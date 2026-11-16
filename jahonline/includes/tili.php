<?php

/**
 * JAHOnline – seurat, palvelut, GPS-paketit ja oikeudet.
 *
 * Seurakohtaiset palvelut (erillään): GPS, kilpailut, iltarastit.
 * Rastilippu-liitäntä on valinnainen lisäominaisuus, 5 € / tapahtuma, laskutus kuukausittain.
 * SSL-tulostiedosto liitolle: 10 € / kisa, jossa latausta käytetään, laskutus kuukausittain.
 * GPS-paketit:
 * - ilmainen: GPS-luonti + seuranta vakioasetuksilla
 * - pro: asetukset, TV/iframe, ghost
 * - analyysi: pro + replay, välianalyysi, GPX
 */

function tiliVarmistaSarake(PDO $pdo, string $table, string $column, string $definition): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");

    return true;
}

function tiliVarmistaTaulut(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }

    tiliVarmistaSarake($pdo, 'users', 'email', 'VARCHAR(255) NULL');
    tiliVarmistaSarake($pdo, 'users', 'nimi', 'VARCHAR(255) NULL');
    tiliVarmistaSarake($pdo, 'users', 'viimeisin_seura_id', 'INT NULL');
    tiliVarmistaSarake($pdo, 'users', 'api_token', 'VARCHAR(64) NULL');
    tiliVarmistaSarake($pdo, 'users', 'viesti_kilpailu', 'TINYINT(1) NOT NULL DEFAULT 0');
    tiliVarmistaSarake($pdo, 'kilpailut', 'seura_id', 'INT NULL');
    tiliVarmistaSarake($pdo, 'kilpailut', 'paakayttaja_id', 'INT NULL');

    // Uniikki indeksi käyttäjäavaimelle (jos puuttuu)
    try {
        $idx = $pdo->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND INDEX_NAME = 'uq_users_api_token'
        ")->fetchColumn();
        if ((int) $idx === 0) {
            $pdo->exec('CREATE UNIQUE INDEX uq_users_api_token ON users (api_token)');
        }
    } catch (Throwable $e) {
        // ohita (vanha MySQL / oikeudet)
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS paketit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            koodi VARCHAR(50) NOT NULL UNIQUE,
            nimi VARCHAR(100) NOT NULL,
            kuvaus VARCHAR(500) NULL,
            max_laitteet INT NOT NULL DEFAULT 10,
            max_kesto_tuntia INT NOT NULL DEFAULT 3,
            max_tapahtumat_kk INT NOT NULL DEFAULT 5,
            ominaisuudet JSON NULL,
            jarjestys INT NOT NULL DEFAULT 0,
            aktiivinen TINYINT(1) NOT NULL DEFAULT 1,
            luotu TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS seurat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nimi VARCHAR(255) NOT NULL,
            slug VARCHAR(120) NOT NULL UNIQUE,
            paketti_id INT NOT NULL,
            tila ENUM('aktiivinen','suljettu') NOT NULL DEFAULT 'aktiivinen',
            luotu TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_seurat_paketti (paketti_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS seura_jasenet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seura_id INT NOT NULL,
            user_id INT NOT NULL,
            rooli ENUM('omistaja','admin','jasen') NOT NULL DEFAULT 'jasen',
            palvelut ENUM('gps','tulokset','molemmat','iltarastit') NOT NULL DEFAULT 'molemmat',
            kisa_valikot VARCHAR(80) NULL DEFAULT NULL,
            iltarasti_oikeus TINYINT(1) NOT NULL DEFAULT 0,
            tila ENUM('aktiivinen','kutsuttu','poistettu') NOT NULL DEFAULT 'aktiivinen',
            luotu TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_seura_user (seura_id, user_id),
            INDEX idx_jasen_user (user_id),
            INDEX idx_jasen_seura (seura_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    tiliSiementaPaketit($pdo);
    tiliVarmistaPalvelutEnum($pdo);
    tiliVarmistaSeuraPalvelut($pdo);
    tiliVarmistaSarake($pdo, 'seura_jasenet', 'kisa_valikot', 'VARCHAR(80) NULL DEFAULT NULL');
    tiliVarmistaSarake($pdo, 'seura_jasenet', 'iltarasti_oikeus', 'TINYINT(1) NOT NULL DEFAULT 0');
    if (is_readable(__DIR__ . '/rastilippu.php')) {
        require_once __DIR__ . '/rastilippu.php';
        if (function_exists('rastilippuVarmista')) {
            rastilippuVarmista($pdo);
        }
    }
    $ok = true;
}

function tiliPalvelutArvot(): array
{
    return ['gps', 'tulokset', 'molemmat', 'iltarastit'];
}

/** Kilpailuvalikoiden avaimet seuran jäsenelle (useita sallittu). */
function tiliKisaValikkoAvaimet(): array
{
    return [
        'kaikki' => 'Kaikki',
        'ajanotto' => 'Ajanotto',
        'lahtopaikka' => 'Lähtöpaikka',
        'ilmoittautuminen' => 'Ilmoittautuminen',
    ];
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function tiliKisaValikotJasenelta(mixed $raw): array
{
    if (is_array($raw)) {
        $osat = $raw;
    } else {
        $osat = preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $ok = array_keys(tiliKisaValikkoAvaimet());
    $out = [];
    foreach ($osat as $k) {
        $k = strtolower(trim((string) $k));
        if (in_array($k, $ok, true) && !in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    if (in_array('kaikki', $out, true)) {
        return ['kaikki'];
    }

    return $out;
}

function tiliKisaValikotMerkkijonoksi(mixed $raw): string
{
    return implode(',', tiliKisaValikotJasenelta($raw));
}

function tiliTallennaSessioKisaValikot(mixed $raw): void
{
    $_SESSION['seura_kisa_valikot'] = tiliKisaValikotMerkkijonoksi($raw);
}

/** @return list<string> */
function tiliKayttajaKisaValikot(): array
{
    return tiliKisaValikotJasenelta($_SESSION['seura_kisa_valikot'] ?? '');
}

function tiliKisaValikotTaysi(): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if (function_exists('onPaakayttaja') && onPaakayttaja()) {
        return true;
    }
    if (function_exists('onSeuraAdmin') && onSeuraAdmin()) {
        return true;
    }

    return in_array('kaikki', tiliKayttajaKisaValikot(), true);
}

function tiliNaytaKisaValikko(string $osio): bool
{
    if (tiliKisaValikotTaysi()) {
        return true;
    }
    $omat = tiliKayttajaKisaValikot();
    if ($osio === 'kaikki') {
        return in_array('kaikki', $omat, true);
    }

    return in_array($osio, $omat, true);
}

function tiliKisaValikotKenttaHtml(mixed $valitut, bool $disabled = false): void
{
    $valitut = tiliKisaValikotJasenelta($valitut);
    $vihjeet = [
        'kaikki' => 'Kisan kaikki valikot',
        'ajanotto' => 'Mobiiliajanotto, Maalinappi, Numerot, Ajanotto-loki',
        'lahtopaikka' => 'Lähtökello, Nimenhuuto, emit-sisäänluku suunnistuksessa',
        'ilmoittautuminen' => 'Numeroiden jako, Ilmo paikan päällä',
    ];
    echo '<fieldset class="kisa-valikot">';
    echo '<legend>Kilpailuvalikot</legend>';
    echo '<p class="muted" style="margin:0 0 0.35rem">Useita valintoja samaan tunnukseen.</p>';
    foreach (tiliKisaValikkoAvaimet() as $avain => $label) {
        $chk = in_array($avain, $valitut, true) ? ' checked' : '';
        $dis = $disabled ? ' disabled' : '';
        $title = htmlspecialchars($vihjeet[$avain] ?? '', ENT_QUOTES, 'UTF-8');
        echo '<label class="kisa-valikot-vaihtoehto" title="' . $title . '">';
        echo '<input type="checkbox" name="kisa_valikot[]" value="' . htmlspecialchars($avain, ENT_QUOTES, 'UTF-8') . '"'
            . $chk . $dis . '> ';
        echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        echo ' <span class="muted">(' . htmlspecialchars($vihjeet[$avain] ?? '', ENT_QUOTES, 'UTF-8') . ')</span>';
        echo '</label>';
    }
    echo '</fieldset>';
}

function tiliIltarastiOikeusKenttaHtml(bool $valittu, bool $disabled = false): void
{
    $chk = $valittu ? ' checked' : '';
    $dis = $disabled ? ' disabled' : '';
    echo '<label class="iltarasti-oikeus-kentta">';
    echo '<input type="checkbox" name="iltarasti_oikeus" value="1"' . $chk . $dis . '> ';
    echo 'Iltarasti-oikeus';
    echo '</label>';
}

function tiliJasenellaIltarastiOikeus(array $jasen): bool
{
    if (!empty($jasen['iltarasti_oikeus']) || !empty($jasen['jasen_iltarasti_oikeus'])) {
        return true;
    }
    $p = (string) ($jasen['palvelut'] ?? $jasen['jasen_palvelut'] ?? '');

    return $p === 'iltarastit';
}

/** Jäsenen palvelut-ENUM seuran palveluista (ei alasvetoa). */
function tiliJasenPalvelutSeurasta(array $seura): string
{
    $gps = tiliSeuraPalveluPaalla($seura, 'gps');
    $kisat = tiliSeuraPalveluPaalla($seura, 'kilpailut');
    if ($gps && $kisat) {
        return 'molemmat';
    }
    if ($gps) {
        return 'gps';
    }
    if ($kisat) {
        return 'tulokset';
    }

    return 'iltarastit';
}

function tiliSeuraNimiTasmaa(string $seuraNimi, string $kirjoitettu): bool
{
    $a = preg_replace('/\s+/u', ' ', trim($seuraNimi)) ?? '';
    $b = preg_replace('/\s+/u', ' ', trim($kirjoitettu)) ?? '';
    if ($a === '' || $b === '') {
        return false;
    }

    return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
}

/** Seurakohtaiset palvelut (erillään GPS-paketin rajoista). */
function tiliSeuraPalveluAvaimet(): array
{
    return ['gps', 'kilpailut', 'iltarastit'];
}

function tiliSeuraPalveluSarake(string $palvelu): string
{
    if ($palvelu === 'gps') {
        return 'palvelu_gps';
    }
    if ($palvelu === 'kilpailut' || $palvelu === 'tulokset' || $palvelu === 'tulospalvelu') {
        return 'palvelu_kilpailut';
    }
    if ($palvelu === 'iltarastit') {
        return 'palvelu_iltarastit';
    }

    return '';
}

function tiliSeuraPalveluOtsikko(string $palvelu): string
{
    if ($palvelu === 'gps') {
        return 'GPS';
    }
    if ($palvelu === 'kilpailut' || $palvelu === 'tulokset' || $palvelu === 'tulospalvelu') {
        return 'Kilpailut';
    }
    if ($palvelu === 'iltarastit') {
        return 'Iltarastit';
    }

    return $palvelu;
}

/** Rastilippu-liitäntä: 5 € / yhdistetty tapahtuma, laskutus kuukausittain. */
function tiliRastilippuHintaEuro(): float
{
    return 5.0;
}

/** SSL-tulostiedosto liitolle: 10 € / kisa, jossa latausta käytetään. */
function tiliSslHintaEuro(): float
{
    return 10.0;
}

function tiliLaskutusHetki(?string $nyt = null): DateTimeImmutable
{
    $tz = new DateTimeZone('Europe/Helsinki');
    try {
        return $nyt !== null && $nyt !== ''
            ? new DateTimeImmutable($nyt, $tz)
            : new DateTimeImmutable('now', $tz);
    } catch (Throwable $e) {
        return new DateTimeImmutable('now', $tz);
    }
}

function tiliLaskutusVuosi(?string $nyt = null): int
{
    return (int) tiliLaskutusHetki($nyt)->format('Y');
}

/** Laskutusjakso muodossa YYYY-MM. */
function tiliLaskutusKuukausi(?string $nyt = null): string
{
    return tiliLaskutusHetki($nyt)->format('Y-m');
}

function tiliLaskutusKuukausiTeksti(string $ym): string
{
    $nimet = [
        1 => 'tammikuu', 2 => 'helmikuu', 3 => 'maaliskuu', 4 => 'huhtikuu',
        5 => 'toukokuu', 6 => 'kesäkuu', 7 => 'heinäkuu', 8 => 'elokuu',
        9 => 'syyskuu', 10 => 'lokakuu', 11 => 'marraskuu', 12 => 'joulukuu',
    ];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        $ym = tiliLaskutusKuukausi();
        preg_match('/^(\d{4})-(\d{2})$/', $ym, $m);
    }
    $kk = (int) ($m[2] ?? 0);
    $vuosi = (string) ($m[1] ?? '');

    return ($nimet[$kk] ?? $ym) . ' ' . $vuosi;
}

function tiliLaskutusKuukausiSiirra(string $ym, int $delta): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym)) {
        $ym = tiliLaskutusKuukausi();
    }
    try {
        $d = new DateTimeImmutable($ym . '-01', new DateTimeZone('Europe/Helsinki'));
        $d = $d->modify(($delta >= 0 ? '+' : '') . $delta . ' months');

        return $d->format('Y-m');
    } catch (Throwable $e) {
        return tiliLaskutusKuukausi();
    }
}

function tiliLaskutusKuukausiNormalisoi($jakso): string
{
    if (is_string($jakso) && preg_match('/^\d{4}-\d{2}$/', $jakso)) {
        return $jakso;
    }
    if (is_int($jakso) && $jakso >= 2020 && $jakso <= 2100) {
        return sprintf('%04d-01', $jakso);
    }

    return tiliLaskutusKuukausi();
}

function tiliEuroTeksti(float $euro): string
{
    $s = number_format($euro, 2, ',', ' ');

    return $s . ' €';
}

function tiliVarmistaSeuraPalvelut(PDO $pdo): void
{
    tiliVarmistaSarake($pdo, 'seurat', 'palvelu_gps', 'TINYINT(1) NOT NULL DEFAULT 1');
    tiliVarmistaSarake($pdo, 'seurat', 'palvelu_kilpailut', 'TINYINT(1) NOT NULL DEFAULT 1');
    $uusiIlta = tiliVarmistaSarake($pdo, 'seurat', 'palvelu_iltarastit', 'TINYINT(1) NOT NULL DEFAULT 0');
    if ($uusiIlta) {
        try {
            $pdo->exec("
                UPDATE seurat s
                SET palvelu_iltarastit = 1
                WHERE (s.rastilippu_token IS NOT NULL AND s.rastilippu_token <> '')
                   OR EXISTS (
                        SELECT 1 FROM kilpailut k
                        WHERE k.seura_id = s.id AND k.luonne = 'iltarasti'
                   )
            ");
        } catch (Throwable $e) {
        }
    }
    $uusiLiit = tiliVarmistaSarake($pdo, 'seurat', 'rastilippu_liitanta', 'TINYINT(1) NOT NULL DEFAULT 0');
    tiliVarmistaSarake($pdo, 'seurat', 'viesti_kilpailu', 'TINYINT(1) NOT NULL DEFAULT 0');
    tiliVarmistaSarake($pdo, 'seurat', 'laskutus_email', 'VARCHAR(255) NULL');
    tiliVarmistaSarake($pdo, 'seurat', 'ei_laskutusta', 'TINYINT(1) NOT NULL DEFAULT 0');
    tiliVarmistaSarake($pdo, 'kilpailut', 'ssl_lataus_at', 'DATETIME NULL');
    tiliVarmistaSarake($pdo, 'seurat', 'logo', 'VARCHAR(255) NULL');
    if (is_readable(__DIR__ . '/seura_logo.php')) {
        require_once __DIR__ . '/seura_logo.php';
        if (function_exists('seuraLogoVarmista')) {
            seuraLogoVarmista($pdo);
        }
    }
    if ($uusiLiit) {
        try {
            $pdo->exec("
                UPDATE seurat
                SET rastilippu_liitanta = 1
                WHERE rastilippu_token IS NOT NULL AND rastilippu_token <> ''
            ");
        } catch (Throwable $e) {
        }
    }
}

function tiliSeuraPalveluPaalla(array $seura, string $palvelu): bool
{
    $col = tiliSeuraPalveluSarake($palvelu);
    if ($col === '') {
        return true;
    }
    if (!array_key_exists($col, $seura)) {
        return $palvelu !== 'iltarastit';
    }

    return (int) $seura[$col] === 1;
}

function tiliSessioSeuraPalvelu(string $palvelu): bool
{
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }
    $col = tiliSeuraPalveluSarake($palvelu);
    if ($col === '') {
        return true;
    }
    $avain = 'seura_' . $col;
    if (!array_key_exists($avain, $_SESSION)) {
        return $palvelu !== 'iltarastit';
    }

    return !empty($_SESSION[$avain]);
}

function tiliTallennaSeuraPalvelut(PDO $pdo, int $seuraId, array $palvelut): void
{
    tiliVarmistaTaulut($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $gps = !empty($palvelut['gps']) ? 1 : 0;
    $kisat = !empty($palvelut['kilpailut']) ? 1 : 0;
    $illat = !empty($palvelut['iltarastit']) ? 1 : 0;
    $pdo->prepare('
        UPDATE seurat
        SET palvelu_gps = ?, palvelu_kilpailut = ?, palvelu_iltarastit = ?
        WHERE id = ?
    ')->execute([$gps, $kisat, $illat, $seuraId]);
}

function tiliSeuraRastilippuPaalla(array $seura): bool
{
    if (!tiliSeuraPalveluPaalla($seura, 'iltarastit')) {
        return false;
    }
    if (!array_key_exists('rastilippu_liitanta', $seura)) {
        return false;
    }

    return (int) $seura['rastilippu_liitanta'] === 1;
}

function tiliSeuraViestiPaalla(array $seura): bool
{
    if (!tiliSeuraPalveluPaalla($seura, 'iltarastit')) {
        return false;
    }
    if (!array_key_exists('viesti_kilpailu', $seura)) {
        return false;
    }

    return (int) $seura['viesti_kilpailu'] === 1;
}

function tiliTallennaSeuraViesti(PDO $pdo, int $seuraId, bool $paalla): void
{
    tiliVarmistaTaulut($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    if ($paalla && !tiliSeuraSallii($pdo, $seuraId, 'iltarastit')) {
        throw new RuntimeException('Viestikilpailu edellyttää Iltarastit-palvelua.');
    }
    $pdo->prepare('UPDATE seurat SET viesti_kilpailu = ? WHERE id = ?')
        ->execute([$paalla ? 1 : 0, $seuraId]);
}

function tiliSeuraSalliiRastilipun(PDO $pdo, int $seuraId): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if ($seuraId < 1) {
        return false;
    }
    tiliVarmistaTaulut($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM seurat WHERE id = ? LIMIT 1');
        $st->execute([$seuraId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && tiliSeuraRastilippuPaalla($row);
    } catch (Throwable $e) {
        return false;
    }
}

function tiliSeuraEiLaskutusta(array $seura): bool
{
    return (int) ($seura['ei_laskutusta'] ?? 0) === 1;
}

function tiliLaskutuspostiOk(?string $email, bool $eiLaskutusta = false): bool
{
    if ($eiLaskutusta) {
        return true;
    }
    $email = trim((string) $email);

    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function tiliSeuraLaskutuspostiOk(?array $seura): bool
{
    if (!is_array($seura)) {
        return false;
    }

    return tiliLaskutuspostiOk(
        (string) ($seura['laskutus_email'] ?? ''),
        tiliSeuraEiLaskutusta($seura)
    );
}

function tiliKilpailuLaskutuspostiOk(PDO $pdo, array $kilpailu): bool
{
    $sid = (int) ($kilpailu['seura_id'] ?? 0);
    if ($sid < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT laskutus_email, ei_laskutusta FROM seurat WHERE id = ? LIMIT 1');
        $st->execute([$sid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($r) && tiliSeuraLaskutuspostiOk($r);
    } catch (Throwable $e) {
        return false;
    }
}

function tiliLaskutuspostiPuuttuuTeksti(): string
{
    return 'Laskutussähköposti puuttuu';
}

function tiliLaskutuspostiTyylit(): string
{
    return '<style>'
        . '.laskutusposti-esto,.laskutusposti-esto:hover{opacity:.45;filter:grayscale(.55);pointer-events:none;cursor:not-allowed}'
        . '.laskutusposti-huom{color:#8a5a00;margin:.4rem 0 .2rem}'
        . 'label.laskutusposti-esto{display:inline-flex;align-items:center;gap:.4rem}'
        . '</style>';
}

function tiliLaskutuspostiHuomioHtml(string $ohje = ''): void
{
    static $tyyli = false;
    if (!$tyyli) {
        echo tiliLaskutuspostiTyylit();
        $tyyli = true;
    }
    $txt = tiliLaskutuspostiPuuttuuTeksti();
    if ($ohje !== '') {
        $txt .= '. ' . $ohje;
    } else {
        $txt .= '.';
    }
    echo '<p class="laskutusposti-huom">' . htmlspecialchars($txt) . '</p>';
}

function tiliLaskutuspostiEstettyNappi(string $label, string $luokka = 'btn'): void
{
    static $tyyli = false;
    if (!$tyyli) {
        echo tiliLaskutuspostiTyylit();
        $tyyli = true;
    }
    echo '<span class="' . htmlspecialchars($luokka) . ' laskutusposti-esto" aria-disabled="true" title="'
        . htmlspecialchars(tiliLaskutuspostiPuuttuuTeksti()) . '">'
        . htmlspecialchars($label) . '</span>';
}

function tiliLaskutuspostiLiitantaJs(): void
{
    echo <<<'HTML'
<script>
(function () {
    var email = document.querySelector('[name="laskutus_email"]');
    var cb = document.querySelector('[name="rastilippu_liitanta"]');
    var ei = document.querySelector('[name="ei_laskutusta"]');
    if (!cb) return;
    var lab = cb.closest('label');
    function ok() {
        if (ei && ei.checked) return true;
        var v = ((email && email.value) || '').trim();
        return v.indexOf('@') > 0 && v.indexOf('.') > v.indexOf('@');
    }
    function sync() {
        var on = ok();
        cb.disabled = !on;
        if (lab) lab.classList.toggle('laskutusposti-esto', !on);
        var huom = document.getElementById('laskutusposti-huom-liitanta');
        if (huom) huom.hidden = on;
    }
    if (email) email.addEventListener('input', sync);
    if (ei) ei.addEventListener('change', sync);
    sync();
})();
</script>
HTML;
}

function tiliTallennaSeuraEiLaskutusta(PDO $pdo, int $seuraId, bool $eiLaskutusta): void
{
    tiliVarmistaTaulut($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $pdo->prepare('UPDATE seurat SET ei_laskutusta = ? WHERE id = ?')
        ->execute([$eiLaskutusta ? 1 : 0, $seuraId]);
}

function tiliValidoiLaskutusposti(?string $email): string
{
    $email = trim((string) $email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Anna kelvollinen laskutussähköposti Rastilippu-liitännälle.');
    }

    return mb_substr($email, 0, 255);
}

function tiliTallennaRastilippuLiitanta(PDO $pdo, int $seuraId, bool $paalla, ?string $laskutusEmail, bool $vaadiPosti = true): void
{
    tiliVarmistaTaulut($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $email = trim((string) $laskutusEmail);
    $eiLaskua = false;
    try {
        $st = $pdo->prepare('SELECT ei_laskutusta FROM seurat WHERE id = ? LIMIT 1');
        $st->execute([$seuraId]);
        $eiLaskua = (int) $st->fetchColumn() === 1;
    } catch (Throwable $e) {
    }
    if ($paalla && !$eiLaskua) {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(tiliLaskutuspostiPuuttuuTeksti() . '. Lisää se ennen Rastilippu-liitännän käyttöönottoa.');
        }
        $email = tiliValidoiLaskutusposti($email);
    } elseif ($email !== '') {
        $email = tiliValidoiLaskutusposti($email);
    } else {
        $email = '';
    }
    $pdo->prepare('
        UPDATE seurat
        SET rastilippu_liitanta = ?, laskutus_email = ?
        WHERE id = ?
    ')->execute([$paalla ? 1 : 0, $email !== '' ? $email : null, $seuraId]);
}

function tiliRastilippuTapahtumaLkm(PDO $pdo, int $seuraId, $jakso): int
{
    if ($seuraId < 1) {
        return 0;
    }
    $kuukausi = tiliLaskutusKuukausiNormalisoi($jakso);
    $ehdot = [
        'AND DATE_FORMAT(paiva, \'%Y-%m\') = ?',
        'AND DATE_FORMAT(COALESCE(paiva, luotu), \'%Y-%m\') = ?',
        'AND DATE_FORMAT(COALESCE(paiva, NOW()), \'%Y-%m\') = ?',
    ];
    foreach ($ehdot as $ehto) {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM kilpailut
                WHERE seura_id = ?
                  AND luonne = 'iltarasti'
                  AND rastilippu_event_id IS NOT NULL
                  AND rastilippu_event_id > 0
                  {$ehto}
            ");
            $st->execute([$seuraId, $kuukausi]);

            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
        }
    }

    return 0;
}

function tiliSslTapahtumaLkm(PDO $pdo, int $seuraId, $jakso): int
{
    $kuukausi = tiliLaskutusKuukausiNormalisoi($jakso);
    try {
        if ($seuraId > 0) {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM kilpailut
                WHERE seura_id = ?
                  AND ssl_lataus_at IS NOT NULL
                  AND DATE_FORMAT(ssl_lataus_at, '%Y-%m') = ?
                  AND COALESCE(tyyppi, 'henkilokohtainen') <> 'viesti'
            ");
            $st->execute([$seuraId, $kuukausi]);
        } else {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM kilpailut
                WHERE (seura_id IS NULL OR seura_id = 0)
                  AND ssl_lataus_at IS NOT NULL
                  AND DATE_FORMAT(ssl_lataus_at, '%Y-%m') = ?
                  AND COALESCE(tyyppi, 'henkilokohtainen') <> 'viesti'
            ");
            $st->execute([$kuukausi]);
        }

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function tiliVaadiRastilippuLiitanta(PDO $pdo, int $seuraId): void
{
    if (!tiliSeuraSalliiRastilipun($pdo, $seuraId)) {
        throw new RuntimeException(
            'IRMA/Rastilippu-liitäntä ei ole käytössä. Ota se käyttöön oman seuran tiedoissa ('
            . tiliEuroTeksti(tiliRastilippuHintaEuro())
            . '/tapahtuma, laskutus kuukausittain).'
        );
    }
}

/**
 * @return array{kuukausi:string,vuosi:int,yhdistettyja:int,hinta_euro:float,summa_euro:float,laskutus_email:string,liitanta:bool,ei_laskutusta:bool,teksti:string}
 */
function tiliRastilippuLaskutus(PDO $pdo, int $seuraId, $jakso = null): array
{
    $hinta = tiliRastilippuHintaEuro();
    $kuukausi = tiliLaskutusKuukausiNormalisoi($jakso ?? tiliLaskutusKuukausi());
    $n = 0;
    $email = '';
    $liitanta = false;
    $eiLaskutusta = false;
    if ($seuraId > 0) {
        try {
            $st = $pdo->prepare('SELECT rastilippu_liitanta, laskutus_email, ei_laskutusta FROM seurat WHERE id = ? LIMIT 1');
            $st->execute([$seuraId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $liitanta = (int) ($r['rastilippu_liitanta'] ?? 0) === 1;
            $email = trim((string) ($r['laskutus_email'] ?? ''));
            $eiLaskutusta = (int) ($r['ei_laskutusta'] ?? 0) === 1;
        } catch (Throwable $e) {
        }
        $n = tiliRastilippuTapahtumaLkm($pdo, $seuraId, $kuukausi);
    }
    $summa = $eiLaskutusta ? 0.0 : $n * $hinta;
    $jaksoTxt = tiliLaskutusKuukausiTeksti($kuukausi);
    $teksti = $eiLaskutusta
        ? ('IRMA/Rastilippu, ei laskutusta · ' . $jaksoTxt . ': ' . $n . ' tapahtumaa · ' . tiliEuroTeksti(0.0))
        : ('IRMA/Rastilippu ' . tiliEuroTeksti($hinta) . '/tapahtuma, laskutus kuukausittain'
            . ' · ' . $jaksoTxt . ': ' . $n . ' tapahtumaa · ' . tiliEuroTeksti($summa));

    return [
        'kuukausi' => $kuukausi,
        'vuosi' => (int) substr($kuukausi, 0, 4),
        'yhdistettyja' => $n,
        'hinta_euro' => $hinta,
        'summa_euro' => $summa,
        'laskutus_email' => $email,
        'liitanta' => $liitanta,
        'ei_laskutusta' => $eiLaskutusta,
        'teksti' => $teksti,
    ];
}

/**
 * @return array{kuukausi:string,lkm:int,hinta_euro:float,summa_euro:float,laskutus_email:string,ei_laskutusta:bool,teksti:string}
 */
function tiliSslLaskutus(PDO $pdo, int $seuraId, $jakso = null): array
{
    $hinta = tiliSslHintaEuro();
    $kuukausi = tiliLaskutusKuukausiNormalisoi($jakso ?? tiliLaskutusKuukausi());
    $email = '';
    $eiLaskutusta = false;
    if ($seuraId > 0) {
        try {
            $st = $pdo->prepare('SELECT laskutus_email, ei_laskutusta FROM seurat WHERE id = ? LIMIT 1');
            $st->execute([$seuraId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $email = trim((string) ($r['laskutus_email'] ?? ''));
            $eiLaskutusta = (int) ($r['ei_laskutusta'] ?? 0) === 1;
        } catch (Throwable $e) {
        }
    }
    $n = tiliSslTapahtumaLkm($pdo, $seuraId, $kuukausi);
    $summa = $eiLaskutusta ? 0.0 : $n * $hinta;
    $jaksoTxt = tiliLaskutusKuukausiTeksti($kuukausi);
    $teksti = $eiLaskutusta
        ? ('SSL-tulokset, ei laskutusta · ' . $jaksoTxt . ': ' . $n . ' kisaa · ' . tiliEuroTeksti(0.0))
        : ('SSL-tulokset ' . tiliEuroTeksti($hinta) . '/kisa, laskutus kuukausittain'
            . ' · ' . $jaksoTxt . ': ' . $n . ' kisaa · ' . tiliEuroTeksti($summa));

    return [
        'kuukausi' => $kuukausi,
        'lkm' => $n,
        'hinta_euro' => $hinta,
        'summa_euro' => $summa,
        'laskutus_email' => $email,
        'ei_laskutusta' => $eiLaskutusta,
        'teksti' => $teksti,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function tiliRastilippuLaskutusKaikki(PDO $pdo, $jakso = null): array
{
    $kuukausi = tiliLaskutusKuukausiNormalisoi($jakso ?? tiliLaskutusKuukausi());
    $ulos = [];
    foreach (tiliKuukausiLaskutusKaikki($pdo, $kuukausi) as $rivi) {
        if ((int) ($rivi['rastilippu_lkm'] ?? 0) < 1 && empty($rivi['liitanta'])) {
            continue;
        }
        $ulos[] = [
            'seura_id' => (int) ($rivi['seura_id'] ?? 0),
            'nimi' => (string) ($rivi['nimi'] ?? ''),
            'laskutus_email' => (string) ($rivi['laskutus_email'] ?? ''),
            'liitanta' => !empty($rivi['liitanta']),
            'vuosi' => (int) substr($kuukausi, 0, 4),
            'kuukausi' => $kuukausi,
            'yhdistettyja' => (int) ($rivi['rastilippu_lkm'] ?? 0),
            'summa_euro' => (float) ($rivi['rastilippu_summa'] ?? 0),
            'hinta_euro' => tiliRastilippuHintaEuro(),
        ];
    }

    return $ulos;
}

/**
 * @return list<array<string,mixed>>
 */
function tiliKuukausiLaskutusKaikki(PDO $pdo, $jakso = null): array
{
    tiliVarmistaTaulut($pdo);
    $kuukausi = tiliLaskutusKuukausiNormalisoi($jakso ?? tiliLaskutusKuukausi());
    $ulos = [];
    try {
        $st = $pdo->query('
            SELECT id, nimi, rastilippu_liitanta, laskutus_email, ei_laskutusta
            FROM seurat
            WHERE tila = \'aktiivinen\'
            ORDER BY nimi, id
        ');
        $seurat = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $seurat = [];
    }
    foreach ($seurat as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        if ((int) ($s['ei_laskutusta'] ?? 0) === 1) {
            continue;
        }
        $rl = tiliRastilippuLaskutus($pdo, $sid, $kuukausi);
        $ssl = tiliSslLaskutus($pdo, $sid, $kuukausi);
        $liitanta = (int) ($s['rastilippu_liitanta'] ?? 0) === 1;
        if (!$liitanta && $rl['yhdistettyja'] < 1 && $ssl['lkm'] < 1) {
            continue;
        }
        $ulos[] = [
            'seura_id' => $sid,
            'nimi' => (string) ($s['nimi'] ?? ''),
            'laskutus_email' => trim((string) ($s['laskutus_email'] ?? '')),
            'liitanta' => $liitanta,
            'kuukausi' => $kuukausi,
            'rastilippu_lkm' => $rl['yhdistettyja'],
            'rastilippu_summa' => $rl['summa_euro'],
            'ssl_lkm' => $ssl['lkm'],
            'ssl_summa' => $ssl['summa_euro'],
            'summa_euro' => (float) $rl['summa_euro'] + (float) $ssl['summa_euro'],
        ];
    }
    $ilman = tiliSslLaskutus($pdo, 0, $kuukausi);
    if ($ilman['lkm'] > 0) {
        $ulos[] = [
            'seura_id' => 0,
            'nimi' => 'Ilman seuraa',
            'laskutus_email' => '',
            'liitanta' => false,
            'kuukausi' => $kuukausi,
            'rastilippu_lkm' => 0,
            'rastilippu_summa' => 0.0,
            'ssl_lkm' => $ilman['lkm'],
            'ssl_summa' => $ilman['summa_euro'],
            'summa_euro' => (float) $ilman['summa_euro'],
        ];
    }

    return $ulos;
}

function tiliSeuraVainIltarastit(array $seura): bool
{
    return tiliSeuraPalveluPaalla($seura, 'iltarastit')
        && !tiliSeuraPalveluPaalla($seura, 'gps')
        && !tiliSeuraPalveluPaalla($seura, 'kilpailut');
}

/** @return list<string> */
function tiliSeuraSallitutJasenPalvelut(array $seura): array
{
    $ulos = [];
    $gps = tiliSeuraPalveluPaalla($seura, 'gps');
    $kisat = tiliSeuraPalveluPaalla($seura, 'kilpailut');
    $illat = tiliSeuraPalveluPaalla($seura, 'iltarastit');
    if ($gps && $kisat) {
        $ulos[] = 'molemmat';
    }
    if ($gps) {
        $ulos[] = 'gps';
    }
    if ($kisat) {
        $ulos[] = 'tulokset';
    }
    if ($illat) {
        $ulos[] = 'iltarastit';
    }

    return $ulos;
}

function tiliKirjautumisOsoite(string $rooli): string
{
    if ($rooli !== 'admin' && function_exists('tiliSessioSeuraPalvelu')) {
        $gps = tiliSessioSeuraPalvelu('gps');
        $kisat = tiliSessioSeuraPalvelu('kilpailut');
        $illat = tiliSessioSeuraPalvelu('iltarastit');
        if ($illat && !$gps && !$kisat) {
            return '/public/omat_tapahtumat.php';
        }
    }

    return match ($rooli) {
        'admin', 'paakayttaja' => '../admin/index.php',
        'ajanottaja' => '../public/ajanotto_valinta.php',
        'lahtopaikka', 'lahtotoiminnat' => '../public/lahto_valinta.php',
        'ampumapaikat' => '../public/ampuma_valinta.php',
        'tv' => '../public/tv_valinta.php',
        'iltarasti' => '/public/omat_tapahtumat.php',
        default => '../admin/index.php',
    };
}

function tiliSeuraSallii(PDO $pdo, int $seuraId, string $palvelu): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if ($seuraId < 1) {
        return false;
    }
    tiliVarmistaTaulut($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM seurat WHERE id = ? LIMIT 1');
        $st->execute([$seuraId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? tiliSeuraPalveluPaalla($row, $palvelu) : false;
    } catch (Throwable $e) {
        return false;
    }
}

function tiliSeuranKilpailuLkm(PDO $pdo, int $seuraId): int
{
    if ($seuraId < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM kilpailut
            WHERE seura_id = ?
              AND (luonne IS NULL OR luonne <> 'iltarasti')
        ");
        $st->execute([$seuraId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function tiliVarmistaPalvelutEnum(PDO $pdo): void
{
    $haluttu = tiliPalvelutArvot();
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `seura_jasenet` LIKE 'palvelut'");
        $col = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        $type = (string) ($col['Type'] ?? '');
        $puuttuu = false;
        foreach ($haluttu as $r) {
            if (stripos($type, "'" . $r . "'") === false) {
                $puuttuu = true;
                break;
            }
        }
        if ($puuttuu) {
            $enum = implode(',', array_map(static fn ($r) => "'" . $r . "'", $haluttu));
            $pdo->exec("ALTER TABLE `seura_jasenet` MODIFY COLUMN `palvelut` ENUM($enum) NOT NULL DEFAULT 'molemmat'");
        }
    } catch (Throwable $e) {
    }
}

function tiliPakettiOletukset(): array
{
    return [
        'ilmainen' => [
            'nimi' => 'Ilmainen',
            'kuvaus' => 'Luo GPS-tapahtumia ja seuraa liveä vakioasetuksilla. Tulospalvelu käytössä.',
            'max_laitteet' => 10,
            'max_kesto_tuntia' => 3,
            'max_tapahtumat_kk' => 8,
            'jarjestys' => 10,
            'ominaisuudet' => [
                'gps' => true,
                'gps_asetukset' => false,
                'gps_tv' => true,
                'gps_iframe' => false,
                'gps_ghost' => false,
                'gps_replay' => false,
                'gps_analyysi' => false,
                'gps_gpx' => false,
                'tulospalvelu' => true,
            ],
        ],
        'pro' => [
            'nimi' => 'Pro',
            'kuvaus' => 'Asetukset, TV, iframe, ghost ja laajemmat limiitit.',
            'max_laitteet' => 40,
            'max_kesto_tuntia' => 12,
            'max_tapahtumat_kk' => 40,
            'jarjestys' => 20,
            'ominaisuudet' => [
                'gps' => true,
                'gps_asetukset' => true,
                'gps_tv' => true,
                'gps_iframe' => true,
                'gps_ghost' => true,
                'gps_replay' => false,
                'gps_analyysi' => false,
                'gps_gpx' => false,
                'tulospalvelu' => true,
            ],
        ],
        'analyysi' => [
            'nimi' => 'Analyysi',
            'kuvaus' => 'Pro + replay, välianalyysi ja GPX.',
            'max_laitteet' => 80,
            'max_kesto_tuntia' => 24,
            'max_tapahtumat_kk' => 120,
            'jarjestys' => 30,
            'ominaisuudet' => [
                'gps' => true,
                'gps_asetukset' => true,
                'gps_tv' => true,
                'gps_iframe' => true,
                'gps_ghost' => true,
                'gps_replay' => true,
                'gps_analyysi' => true,
                'gps_gpx' => true,
                'tulospalvelu' => true,
            ],
        ],
    ];
}

function tiliSiementaPaketit(PDO $pdo): void
{
    $defaults = tiliPakettiOletukset();
    foreach ($defaults as $koodi => $p) {
        $chk = $pdo->prepare('SELECT id FROM paketit WHERE koodi = ? LIMIT 1');
        $chk->execute([$koodi]);
        $id = $chk->fetchColumn();
        $json = json_encode($p['ominaisuudet'], JSON_UNESCAPED_UNICODE);
        if ($id) {
            $pdo->prepare('
                UPDATE paketit SET
                    nimi = ?, kuvaus = ?, max_laitteet = ?, max_kesto_tuntia = ?,
                    max_tapahtumat_kk = ?, ominaisuudet = ?, jarjestys = ?, aktiivinen = 1
                WHERE id = ?
            ')->execute([
                $p['nimi'],
                $p['kuvaus'],
                $p['max_laitteet'],
                $p['max_kesto_tuntia'],
                $p['max_tapahtumat_kk'],
                $json,
                $p['jarjestys'],
                $id,
            ]);
        } else {
            $pdo->prepare('
                INSERT INTO paketit (
                    koodi, nimi, kuvaus, max_laitteet, max_kesto_tuntia,
                    max_tapahtumat_kk, ominaisuudet, jarjestys, aktiivinen
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ')->execute([
                $koodi,
                $p['nimi'],
                $p['kuvaus'],
                $p['max_laitteet'],
                $p['max_kesto_tuntia'],
                $p['max_tapahtumat_kk'],
                $json,
                $p['jarjestys'],
            ]);
        }
    }
}

function tiliSlugify(string $nimi): string
{
    $map = [
        'ä' => 'a', 'ö' => 'o', 'å' => 'a',
        'Ä' => 'a', 'Ö' => 'o', 'Å' => 'a',
    ];
    $s = strtr(mb_strtolower(trim($nimi), 'UTF-8'), $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') {
        $s = 'seura';
    }
    return substr($s, 0, 100);
}

function tiliUniikkiSlug(PDO $pdo, string $nimi): string
{
    $base = tiliSlugify($nimi);
    $slug = $base;
    $i = 2;
    while (true) {
        $st = $pdo->prepare('SELECT id FROM seurat WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
        if ($i > 500) {
            return $base . '-' . bin2hex(random_bytes(3));
        }
    }
}

function tiliHaePakettiKoodilla(PDO $pdo, string $koodi): ?array
{
    $st = $pdo->prepare('SELECT * FROM paketit WHERE koodi = ? AND aktiivinen = 1 LIMIT 1');
    $st->execute([$koodi]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tiliPakettiOminaisuudet(?array $paketti): array
{
    $defaults = tiliPakettiOletukset()['ilmainen']['ominaisuudet'];
    if (!$paketti) {
        return $defaults;
    }
    $raw = $paketti['ominaisuudet'] ?? null;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($defaults, $decoded);
        }
    }
    if (is_array($raw)) {
        return array_merge($defaults, $raw);
    }
    $koodi = (string) ($paketti['koodi'] ?? 'ilmainen');
    $seed = tiliPakettiOletukset()[$koodi]['ominaisuudet'] ?? $defaults;
    return $seed;
}

function tiliKirjauduSessioon(array $user, ?array $seura = null, ?array $jasenys = null): void
{
    $_SESSION['user'] = $user['username'];
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'] ?? 'ajanottaja';
    $_SESSION['user_nimi'] = $user['nimi'] ?? $user['username'];
    tiliTallennaSessioViesti($user);
    if ($seura) {
        $_SESSION['seura_id'] = (int) $seura['id'];
        $_SESSION['seura_nimi'] = $seura['nimi'];
        $_SESSION['paketti_koodi'] = $seura['paketti_koodi'] ?? ($seura['koodi'] ?? 'ilmainen');
        tiliTallennaSessioSeuraPalvelut($seura);
    } else {
        unset(
            $_SESSION['seura_id'],
            $_SESSION['seura_nimi'],
            $_SESSION['paketti_koodi'],
            $_SESSION['seura_palvelu_gps'],
            $_SESSION['seura_palvelu_kilpailut'],
            $_SESSION['seura_palvelu_iltarastit'],
            $_SESSION['seura_rastilippu_liitanta'],
            $_SESSION['seura_viesti_kilpailu'],
            $_SESSION['seura_kisa_valikot'],
            $_SESSION['seura_iltarasti_oikeus']
        );
    }
    if ($jasenys) {
        $_SESSION['seura_rooli'] = $jasenys['rooli'] ?? 'jasen';
        $_SESSION['seura_palvelut'] = $jasenys['palvelut'] ?? 'molemmat';
        tiliTallennaSessioKisaValikot($jasenys['kisa_valikot'] ?? ($jasenys['jasen_kisa_valikot'] ?? ''));
        $_SESSION['seura_iltarasti_oikeus'] = tiliJasenellaIltarastiOikeus($jasenys) ? 1 : 0;
    } else {
        unset($_SESSION['seura_rooli'], $_SESSION['seura_palvelut'], $_SESSION['seura_kisa_valikot'], $_SESSION['seura_iltarasti_oikeus']);
    }
}

function tiliHaeKayttajaIdlla(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tiliHaeKayttajaTunnuksella(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Onko käyttäjä seuran omistaja tai admin (nykyinen seura / sessio)?
 */
function onSeuraAdmin(): bool
{
    $rooli = (string) ($_SESSION['seura_rooli'] ?? '');
    return in_array($rooli, ['omistaja', 'admin'], true);
}

/**
 * Seurat, joissa käyttäjä on omistaja tai admin.
 *
 * @return list<int>
 */
function haeSeuraAdminSeuraIdt(?PDO $pdo = null): array
{
    static $cache = null;
    static $cacheUser = null;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return [];
    }

    if ($cache !== null && $cacheUser === $userId) {
        return $cache;
    }

    $db = $pdo;
    if (!$db instanceof PDO && !empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $db = $GLOBALS['pdo'];
    }
    if (!$db instanceof PDO) {
        // Fallback: vain aktiivinen sessio-seura
        $sid = (int) ($_SESSION['seura_id'] ?? 0);
        $cacheUser = $userId;
        $cache = ($sid > 0 && onSeuraAdmin()) ? [$sid] : [];
        return $cache;
    }

    tiliVarmistaTaulut($db);
    $st = $db->prepare("
        SELECT j.seura_id
        FROM seura_jasenet j
        INNER JOIN seurat s ON s.id = j.seura_id AND s.tila = 'aktiivinen'
        WHERE j.user_id = ?
          AND j.tila = 'aktiivinen'
          AND j.rooli IN ('omistaja', 'admin')
        ORDER BY j.seura_id
    ");
    $st->execute([$userId]);
    $ids = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['seura_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $cacheUser = $userId;
    $cache = $ids;
    return $cache;
}

/** Voiko hallita kilpailuja seuran admin-oikeudella? */
function onSeuraAdminSeuralle(int $seuraId, ?PDO $pdo = null): bool
{
    if ($seuraId < 1) {
        return false;
    }
    return in_array($seuraId, haeSeuraAdminSeuraIdt($pdo), true);
}

/**
 * Seurat, joissa jäsenellä on kilpailuvalikoita.
 *
 * @return list<int>
 */
function haeSeuraKisaValikkoSeuraIdt(?PDO $pdo = null): array
{
    static $cache = null;
    static $cacheKey = null;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return [];
    }
    $key = $userId . ':' . (string) ($_SESSION['seura_kisa_valikot'] ?? '');

    if ($cache !== null && $cacheKey === $key) {
        return $cache;
    }

    $db = $pdo;
    if (!$db instanceof PDO && !empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $db = $GLOBALS['pdo'];
    }
    if (!$db instanceof PDO) {
        $sid = (int) ($_SESSION['seura_id'] ?? 0);
        $cacheKey = $key;
        $cache = ($sid > 0 && tiliKayttajaKisaValikot() !== []) ? [$sid] : [];
        return $cache;
    }

    tiliVarmistaTaulut($db);
    $st = $db->prepare("
        SELECT j.seura_id
        FROM seura_jasenet j
        INNER JOIN seurat s ON s.id = j.seura_id AND s.tila = 'aktiivinen'
        WHERE j.user_id = ?
          AND j.tila = 'aktiivinen'
          AND j.kisa_valikot IS NOT NULL
          AND TRIM(j.kisa_valikot) <> ''
        ORDER BY j.seura_id
    ");
    $st->execute([$userId]);
    $ids = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['seura_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $cacheKey = $key;
    $cache = $ids;
    return $cache;
}

function tiliHaeSeuraKayttajalle(PDO $pdo, int $userId): ?array
{
    tiliVarmistaTaulut($pdo);
    $user = tiliHaeKayttajaIdlla($pdo, $userId);
    if ($user && !empty($user['viimeisin_seura_id'])) {
        $st = $pdo->prepare("
            SELECT s.*, p.koodi AS paketti_koodi, p.nimi AS paketti_nimi,
                   p.max_laitteet, p.max_kesto_tuntia, p.max_tapahtumat_kk, p.ominaisuudet,
                   j.rooli AS jasen_rooli, j.palvelut AS jasen_palvelut,
                   j.kisa_valikot AS jasen_kisa_valikot,
                   j.iltarasti_oikeus AS jasen_iltarasti_oikeus
            FROM seurat s
            INNER JOIN seura_jasenet j ON j.seura_id = s.id AND j.user_id = ? AND j.tila = 'aktiivinen'
            INNER JOIN paketit p ON p.id = s.paketti_id
            WHERE s.id = ? AND s.tila = 'aktiivinen'
            LIMIT 1
        ");
        $st->execute([$userId, (int) $user['viimeisin_seura_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $st = $pdo->prepare("
        SELECT s.*, p.koodi AS paketti_koodi, p.nimi AS paketti_nimi,
               p.max_laitteet, p.max_kesto_tuntia, p.max_tapahtumat_kk, p.ominaisuudet,
               j.rooli AS jasen_rooli, j.palvelut AS jasen_palvelut,
               j.kisa_valikot AS jasen_kisa_valikot,
               j.iltarasti_oikeus AS jasen_iltarasti_oikeus
        FROM seurat s
        INNER JOIN seura_jasenet j ON j.seura_id = s.id AND j.user_id = ? AND j.tila = 'aktiivinen'
        INNER JOIN paketit p ON p.id = s.paketti_id
        WHERE s.tila = 'aktiivinen'
        ORDER BY FIELD(j.rooli, 'omistaja', 'admin', 'jasen'), s.id
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Kaikki seurat, joissa käyttäjä on aktiivinen jäsen.
 *
 * @return list<array<string,mixed>>
 */
function tiliHaeSeuratKayttajalle(PDO $pdo, int $userId): array
{
    if ($userId < 1) {
        return [];
    }
    tiliVarmistaTaulut($pdo);
    $st = $pdo->prepare("
        SELECT s.*, p.koodi AS paketti_koodi, p.nimi AS paketti_nimi,
               p.max_laitteet, p.max_kesto_tuntia, p.max_tapahtumat_kk, p.ominaisuudet,
               j.rooli AS jasen_rooli, j.palvelut AS jasen_palvelut,
               j.kisa_valikot AS jasen_kisa_valikot,
               j.iltarasti_oikeus AS jasen_iltarasti_oikeus
        FROM seurat s
        INNER JOIN seura_jasenet j ON j.seura_id = s.id AND j.user_id = ? AND j.tila = 'aktiivinen'
        INNER JOIN paketit p ON p.id = s.paketti_id
        WHERE s.tila = 'aktiivinen'
        ORDER BY FIELD(j.rooli, 'omistaja', 'admin', 'jasen'), s.nimi, s.id
    ");
    $st->execute([$userId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tiliLataaKayttajaSessioon(PDO $pdo): void
{
    tiliVarmistaTaulut($pdo);
    if (empty($_SESSION['user_id']) && !empty($_SESSION['user'])) {
        $u = tiliHaeKayttajaTunnuksella($pdo, (string) $_SESSION['user']);
        if ($u) {
            $_SESSION['user_id'] = (int) $u['id'];
            $_SESSION['role'] = $u['role'];
        }
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $oma = tiliHaeKayttajaIdlla($pdo, (int) $_SESSION['user_id']);
    if ($oma) {
        tiliTallennaSessioViesti($oma);
        $_SESSION['role'] = $oma['role'] ?? ($_SESSION['role'] ?? 'ajanottaja');
    }
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin' && empty($_SESSION['seura_id'])) {
        // Alustan admin voi toimia ilman seuraa
        return;
    }
    $seura = tiliHaeSeuraKayttajalle($pdo, (int) $_SESSION['user_id']);
    if ($seura) {
        $_SESSION['seura_id'] = (int) $seura['id'];
        $_SESSION['seura_nimi'] = $seura['nimi'];
        $_SESSION['paketti_koodi'] = $seura['paketti_koodi'];
        $_SESSION['seura_rooli'] = $seura['jasen_rooli'];
        $_SESSION['seura_palvelut'] = $seura['jasen_palvelut'];
        tiliTallennaSessioKisaValikot($seura['jasen_kisa_valikot'] ?? '');
        $_SESSION['seura_iltarasti_oikeus'] = tiliJasenellaIltarastiOikeus($seura) ? 1 : 0;
        tiliTallennaSessioSeuraPalvelut($seura);
    } else {
        unset(
            $_SESSION['seura_id'],
            $_SESSION['seura_nimi'],
            $_SESSION['paketti_koodi'],
            $_SESSION['seura_rooli'],
            $_SESSION['seura_palvelut'],
            $_SESSION['seura_kisa_valikot'],
            $_SESSION['seura_iltarasti_oikeus'],
            $_SESSION['seura_palvelu_gps'],
            $_SESSION['seura_palvelu_kilpailut'],
            $_SESSION['seura_palvelu_iltarastit'],
            $_SESSION['seura_rastilippu_liitanta'],
            $_SESSION['seura_viesti_kilpailu']
        );
    }
}

function tiliTallennaSessioSeuraPalvelut(array $seura): void
{
    $_SESSION['seura_palvelu_gps'] = tiliSeuraPalveluPaalla($seura, 'gps') ? 1 : 0;
    $_SESSION['seura_palvelu_kilpailut'] = tiliSeuraPalveluPaalla($seura, 'kilpailut') ? 1 : 0;
    $_SESSION['seura_palvelu_iltarastit'] = tiliSeuraPalveluPaalla($seura, 'iltarastit') ? 1 : 0;
    $_SESSION['seura_rastilippu_liitanta'] = tiliSeuraRastilippuPaalla($seura) ? 1 : 0;
    $_SESSION['seura_viesti_kilpailu'] = tiliSeuraViestiPaalla($seura) ? 1 : 0;
}

function tiliTallennaSessioViesti(array $user): void
{
    $_SESSION['viesti_kilpailu'] = !empty($user['viesti_kilpailu']) ? 1 : 0;
}

/** Viestikilpailu on käyttäjätunnuksen lisäominaisuus. */
function tiliKayttajaViestiPaalla(?array $user = null): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if (!empty($_SESSION['seura_viesti_kilpailu'])) {
        return true;
    }
    if ($user !== null) {
        return (int) ($user['viesti_kilpailu'] ?? 0) === 1;
    }

    return !empty($_SESSION['viesti_kilpailu']);
}

function tiliNykyinenOminaisuudet(PDO $pdo): array
{
    tiliVarmistaTaulut($pdo);
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $all = tiliPakettiOletukset()['analyysi']['ominaisuudet'];
        foreach ($all as $k => $v) {
            $all[$k] = true;
        }
        return $all;
    }
    $seuraId = (int) ($_SESSION['seura_id'] ?? 0);
    if ($seuraId < 1) {
        return tiliPakettiOletukset()['ilmainen']['ominaisuudet'];
    }
    $st = $pdo->prepare("
        SELECT p.*
        FROM seurat s
        INNER JOIN paketit p ON p.id = s.paketti_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $st->execute([$seuraId]);
    $paketti = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return tiliPakettiOminaisuudet($paketti);
}

function tiliVoi(PDO $pdo, string $ominaisuus): bool
{
    if ($ominaisuus === 'gps' && !tiliSessioSeuraPalvelu('gps')) {
        return false;
    }
    if ($ominaisuus === 'tulospalvelu' && !tiliSessioSeuraPalvelu('kilpailut')) {
        return false;
    }
    $o = tiliNykyinenOminaisuudet($pdo);
    return !empty($o[$ominaisuus]);
}

function tiliVoiPalvelun(string $palvelu): bool
{
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }
    if (!tiliSessioSeuraPalvelu($palvelu)) {
        return false;
    }
    if ($palvelu === 'iltarastit') {
        if (!empty($_SESSION['role']) && $_SESSION['role'] === 'iltarasti') {
            return true;
        }
        if (!empty($_SESSION['seura_iltarasti_oikeus'])) {
            return true;
        }
        $p = (string) ($_SESSION['seura_palvelut'] ?? 'molemmat');
        if ($p === 'iltarastit') {
            return true;
        }
        $hallinta = (function_exists('onPaakayttaja') && onPaakayttaja())
            || (function_exists('onSeuraAdmin') && onSeuraAdmin());
        if ($hallinta && in_array($p, ['tulokset', 'molemmat', 'iltarastit'], true)) {
            return true;
        }

        return false;
    }
    $p = (string) ($_SESSION['seura_palvelut'] ?? 'molemmat');
    if ($p === 'molemmat' || $p === $palvelu) {
        return true;
    }
    // Seuran omistaja/admin ja pääkäyttäjä saavat seuralle avatut palvelut,
    // vaikka jäsenyyden palvelut-kenttä olisi vain iltarastit.
    return (function_exists('onPaakayttaja') && onPaakayttaja())
        || (function_exists('onSeuraAdmin') && onSeuraAdmin());
}

function tiliVaadiPalvelu(string $palvelu): void
{
    if (!tiliVoiPalvelun($palvelu)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Ei käyttöoikeutta tähän palveluun tällä tunnuksella.';
        exit;
    }
}

function tiliVaadiOminaisuus(PDO $pdo, string $ominaisuus, string $viesti = ''): void
{
    if (tiliVoi($pdo, $ominaisuus)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $viesti !== ''
        ? $viesti
        : 'Ominaisuus ei kuulu nykyiseen pakettiin. Päivitä paketti hallinnasta.';
    exit;
}

function tiliNykyisetRajat(PDO $pdo): array
{
    tiliVarmistaTaulut($pdo);
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return [
            'max_laitteet' => 200,
            'max_kesto_tuntia' => 48,
            'max_tapahtumat_kk' => 1000,
            'paketti_koodi' => 'admin',
        ];
    }
    $seuraId = (int) ($_SESSION['seura_id'] ?? 0);
    if ($seuraId < 1) {
        $d = tiliPakettiOletukset()['ilmainen'];
        return [
            'max_laitteet' => $d['max_laitteet'],
            'max_kesto_tuntia' => $d['max_kesto_tuntia'],
            'max_tapahtumat_kk' => $d['max_tapahtumat_kk'],
            'paketti_koodi' => 'ilmainen',
        ];
    }
    $st = $pdo->prepare("
        SELECT p.koodi, p.max_laitteet, p.max_kesto_tuntia, p.max_tapahtumat_kk
        FROM seurat s
        INNER JOIN paketit p ON p.id = s.paketti_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $st->execute([$seuraId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $d = tiliPakettiOletukset()['ilmainen'];
        return [
            'max_laitteet' => $d['max_laitteet'],
            'max_kesto_tuntia' => $d['max_kesto_tuntia'],
            'max_tapahtumat_kk' => $d['max_tapahtumat_kk'],
            'paketti_koodi' => 'ilmainen',
        ];
    }
    return [
        'max_laitteet' => (int) $row['max_laitteet'],
        'max_kesto_tuntia' => (int) $row['max_kesto_tuntia'],
        'max_tapahtumat_kk' => (int) $row['max_tapahtumat_kk'],
        'paketti_koodi' => $row['koodi'],
    ];
}

function tiliSeuranTapahtumatKuussa(PDO $pdo, int $seuraId): int
{
    // gps_sessiot.seura_id may not exist yet in older deploys
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM gps_sessiot
            WHERE seura_id = ?
              AND luotu >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $st->execute([$seuraId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @throws RuntimeException
 */
function tiliLuoSeuraKayttajalle(
    PDO $pdo,
    int $userId,
    string $seuraNimi,
    string $pakettiKoodi = 'ilmainen'
): array {
    tiliVarmistaTaulut($pdo);
    $seuraNimi = trim($seuraNimi);
    if ($seuraNimi === '') {
        throw new RuntimeException('Anna seuran nimi');
    }
    $paketti = tiliHaePakettiKoodilla($pdo, $pakettiKoodi);
    if (!$paketti) {
        throw new RuntimeException('Pakettia ei löytynyt');
    }
    $slug = tiliUniikkiSlug($pdo, $seuraNimi);
    $pdo->prepare('INSERT INTO seurat (nimi, slug, paketti_id) VALUES (?, ?, ?)')
        ->execute([$seuraNimi, $slug, (int) $paketti['id']]);
    $seuraId = (int) $pdo->lastInsertId();
    try {
        $pdo->prepare('
            UPDATE seurat
            SET palvelu_gps = 0, palvelu_kilpailut = 0, palvelu_iltarastit = 1
            WHERE id = ?
        ')->execute([$seuraId]);
    } catch (Throwable $e) {
    }
    $pdo->prepare("
        INSERT INTO seura_jasenet (seura_id, user_id, rooli, palvelut, tila)
        VALUES (?, ?, 'omistaja', 'iltarastit', 'aktiivinen')
    ")->execute([$seuraId, $userId]);
    $pdo->prepare('UPDATE users SET viimeisin_seura_id = ? WHERE id = ?')
        ->execute([$seuraId, $userId]);

    return [
        'seura_id' => $seuraId,
        'slug' => $slug,
        'paketti_koodi' => $paketti['koodi'],
    ];
}

/**
 * Aseta käyttäjän aktiivinen seura (jäsenyys + viimeisin_seura_id).
 * $seuraId = null → tyhjennä viimeisin_seura_id (jäsenyyksiä ei poisteta).
 */
function tiliAsetaKayttajanSeura(
    PDO $pdo,
    int $userId,
    ?int $seuraId,
    string $jasenRooli = 'jasen',
    ?string $palvelut = null
): void {
    tiliVarmistaTaulut($pdo);
    if (!in_array($jasenRooli, ['omistaja', 'admin', 'jasen'], true)) {
        $jasenRooli = 'jasen';
    }
    $palveluArvo = $palvelut !== null && in_array($palvelut, tiliPalvelutArvot(), true)
        ? $palvelut
        : 'molemmat';
    $paivitaPalvelut = $palvelut !== null && in_array($palvelut, tiliPalvelutArvot(), true);

    if (!$seuraId || $seuraId <= 0) {
        $pdo->prepare('UPDATE users SET viimeisin_seura_id = NULL WHERE id = ?')->execute([$userId]);
        return;
    }

    $st = $pdo->prepare("SELECT id FROM seurat WHERE id = ? AND tila = 'aktiivinen' LIMIT 1");
    $st->execute([$seuraId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('Valittua seuraa ei löytynyt');
    }

    $pdo->prepare("
        INSERT INTO seura_jasenet (seura_id, user_id, rooli, palvelut, tila)
        VALUES (?, ?, ?, ?, 'aktiivinen')
        ON DUPLICATE KEY UPDATE
            tila = 'aktiivinen',
            rooli = IF(rooli = 'omistaja', rooli, VALUES(rooli)),
            palvelut = IF(?, VALUES(palvelut), palvelut)
    ")->execute([$seuraId, $userId, $jasenRooli, $palveluArvo, $paivitaPalvelut ? 1 : 0]);

    $pdo->prepare('UPDATE users SET viimeisin_seura_id = ? WHERE id = ?')
        ->execute([$seuraId, $userId]);
}

function tiliVoiLuodaSeuran(?array $seura): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if (!$seura) {
        return true;
    }

    return in_array((string) ($seura['jasen_rooli'] ?? ''), ['omistaja', 'admin'], true);
}

function tiliOnSeuranOmistaja(array $seura): bool
{
    return (string) ($seura['jasen_rooli'] ?? '') === 'omistaja'
        || (function_exists('onAdmin') && onAdmin());
}

/**
 * Voiko nykyinen seuran pääkäyttäjä muokata tätä jäsenriviä.
 *
 * @param array<string,mixed> $seura
 * @param array<string,mixed> $kohde
 */
function tiliVoiMuokataSeuraJasenta(array $seura, array $kohde, int $omaId): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    $omaRooli = (string) ($seura['jasen_rooli'] ?? '');
    if (!in_array($omaRooli, ['omistaja', 'admin'], true)) {
        return false;
    }
    $kohdeRooli = (string) ($kohde['rooli'] ?? '');
    $kohdeUid = (int) ($kohde['user_id'] ?? 0);
    if ($kohdeRooli === 'omistaja' && $omaRooli !== 'omistaja') {
        return false;
    }
    if ($omaRooli === 'admin' && $kohdeRooli === 'admin' && $kohdeUid !== $omaId) {
        return false;
    }

    return true;
}

/**
 * @throws RuntimeException
 */
function tiliVaihdaAktiivinenSeura(PDO $pdo, int $userId, int $seuraId): void
{
    tiliVarmistaTaulut($pdo);
    if ($userId < 1 || $seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $st = $pdo->prepare("
        SELECT s.id
        FROM seurat s
        INNER JOIN seura_jasenet j ON j.seura_id = s.id AND j.user_id = ? AND j.tila = 'aktiivinen'
        WHERE s.id = ? AND s.tila = 'aktiivinen'
        LIMIT 1
    ");
    $st->execute([$userId, $seuraId]);
    if (!(int) $st->fetchColumn()) {
        throw new RuntimeException('Et ole tämän seuran jäsen.');
    }
    $pdo->prepare('UPDATE users SET viimeisin_seura_id = ? WHERE id = ?')
        ->execute([$seuraId, $userId]);
}

/**
 * @throws RuntimeException
 */
function tiliPaivitaSeuraJasen(
    PDO $pdo,
    array $seura,
    array $kohde,
    int $omaId,
    string $email,
    string $rooli,
    ?string $palvelut,
    ?string $kisaValikot = null,
    ?int $iltarastiOikeus = null
): void {
    tiliVarmistaTaulut($pdo);
    if (!tiliVoiMuokataSeuraJasenta($seura, $kohde, $omaId)) {
        throw new RuntimeException('Ei oikeutta muokata tätä käyttäjää.');
    }
    $seuraId = (int) ($seura['id'] ?? 0);
    $userId = (int) ($kohde['user_id'] ?? 0);
    if ($seuraId < 1 || $userId < 1) {
        throw new RuntimeException('Käyttäjä puuttuu.');
    }
    $email = tiliValidoiSahkoposti($pdo, $email, $userId, $seuraId);
    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);

    $nykyRooli = (string) ($kohde['rooli'] ?? 'jasen');
    if ($nykyRooli === 'omistaja') {
        return;
    }
    if (!in_array($rooli, ['admin', 'jasen'], true)) {
        $rooli = $nykyRooli === 'admin' ? 'admin' : 'jasen';
    }
    $sql = 'UPDATE seura_jasenet SET rooli = ?';
    $params = [$rooli];
    if ($palvelut !== null && in_array($palvelut, tiliPalvelutArvot(), true)) {
        $sql .= ', palvelut = ?';
        $params[] = $palvelut;
    }
    if ($kisaValikot !== null) {
        $sql .= ', kisa_valikot = ?';
        $valikkoMj = tiliKisaValikotMerkkijonoksi($kisaValikot);
        $params[] = $valikkoMj === '' ? null : $valikkoMj;
    }
    if ($iltarastiOikeus !== null) {
        $sql .= ', iltarasti_oikeus = ?';
        $params[] = $iltarastiOikeus ? 1 : 0;
    }
    $sql .= " WHERE id = ? AND seura_id = ? AND rooli <> 'omistaja'";
    $params[] = (int) ($kohde['id'] ?? 0);
    $params[] = $seuraId;
    $pdo->prepare($sql)->execute($params);
}

/**
 * @throws RuntimeException
 */
function tiliPoistaJasenSeurasta(PDO $pdo, array $seura, array $kohde, int $omaId): void
{
    tiliVarmistaTaulut($pdo);
    $seuraId = (int) ($seura['id'] ?? 0);
    $userId = (int) ($kohde['user_id'] ?? 0);
    $jasenId = (int) ($kohde['id'] ?? 0);
    if ($seuraId < 1 || $userId < 1) {
        throw new RuntimeException('Jäsen puuttuu.');
    }
    if ($userId === $omaId) {
        throw new RuntimeException('Et voi poistaa itseäsi seurasta.');
    }
    if ((string) ($kohde['rooli'] ?? '') === 'omistaja') {
        throw new RuntimeException('Seuran omistajaa ei voi poistaa.');
    }
    if (!tiliVoiMuokataSeuraJasenta($seura, $kohde, $omaId)) {
        throw new RuntimeException('Ei oikeutta poistaa tätä käyttäjää.');
    }
    $pdo->prepare("
        UPDATE seura_jasenet SET tila = 'poistettu'
        WHERE id = ? AND seura_id = ? AND rooli <> 'omistaja'
    ")->execute([$jasenId, $seuraId]);
    $pdo->prepare('UPDATE users SET viimeisin_seura_id = NULL WHERE id = ? AND viimeisin_seura_id = ?')
        ->execute([$userId, $seuraId]);
}

/**
 * Seuran pääkäyttäjä lopettaa seuran JAHOnlinessa (tila = suljettu).
 *
 * @throws RuntimeException
 */
function tiliLopetaSeura(
    PDO $pdo,
    int $seuraId,
    int $actorId,
    string $vahvistusNimi = '',
    bool $haluanLopettaa = false
): void {
    tiliVarmistaTaulut($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $st = $pdo->prepare('SELECT * FROM seurat WHERE id = ? LIMIT 1');
    $st->execute([$seuraId]);
    $seura = $st->fetch(PDO::FETCH_ASSOC);
    if (!$seura) {
        throw new RuntimeException('Seuraa ei löytynyt.');
    }
    if (!$haluanLopettaa) {
        throw new RuntimeException('Vahvista seuran lopetus painamalla «Haluan lopettaa seuran».');
    }
    if (!tiliSeuraNimiTasmaa((string) ($seura['nimi'] ?? ''), $vahvistusNimi)) {
        throw new RuntimeException('Kirjoita seuran nimi vahvistukseksi.');
    }
    $saa = function_exists('onAdmin') && onAdmin();
    if (!$saa) {
        $j = $pdo->prepare("
            SELECT rooli FROM seura_jasenet
            WHERE seura_id = ? AND user_id = ? AND tila = 'aktiivinen'
            LIMIT 1
        ");
        $j->execute([$seuraId, $actorId]);
        $saa = $j->fetchColumn() === 'omistaja';
    }
    if (!$saa) {
        throw new RuntimeException('Vain seuran pääkäyttäjä voi lopettaa seuran.');
    }
    $pdo->prepare("UPDATE seurat SET tila = 'suljettu' WHERE id = ?")->execute([$seuraId]);
    $pdo->prepare('UPDATE users SET viimeisin_seura_id = NULL WHERE viimeisin_seura_id = ?')
        ->execute([$seuraId]);
}

/**
 * Sivuston pääkäyttäjä poistaa seuran ja sen käyttäjät, joilla ei ole muuta seuraa.
 *
 * @return array{nimi:string,kayttajia:int}
 * @throws RuntimeException
 */
function tiliPoistaSeuraJaKayttajat(PDO $pdo, int $seuraId, int $actorId): array
{
    tiliVarmistaTaulut($pdo);
    if (!(function_exists('onAdmin') && onAdmin())) {
        throw new RuntimeException('Vain sivuston pääkäyttäjä voi poistaa seuran.');
    }
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    $st = $pdo->prepare('SELECT * FROM seurat WHERE id = ? LIMIT 1');
    $st->execute([$seuraId]);
    $seura = $st->fetch(PDO::FETCH_ASSOC);
    if (!$seura) {
        throw new RuntimeException('Seuraa ei löytynyt.');
    }
    $jasenet = $pdo->prepare('SELECT DISTINCT user_id FROM seura_jasenet WHERE seura_id = ?');
    $jasenet->execute([$seuraId]);
    $userIds = [];
    foreach ($jasenet as $r) {
        $uid = (int) ($r['user_id'] ?? 0);
        if ($uid > 0) {
            $userIds[] = $uid;
        }
    }
    $poistettavat = [];
    foreach ($userIds as $uid) {
        if ($uid === $actorId) {
            continue;
        }
        $u = tiliHaeKayttajaIdlla($pdo, $uid);
        if (!$u || ($u['role'] ?? '') === 'admin') {
            continue;
        }
        $muu = $pdo->prepare('SELECT COUNT(*) FROM seura_jasenet WHERE user_id = ? AND seura_id <> ?');
        $muu->execute([$uid, $seuraId]);
        if ((int) $muu->fetchColumn() > 0) {
            continue;
        }
        $poistettavat[] = $uid;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM seura_jasenet WHERE seura_id = ?')->execute([$seuraId]);
        $pdo->prepare('UPDATE users SET viimeisin_seura_id = NULL WHERE viimeisin_seura_id = ?')
            ->execute([$seuraId]);
        try {
            $pdo->prepare('UPDATE kilpailut SET seura_id = NULL WHERE seura_id = ?')->execute([$seuraId]);
        } catch (Throwable $e) {
        }
        try {
            $pdo->prepare('UPDATE gps_sessiot SET seura_id = NULL WHERE seura_id = ?')->execute([$seuraId]);
        } catch (Throwable $e) {
        }
        try {
            $pdo->prepare('UPDATE emit_henkilot SET seura_id = NULL WHERE seura_id = ?')->execute([$seuraId]);
        } catch (Throwable $e) {
        }
        $pdo->prepare('DELETE FROM seurat WHERE id = ?')->execute([$seuraId]);
        foreach ($poistettavat as $uid) {
            $pdo->prepare('UPDATE users SET paakayttaja_id = NULL WHERE paakayttaja_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM seura_jasenet WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'")->execute([$uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Seuran poisto epäonnistui.');
    }

    return [
        'nimi' => (string) ($seura['nimi'] ?? ''),
        'kayttajia' => count($poistettavat),
    ];
}

function tiliPoistaKayttaja(PDO $pdo, int $userId, int $actorId): void
{
    tiliVarmistaTaulut($pdo);
    if ($userId < 1) {
        throw new RuntimeException('Käyttäjä puuttuu.');
    }
    if ($userId === $actorId) {
        throw new RuntimeException('Et voi poistaa itseäsi.');
    }
    $pdo->prepare('UPDATE users SET paakayttaja_id = NULL WHERE paakayttaja_id = ?')->execute([$userId]);
    try {
        $pdo->prepare('DELETE FROM seura_jasenet WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
}

function tiliSalasanaVaatimusTeksti(): string
{
    return 'Vähintään 8 merkkiä ja kolme seuraavista: iso kirjain, pieni kirjain, numero, erikoismerkki.';
}

function tiliSalasanaLuokat(string $password): int
{
    $n = 0;
    if (preg_match('/\p{Lu}/u', $password)) {
        $n++;
    }
    if (preg_match('/\p{Ll}/u', $password)) {
        $n++;
    }
    if (preg_match('/\p{Nd}/u', $password)) {
        $n++;
    }
    if (preg_match('/[^\p{L}\p{Nd}]/u', $password)) {
        $n++;
    }

    return $n;
}

function tiliSalasanaPituus(string $password): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($password, 'UTF-8');
    }

    return strlen($password);
}

/**
 * @throws RuntimeException
 */
function tiliValidoiSalasana(string $password): void
{
    if (tiliSalasanaPituus($password) < 8 || tiliSalasanaLuokat($password) < 3) {
        throw new RuntimeException(
            'Salasanan tulee olla vähintään 8 merkkiä ja sisältää kolme seuraavista: iso kirjain, pieni kirjain, numero, erikoismerkki.'
        );
    }
}

function tiliSahkopostiPuuttuu(?array $user): bool
{
    if (!$user) {
        return true;
    }

    return trim((string) ($user['email'] ?? '')) === '';
}

function tiliSahkopostiSivuSallittu(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['sahkoposti.php', 'logout.php', 'login.php'], true)) {
        return true;
    }
    $polku = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    return str_contains($polku, '/api/');
}

function tiliVaadiSahkoposti(PDO $pdo): void
{
    if (empty($_SESSION['user_id']) || tiliSahkopostiSivuSallittu()) {
        return;
    }
    $user = tiliHaeKayttajaIdlla($pdo, (int) $_SESSION['user_id']);
    if (!tiliSahkopostiPuuttuu($user)) {
        return;
    }
    $kohde = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $q = '';
    if ($kohde !== '' && function_exists('turvallinenRedirect') && turvallinenRedirect($kohde)) {
        $q = '?redirect=' . urlencode($kohde);
    }
    header('Location: /auth/sahkoposti.php' . $q);
    exit;
}

/**
 * Vaadi kelvollinen sähköposti. Palauttaa normalisoidun osoitteen.
 * Sama osoite sallitaan usealle käyttäjälle (ei uniikkirajoitetta).
 *
 * @throws RuntimeException
 */
function tiliValidoiSahkoposti(
    PDO $pdo,
    ?string $email,
    ?int $excludeUserId = null,
    ?int $seuraId = null
): string {
    tiliVarmistaTaulut($pdo);
    $email = $email !== null ? trim($email) : '';
    if ($email === '') {
        throw new RuntimeException('Sähköposti on pakollinen');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Sähköposti ei ole kelvollinen');
    }
    if (strlen($email) > 255) {
        throw new RuntimeException('Sähköposti on liian pitkä');
    }

    return mb_strtolower($email, 'UTF-8');
}

/**
 * Sivuston avain–arvo-asetukset (esim. rekisteröinti päälle/pois).
 */
function jahVarmistaAsetukset(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jah_asetukset (
            avain VARCHAR(100) NOT NULL PRIMARY KEY,
            arvo TEXT NOT NULL,
            paivitetty TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ok = true;
}

function jahAsetus(PDO $pdo, string $avain, ?string $oletus = null): ?string
{
    jahVarmistaAsetukset($pdo);
    $st = $pdo->prepare('SELECT arvo FROM jah_asetukset WHERE avain = ? LIMIT 1');
    $st->execute([$avain]);
    $row = $st->fetchColumn();
    if ($row === false) {
        return $oletus;
    }

    return (string) $row;
}

function jahAsetaAsetus(PDO $pdo, string $avain, string $arvo): void
{
    jahVarmistaAsetukset($pdo);
    $pdo->prepare('
        INSERT INTO jah_asetukset (avain, arvo) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE arvo = VALUES(arvo)
    ')->execute([$avain, $arvo]);
}

function jahRekisterointiAuki(PDO $pdo): bool
{
    return jahAsetus($pdo, 'rekisterointi_auki', '1') !== '0';
}

function tiliRekisteroi(
    PDO $pdo,
    string $username,
    string $password,
    string $seuraNimi,
    ?string $email = null,
    ?string $nimi = null
): array {
    tiliVarmistaTaulut($pdo);
    $username = trim($username);
    $seuraNimi = trim($seuraNimi);
    $nimi = $nimi !== null ? trim($nimi) : null;
    $email = tiliValidoiSahkoposti($pdo, $email);

    if ($username === '' || strlen($username) < 3) {
        throw new RuntimeException('Käyttäjätunnuksen tulee olla vähintään 3 merkkiä');
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        throw new RuntimeException('Käyttäjätunnus saa sisältää vain kirjaimia, numeroita ja ._-');
    }
    tiliValidoiSalasana($password);
    if ($seuraNimi === '') {
        throw new RuntimeException('Anna seuran nimi');
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('Käyttäjätunnus on jo käytössä');
    }

    if (function_exists('kayttajaRoolitVarmista')) {
        kayttajaRoolitVarmista($pdo);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('
        INSERT INTO users (username, password, role, email, nimi)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([
        $username,
        $hash,
        'iltarasti',
        $email,
        $nimi !== '' ? $nimi : null,
    ]);
    $userId = (int) $pdo->lastInsertId();
    $seura = tiliLuoSeuraKayttajalle($pdo, $userId, $seuraNimi, 'ilmainen');
    $user = tiliHaeKayttajaIdlla($pdo, $userId);
    $seuraFull = tiliHaeSeuraKayttajalle($pdo, $userId);

    return [
        'user' => $user,
        'seura' => $seuraFull,
        'seura_id' => $seura['seura_id'],
    ];
}

function tiliSeuranOminaisuudetIdlla(PDO $pdo, ?int $seuraId): array
{
    tiliVarmistaTaulut($pdo);
    if (!$seuraId) {
        return tiliPakettiOletukset()['ilmainen']['ominaisuudet'];
    }
    $st = $pdo->prepare("
        SELECT p.*
        FROM seurat s
        INNER JOIN paketit p ON p.id = s.paketti_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $st->execute([$seuraId]);
    return tiliPakettiOminaisuudet($st->fetch(PDO::FETCH_ASSOC) ?: null);
}

/** Bootstrap tulospalvelun admin-sivuille (auth + paketti). */
function tiliVaadiTulospalvelu(PDO $pdo): void
{
    tiliVarmistaTaulut($pdo);
    tiliLataaKayttajaSessioon($pdo);
    if (!tiliSessioSeuraPalvelu('kilpailut')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Kilpailut-palvelu ei ole käytössä tällä seuralla.';
        exit;
    }
    tiliVaadiPalvelu('tulokset');
    tiliVaadiOminaisuus($pdo, 'tulospalvelu', 'Tulospalvelu ei kuulu nykyiseen pakettiin.');
}
