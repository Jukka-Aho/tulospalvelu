<?php

/**
 * Iltarastit: Rastilippu-tapahtuman paikallinen juoksutus.
 */

require_once __DIR__ . '/kisa_luonne.php';
require_once __DIR__ . '/rastilippu.php';
if (is_readable(__DIR__ . '/tili.php')) {
    require_once __DIR__ . '/tili.php';
}
if (is_readable(__DIR__ . '/emit_henkilot.php')) {
    require_once __DIR__ . '/emit_henkilot.php';
}

function iltarastiVarmista(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $ok = true;
    if (function_exists('kisaLuonneVarmista')) {
        kisaLuonneVarmista($pdo);
    }
    rastilippuVarmista($pdo);
    $lisaa = static function (PDO $pdo, string $col, string $ddl): void {
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `kilpailut` LIKE ' . $pdo->quote($col));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                return;
            }
            $pdo->exec('ALTER TABLE `kilpailut` ADD COLUMN ' . $ddl);
        } catch (Throwable $e) {
        }
    };
    $lisaa($pdo, 'tyyppi', "tyyppi ENUM('henkilokohtainen','viesti') NOT NULL DEFAULT 'henkilokohtainen'");
    if (is_readable(__DIR__ . '/viesti_sarjat.php')) {
        require_once __DIR__ . '/viesti_sarjat.php';
        if (function_exists('viestiSarjatVarmista')) {
            viestiSarjatVarmista($pdo);
        }
    }
    $lisaa($pdo, 'rastilippu_event_id', 'rastilippu_event_id INT NULL DEFAULT NULL');
    $lisaa($pdo, 'rastilippu_event_uuid', 'rastilippu_event_uuid VARCHAR(64) NULL DEFAULT NULL');
    $lisaa($pdo, 'rastilippu_series_id', 'rastilippu_series_id INT NULL DEFAULT NULL');
    $lisaa($pdo, 'alku_at', 'alku_at DATETIME NULL DEFAULT NULL');
    $lisaa($pdo, 'loppu_at', 'loppu_at DATETIME NULL DEFAULT NULL');
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `kilpailijat` LIKE ' . $pdo->quote('laina'));
        if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE `kilpailijat` ADD COLUMN laina CHAR(1) NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('CREATE UNIQUE INDEX uq_kilpailut_seura_rastilippu ON kilpailut (seura_id, rastilippu_event_id)');
    } catch (Throwable $e) {
    }
}

function iltarastiVoiPalvelun(): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if (!function_exists('tiliVoiPalvelun')) {
        return function_exists('onPaakayttaja') && onPaakayttaja();
    }

    return tiliVoiPalvelun('iltarastit');
}

function iltarastiDtLomakkeeseen(?string $dt): string
{
    $dt = trim((string) $dt);
    if ($dt === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $dt, $m)) {
        return $m[1] . 'T' . $m[2];
    }

    return '';
}

function iltarastiDtKannasta(?string $raw): ?string
{
    $s = trim(str_replace('T', ' ', (string) $raw));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(?::(\d{2}))?/', $s, $m)) {
        return $m[1] . ' ' . $m[2] . ':' . ($m[3] ?? '00');
    }

    return null;
}

function iltarastiTanaan(): string
{
    if (function_exists('paikallinenAikaNyt')) {
        return substr(paikallinenAikaNyt(), 0, 10);
    }
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
    } catch (Throwable $e) {
        return date('Y-m-d');
    }
}

function iltarastiOletusAlku(?string $pvm): string
{
    $pvm = rastilippuPvm($pvm) ?: iltarastiTanaan();

    return $pvm . 'T16:00';
}

function iltarastiOletusLoppu(?string $pvm): string
{
    $pvm = rastilippuPvm($pvm) ?: iltarastiTanaan();

    return $pvm . 'T21:00';
}

function iltarastiTulospalveluUrl(int $kilpailuId): string
{
    return '/public/emit_lukija.php?kilpailu_id=' . (int) $kilpailuId;
}

function iltarastiKioskiUrl(string $sivu, int $kilpailuId = 0): string
{
    return match ($sivu) {
        'hub' => '/public/omat_tapahtumat.php',
        'osanottajat' => '/public/kilpailijat.php',
        'asetukset' => '/public/iltarasti_asetukset.php',
        'tulokset' => '/public/iltarasti_tulokset.php?kilpailu_id=' . (int) $kilpailuId,
        'radat' => '/public/radat.php',
        'tulospalvelu' => iltarastiTulospalveluUrl($kilpailuId),
        'automaatti' => '/public/automaatti.php?kilpailu_id=' . (int) $kilpailuId,
        'esitykset' => '/public/iltarasti_esitykset.php?kilpailu_id=' . (int) $kilpailuId,
        default => '/public/omat_tapahtumat.php',
    };
}

function iltarastiOhjaaJulkiseen(string $julkinenPolku): void
{
    if (defined('JAH_ILTARASTI_PUBLIC') && JAH_ILTARASTI_PUBLIC) {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $julkinenPolku . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}

function iltarastiAikaVali(?string $alku, ?string $loppu, ?string $pvm = null): string
{
    $alkuLomake = iltarastiDtLomakkeeseen($alku);
    $loppuLomake = iltarastiDtLomakkeeseen($loppu);
    $pvm = rastilippuPvm($pvm) ?: ($alkuLomake !== '' ? substr($alkuLomake, 0, 10) : '');
    $paivaTxt = $pvm !== '' && function_exists('formatPaiva') ? formatPaiva($pvm) : $pvm;
    $alkuKello = $alkuLomake !== '' ? substr($alkuLomake, 11, 5) : '';
    $loppuKello = $loppuLomake !== '' ? substr($loppuLomake, 11, 5) : '';
    if ($paivaTxt === '' && $alkuKello === '') {
        return '';
    }
    if ($alkuKello !== '' && $loppuKello !== '') {
        return trim($paivaTxt . ' ' . $alkuKello . '–' . $loppuKello);
    }
    if ($alkuKello !== '') {
        return trim($paivaTxt . ' ' . $alkuKello);
    }

    return $paivaTxt;
}

/**
 * @return array<string,mixed>
 */
function iltarastiKuoriOpts(PDO $pdo, array $kilpailu, string $active, string $class = ''): array
{
    return [
        'admin' => true,
        'active' => $active,
        'pdo' => $pdo,
        'palvelu' => 'iltarastit',
        'iltarastiKuori' => true,
        'otsikko' => (string) ($kilpailu['nimi'] ?? 'Iltarastit'),
        'class' => trim($class . ' iltarasti-kioski'),
    ];
}

function iltarastiPvmKannasta(?string $raw): ?string
{
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    if (function_exists('rastilippuPvm')) {
        $p = rastilippuPvm($s);
        if (is_string($p) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p)) {
            return $p;
        }
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }

    return null;
}

function iltarastiTallennaAsetukset(
    PDO $pdo,
    int $kilpailuId,
    string $nimi,
    ?string $paikka,
    ?string $pvm = null
): void {
    $nimi = trim($nimi);
    if ($nimi === '') {
        throw new RuntimeException('Anna tapahtuman nimi.');
    }
    $pvm = iltarastiPvmKannasta($pvm);
    if ($pvm === null) {
        throw new RuntimeException('Anna tapahtuman päivämäärä.');
    }
    $tila = iltarastiTilaPaivasta($pvm);
    $paikka = trim((string) $paikka);
    $pdo->prepare('
        UPDATE kilpailut
        SET nimi = ?, paikka = ?, paiva = ?, alku_at = NULL, loppu_at = NULL, tila = ?
        WHERE id = ? AND luonne = \'iltarasti\'
    ')->execute([
        mb_substr($nimi, 0, 255),
        $paikka !== '' ? mb_substr($paikka, 0, 255) : null,
        $pvm,
        $tila,
        $kilpailuId,
    ]);
}

/**
 * @param array<string,mixed>|null $tapahtuma rastilippuNormalisoiTapahtuma(); null irrottaa.
 */
function iltarastiYhdistaRastilippuun(PDO $pdo, int $kilpailuId, int $seuraId, ?array $tapahtuma): void
{
    iltarastiVarmista($pdo);
    if ($kilpailuId < 1) {
        throw new RuntimeException('Tapahtuma puuttuu.');
    }
    $eventId = (int) ($tapahtuma['id'] ?? 0);
    if ($tapahtuma !== null && $eventId > 0 && function_exists('tiliSeuraSallii') && !tiliSeuraSallii($pdo, $seuraId, 'iltarastit')) {
        throw new RuntimeException('Iltarastit-palvelu ei ole käytössä tällä seuralla.');
    }
    if ($tapahtuma !== null && $eventId > 0 && function_exists('tiliVaadiRastilippuLiitanta')) {
        tiliVaadiRastilippuLiitanta($pdo, $seuraId);
    }
    if ($tapahtuma !== null && $eventId > 0) {
        $kisaNyt = function_exists('haeKilpailu') ? haeKilpailu($pdo, $kilpailuId) : null;
        if (is_array($kisaNyt) && function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kisaNyt)) {
            throw new RuntimeException('Rastilippu-liitäntä on toistaiseksi vain henkilökohtaisissa tapahtumissa.');
        }
    }
    if ($tapahtuma === null || $eventId < 1) {
        $pdo->prepare("
            UPDATE kilpailut
            SET rastilippu_event_id = NULL, rastilippu_event_uuid = NULL, rastilippu_series_id = NULL
            WHERE id = ? AND luonne IN ('iltarasti','suunnistus') AND COALESCE(pirila_synkka,0) = 0
        ")->execute([$kilpailuId]);

        return;
    }
    $olemassa = iltarastiHaeRastilipusta($pdo, $seuraId, $eventId);
    if ($olemassa && (int) $olemassa['id'] !== $kilpailuId) {
        $nimi = trim((string) ($olemassa['nimi'] ?? ''));
        throw new RuntimeException(
            'Tämä Rastilippu-tapahtuma on jo yhdistetty toiseen iltaan'
            . ($nimi !== '' ? ' (' . $nimi . ')' : '') . '.'
        );
    }
    $uuid = trim((string) ($tapahtuma['uuid'] ?? ''));
    $series = !empty($tapahtuma['series_id']) ? (int) $tapahtuma['series_id'] : null;
    try {
        $pdo->prepare("
            UPDATE kilpailut
            SET rastilippu_event_id = ?, rastilippu_event_uuid = ?, rastilippu_series_id = ?
            WHERE id = ? AND luonne IN ('iltarasti','suunnistus') AND COALESCE(pirila_synkka,0) = 0
        ")->execute([
            $eventId,
            $uuid !== '' ? mb_substr($uuid, 0, 64) : null,
            $series,
            $kilpailuId,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Tämä Rastilippu-tapahtuma on jo yhdistetty toiseen iltaan.');
    }
    if ($uuid !== '' && function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailuId);
    }
}

/**
 * @return array<string,mixed>|null
 */
function iltarastiHaeRastilippuTapahtuma(PDO $pdo, int $seuraId, int $eventId, bool $pakotaPaivitys = false): ?array
{
    if ($seuraId < 1 || $eventId < 1 || !function_exists('rastilippuTapahtumatSeuralle')) {
        return null;
    }
    $lista = rastilippuTapahtumatSeuralle($pdo, $seuraId, $pakotaPaivitys) ?: [];
    foreach ($lista as $t) {
        if ((int) ($t['id'] ?? 0) === $eventId) {
            return is_array($t) ? $t : null;
        }
    }
    if (!$pakotaPaivitys) {
        return iltarastiHaeRastilippuTapahtuma($pdo, $seuraId, $eventId, true);
    }

    return null;
}

function iltarastiAsetaLaina(PDO $pdo, int $kilpailijaId, bool $laina): void
{
    if ($kilpailijaId < 1) {
        return;
    }
    try {
        $pdo->prepare('UPDATE kilpailijat SET laina = ? WHERE id = ?')
            ->execute([$laina ? 'X' : null, $kilpailijaId]);
    } catch (Throwable $e) {
    }
}

function iltarastiSeuraRooli(): string
{
    return (string) ($_SESSION['seura_rooli'] ?? '');
}

/** Seuran iltarastien pääkäyttäjä (omistaja/admin) tai järjestelmän pääkäyttäjä. */
function iltarastiOnPaakayttaja(): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    if (function_exists('onPaakayttaja') && onPaakayttaja()) {
        return true;
    }

    return in_array(iltarastiSeuraRooli(), ['omistaja', 'admin'], true);
}

function iltarastiVoiAvaimen(): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }

    return in_array(iltarastiSeuraRooli(), ['omistaja', 'admin'], true);
}

/** Normi seurakäyttäjä saa valita Rastilippu-tapahtuman seuran avaimella. */
function iltarastiVoiYhdistaa(): bool
{
    return iltarastiVoiPalvelun();
}

function iltarastiSeurallaOnNimi(?array $seura): bool
{
    return $seura !== null && trim((string) ($seura['nimi'] ?? '')) !== '';
}

function iltarastiKayttajaTasoLabel(?string $jasenRooli): string
{
    if (in_array((string) $jasenRooli, ['omistaja', 'admin'], true)) {
        return 'Pääkäyttäjä';
    }

    return 'Iltarastikäyttäjä';
}

function iltarastiTilaPaivasta(?string $pvm): string
{
    $pvm = trim((string) $pvm);
    if ($pvm === '') {
        return 'tulossa';
    }
    $tanaan = iltarastiTanaan();
    if ($pvm > $tanaan) {
        return 'tulossa';
    }
    if ($pvm === $tanaan) {
        return 'kaynnissa';
    }

    return 'valmis';
}

function iltarastiVaadiPalvelu(): void
{
    if (function_exists('vaadiKirjautuminen')) {
        vaadiKirjautuminen();
    }
    if (!iltarastiVoiPalvelun()) {
        http_response_code(403);
        die('Ei käyttöoikeutta iltarasteihin.');
    }
}

function iltarastiVoiHallita(array $kilpailu): bool
{
    if (!kisaOnIltarasti($kilpailu)) {
        return false;
    }
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    $omaSeura = function_exists('nykyinenSeuraId') ? nykyinenSeuraId() : (int) ($_SESSION['seura_id'] ?? 0);
    if ($seuraId > 0 && $omaSeura === $seuraId && iltarastiVoiPalvelun()) {
        return true;
    }
    $owner = (int) ($kilpailu['paakayttaja_id'] ?? 0);
    $oma = function_exists('paakayttajaId') ? (int) (paakayttajaId() ?? 0) : 0;

    return $owner > 0 && $oma > 0 && $owner === $oma && iltarastiVoiPalvelun();
}

function iltarastiKeskhylLabel(string $kirjain): string
{
    $k = mb_strtoupper(mb_substr(trim($kirjain), 0, 1), 'UTF-8');

    return match ($k) {
        'T' => 'Hyväksytty',
        'E' => 'Esitys',
        'H' => 'Hylätty',
        'K' => 'Keskeyttänyt',
        '-' => 'Lähtenyt',
        default => $k !== '' ? $k : '—',
    };
}

function iltarastiStatusKeskhyl(string $kirjain): string
{
    $k = mb_strtoupper(mb_substr(trim($kirjain), 0, 1), 'UTF-8');

    return match ($k) {
        'H' => 'DSQ',
        'K' => 'DNF',
        // Esitys on keskhyl=E. status-sarake on ENUM ilman ESITYS-arvoa.
        'E' => 'OK',
        'T' => 'OK',
        default => 'OK',
    };
}

function iltarastiTulosTila(array $rivi): string
{
    if (iltarastiOnMaastossa($rivi)) {
        return 'Maastossa';
    }
    $k = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');
    if ($k !== '') {
        return iltarastiKeskhylLabel($k);
    }
    $st = strtoupper(trim((string) ($rivi['status'] ?? '')));
    if ($st === 'ESITYS') {
        return 'Esitys';
    }
    if ($st === 'DSQ') {
        return 'Hylätty';
    }
    if ($st === 'DNF') {
        return 'Keskeyttänyt';
    }
    $aika = $rivi['aika_sec'] ?? $rivi['maali_aika_sec'] ?? null;
    if ($aika) {
        return 'Hyväksytty';
    }

    return '—';
}

/** Onko kilpailija vielä maastossa (ei hyväksytty, hylätty, keskeyttänyt eikä esitys). */
function iltarastiOnMaastossa(array $rivi): bool
{
    $k = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');

    return $k === '' || $k === '-';
}

/**
 * Osanottajat radoittain (iltarastissa rata = sarja). Tuntematon sarja_id tulee perään.
 *
 * @param list<array<string,mixed>> $radat
 * @param list<array<string,mixed>> $henkilot
 * @return list<array{id:int,nimi:string,henkilot:list<array<string,mixed>>,maastossa:int}>
 */
function iltarastiRyhmitteleSarjoittain(array $radat, array $henkilot): array
{
    $ryhmat = [];
    foreach ($radat as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $ryhmat[$id] = [
            'id' => $id,
            'nimi' => (string) ($r['nimi'] ?? ''),
            'henkilot' => [],
            'maastossa' => 0,
        ];
    }
    foreach ($henkilot as $h) {
        $id = (int) ($h['sarja_id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (!isset($ryhmat[$id])) {
            $ryhmat[$id] = [
                'id' => $id,
                'nimi' => (string) ($h['rata'] ?? ''),
                'henkilot' => [],
                'maastossa' => 0,
            ];
        }
        $ryhmat[$id]['henkilot'][] = $h;
        if (iltarastiOnMaastossa($h)) {
            $ryhmat[$id]['maastossa']++;
        }
    }

    return array_values($ryhmat);
}

/**
 * @return list<array<string,mixed>>
 */
function iltarastiHaeSeuran(PDO $pdo, int $seuraId): array
{
    iltarastiVarmista($pdo);
    if ($seuraId < 1 && !(function_exists('onAdmin') && onAdmin())) {
        return [];
    }
    $sql = '
        SELECT * FROM kilpailut
        WHERE luonne IN (\'iltarasti\',\'suunnistus\')
          AND COALESCE(pirila_synkka, 0) = 0
    ';
    $params = [];
    if ($seuraId > 0) {
        $sql .= ' AND seura_id = ?';
        $params[] = $seuraId;
    }
    $sql .= ' ORDER BY paiva DESC, id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function iltarastiHaeRastilipusta(PDO $pdo, int $seuraId, int $eventId): ?array
{
    iltarastiVarmista($pdo);
    if ($seuraId < 1 || $eventId < 1) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT * FROM kilpailut
        WHERE seura_id = ? AND rastilippu_event_id = ?
        LIMIT 1
    ');
    $st->execute([$seuraId, $eventId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param array<string,mixed> $tapahtuma rastilippuNormalisoiTapahtuma()
 */
function iltarastiLuoTapahtumasta(PDO $pdo, int $seuraId, array $tapahtuma, ?int $omistajaId = null): array
{
    iltarastiVarmista($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    if (function_exists('tiliSeuraSallii') && !tiliSeuraSallii($pdo, $seuraId, 'iltarastit')) {
        throw new RuntimeException('Iltarastit-palvelu ei ole käytössä tällä seuralla.');
    }
    if (function_exists('tiliVaadiRastilippuLiitanta')) {
        tiliVaadiRastilippuLiitanta($pdo, $seuraId);
    }
    $eventId = (int) ($tapahtuma['id'] ?? 0);
    if ($eventId < 1) {
        throw new RuntimeException('Rastilippu-tapahtuma puuttuu.');
    }
    $olemassa = iltarastiHaeRastilipusta($pdo, $seuraId, $eventId);
    if ($olemassa) {
        return $olemassa;
    }
    $nimi = trim((string) ($tapahtuma['nimi'] ?? ''));
    if ($nimi === '') {
        $nimi = 'Iltarastit';
    }
    $pvm = $tapahtuma['paiva'] ?? null;
    $pvmStr = is_string($pvm) ? $pvm : null;
    $paikka = trim((string) ($tapahtuma['paikka'] ?? ''));
    $tila = iltarastiTilaPaivasta($pvmStr);
    $token = function_exists('generoiApiToken') ? generoiApiToken() : bin2hex(random_bytes(16));
    $pdo->prepare('
        INSERT INTO kilpailut
            (nimi, paiva, paikka, tila, kisa_tyyppi, api_token, pirila_synkka, paakayttaja_id, seura_id,
             ilmoittautuminen_auki, testikisa, jaettu_jono,
             luonne, rastilippu_event_id, rastilippu_event_uuid, rastilippu_series_id, alku_at, loppu_at)
        VALUES (?, ?, ?, ?, \'normaali\', ?, 0, ?, ?, 0, 0, 0, \'suunnistus\', ?, ?, ?, NULL, NULL)
    ')->execute([
        mb_substr($nimi, 0, 255),
        $pvm ?: null,
        $paikka !== '' ? mb_substr($paikka, 0, 255) : null,
        $tila,
        $token,
        $omistajaId && $omistajaId > 0 ? $omistajaId : null,
        $seuraId,
        $eventId,
        ($tapahtuma['uuid'] ?? '') !== '' ? mb_substr((string) $tapahtuma['uuid'], 0, 64) : null,
        !empty($tapahtuma['series_id']) ? (int) $tapahtuma['series_id'] : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM kilpailut WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Tapahtuman luonti epäonnistui.');
    }

    return $row;
}

function iltarastiSiivoaHaamuSarjat(PDO $pdo, int $kilpailuId): int
{
    if ($kilpailuId < 1) {
        return 0;
    }
    $lu = $pdo->prepare('SELECT luonne FROM kilpailut WHERE id = ?');
    $lu->execute([$kilpailuId]);
    if ((string) $lu->fetchColumn() !== 'iltarasti') {
        return 0;
    }
    $st = $pdo->prepare('
        SELECT s.id
        FROM sarjat s
        WHERE s.kilpailu_id = ?
          AND NOT EXISTS (
              SELECT 1 FROM radat r
              WHERE r.kilpailu_id = s.kilpailu_id
                AND (r.tunnus = s.nimi OR (s.rata_nimi IS NOT NULL AND s.rata_nimi <> \'\' AND r.tunnus = s.rata_nimi))
          )
          AND NOT EXISTS (SELECT 1 FROM kilpailijat k WHERE k.sarja_id = s.id)
    ');
    $st->execute([$kilpailuId]);
    $poistetut = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
        $sid = (int) $sid;
        if ($sid < 1) {
            continue;
        }
        if (function_exists('radatPoistaTyhjaSarja') && radatPoistaTyhjaSarja($pdo, $kilpailuId, '', $sid)) {
            $poistetut++;
        }
    }

    return $poistetut;
}

/**
 * Iltarastin rata ja sarja ovat 1:1. Nimen vaihto nimeää saman sarjan;
 * vanha tyhjä «B» ei jää osanottajiin.
 */
function iltarastiVarmistaRataSarja(PDO $pdo, int $kilpailuId, int $rataId, ?string $vanhaTunnus = null): void
{
    if ($kilpailuId < 1 || $rataId < 1) {
        return;
    }
    if (function_exists('radatVarmistaTaulut')) {
        radatVarmistaTaulut($pdo);
    }
    $st = $pdo->prepare('SELECT tunnus, pituus_m FROM radat WHERE id = ? AND kilpailu_id = ?');
    $st->execute([$rataId, $kilpailuId]);
    $rata = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rata) {
        return;
    }
    $tunnus = trim((string) $rata['tunnus']);
    if ($tunnus === '') {
        return;
    }
    $vanha = trim((string) $vanhaTunnus);
    if ($vanha === $tunnus) {
        $vanha = '';
    }
    $pdo->prepare('UPDATE radat SET ennakko = 0, maalilaji = 4 WHERE id = ?')->execute([$rataId]);

    $nimet = [$tunnus];
    if ($vanha !== '') {
        $nimet[] = $vanha;
    }
    $nimet = array_values(array_unique($nimet));
    $ph = implode(',', array_fill(0, count($nimet), '?'));
    $h = $pdo->prepare("
        SELECT id, nimi, rata_nimi
        FROM sarjat
        WHERE kilpailu_id = ?
          AND (nimi IN ($ph) OR rata_nimi IN ($ph))
        ORDER BY id
    ");
    $h->execute(array_merge([$kilpailuId], $nimet, $nimet));
    $ehdokkaat = $h->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sarjaId = 0;
    foreach ($ehdokkaat as $s) {
        if ((string) ($s['nimi'] ?? '') === $tunnus || (string) ($s['rata_nimi'] ?? '') === $tunnus) {
            $sarjaId = (int) $s['id'];
            break;
        }
    }
    if ($sarjaId < 1 && $ehdokkaat !== []) {
        $sarjaId = (int) $ehdokkaat[0]['id'];
    }
    if ($sarjaId < 1) {
        $pdo->prepare('INSERT INTO sarjat (kilpailu_id, nimi) VALUES (?, ?)')->execute([$kilpailuId, $tunnus]);
        $sarjaId = (int) $pdo->lastInsertId();
    }
    if ($sarjaId < 1) {
        return;
    }

    $pdo->prepare('UPDATE sarjat SET nimi = ?, rata_nimi = ?, rata_oma = 1 WHERE id = ? AND kilpailu_id = ?')
        ->execute([$tunnus, $tunnus, $sarjaId, $kilpailuId]);

    foreach ($ehdokkaat as $s) {
        $toinen = (int) ($s['id'] ?? 0);
        if ($toinen < 1 || $toinen === $sarjaId) {
            continue;
        }
        $pdo->prepare('UPDATE kilpailijat SET sarja_id = ? WHERE sarja_id = ? AND kilpailu_id = ?')
            ->execute([$sarjaId, $toinen, $kilpailuId]);
        if (function_exists('radatPoistaTyhjaSarja')) {
            radatPoistaTyhjaSarja($pdo, $kilpailuId, '', $toinen);
        }
    }

    iltarastiSiivoaHaamuSarjat($pdo, $kilpailuId);

    if (function_exists('radatTaytaSarjanMatka')) {
        radatTaytaSarjanMatka($pdo, $kilpailuId, true);
    }
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailuId);
    }
}

function iltarastiPaatosSallittu(string $kirjain): bool
{
    $k = mb_strtoupper(mb_substr(trim($kirjain), 0, 1), 'UTF-8');

    return in_array($k, ['T', 'H', 'K'], true);
}

/** @return array<string,string> */
function iltarastiTulosKirjaimet(): array
{
    return [
        '-' => 'Maastossa',
        'T' => 'Hyväksytty',
        'E' => 'Esitys',
        'H' => 'Hylätty',
        'K' => 'Keskeyttänyt',
    ];
}

function iltarastiTulosKirjain(array $rivi): string
{
    if (iltarastiOnMaastossa($rivi)) {
        return '-';
    }
    $k = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');

    return array_key_exists($k, iltarastiTulosKirjaimet()) ? $k : '-';
}

function iltarastiTallennaOsanottajaTulos(
    PDO $pdo,
    array $kilpailu,
    array $rivi,
    string $kirjain,
    ?string $aikaTeksti
): array {
    if (!kisaOnIltarasti($kilpailu)) {
        throw new RuntimeException('Tulos on vain iltarasteille.');
    }
    $k = mb_strtoupper(mb_substr(trim($kirjain), 0, 1), 'UTF-8');
    if ($k === '') {
        $k = '-';
    }
    if (!array_key_exists($k, iltarastiTulosKirjaimet())) {
        throw new RuntimeException('Valitse tulos.');
    }
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1) {
        throw new RuntimeException('Henkilö puuttuu.');
    }
    $status = iltarastiStatusKeskhyl($k);
    $pdo->prepare('UPDATE kilpailijat SET keskhyl = ?, status = ?, lasna = 1 WHERE id = ? AND kilpailu_id = ?')
        ->execute([$k, $status, $kid, (int) $kilpailu['id']]);
    $aikaSec = null;
    if (!function_exists('leimaParseAikaTeksti')) {
        require_once __DIR__ . '/leimantarkastus.php';
    }
    $aikaTeksti = $aikaTeksti !== null ? trim($aikaTeksti) : '';
    if ($k === '-') {
        try {
            $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id = ?')->execute([$kid]);
        } catch (Throwable $e) {
        }
        $aikaSec = 0;
    } elseif ($aikaTeksti !== '') {
        $sec = leimaParseAikaTeksti($aikaTeksti);
        if ($sec === null) {
            throw new RuntimeException('Aika ei ole kelvollinen (esim. 45:12).');
        }
        $aikaSec = $sec;
        if ($sec > 0) {
            $sarjaId = (int) ($rivi['sarja_id'] ?? 0);
            if (function_exists('tallennaKilpailijanAjat')) {
                tallennaKilpailijanAjat($pdo, $kid, $sarjaId, null, null, $sec, false, false, true, false);
            } elseif (function_exists('jahBridgeTallennaAika')) {
                jahBridgeTallennaAika($pdo, $kid, $sec, null, 'iltarasti-muokkaus', null, null);
            } else {
                $chk = $pdo->prepare('SELECT id FROM ajat WHERE kilpailija_id = ?');
                $chk->execute([$kid]);
                if ($chk->fetch()) {
                    $pdo->prepare('UPDATE ajat SET aika_sec = ? WHERE kilpailija_id = ?')->execute([$sec, $kid]);
                } else {
                    $pdo->prepare('INSERT INTO ajat (kilpailija_id, aika_sec) VALUES (?, ?)')->execute([$kid, $sec]);
                }
            }
        }
    } elseif (in_array($k, ['T', 'E'], true)) {
        $aikaSec = iltarastiTallennaAikaKannasta($pdo, $kilpailu, $rivi);
    }
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }

    return [
        'ok' => true,
        'keskhyl' => $k,
        'status' => $status,
        'aika_sec' => $aikaSec,
        'message' => 'Tulos: ' . iltarastiTulosTila(['keskhyl' => $k])
            . ($aikaSec ? ' · ' . (function_exists('formatAikaSec') ? formatAikaSec($aikaSec) : $aikaSec . ' s') : '') . '.',
    ];
}

function iltarastiAsetaPaatos(PDO $pdo, array $kilpailu, array $rivi, string $paatos): array
{
    if (!kisaOnIltarasti($kilpailu)) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Päätös on vain iltarasteille.'];
    }
    $k = mb_strtoupper(mb_substr(trim($paatos), 0, 1), 'UTF-8');
    if (!iltarastiPaatosSallittu($k)) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Valitse hyväksytty, hylätty tai keskeyttänyt.'];
    }
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Kilpailija puuttuu.'];
    }
    $status = iltarastiStatusKeskhyl($k);
    $pdo->prepare('UPDATE kilpailijat SET keskhyl = ?, status = ?, lasna = 1 WHERE id = ? AND kilpailu_id = ?')
        ->execute([$k, $status, $kid, (int) $kilpailu['id']]);
    $aikaSec = null;
    if ($k === 'T') {
        $aikaSec = iltarastiTallennaAikaKannasta($pdo, $kilpailu, $rivi);
    }
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }

    return [
        'ok' => true,
        'taso' => 'ok',
        'message' => 'Tila: ' . iltarastiKeskhylLabel($k) . '.'
            . ($aikaSec ? ' Aika ' . (function_exists('formatAikaSec') ? formatAikaSec($aikaSec) : $aikaSec . ' s') . '.' : ''),
        'keskhyl' => $k,
        'status' => $status,
        'esitys' => false,
        'aika_sec' => $aikaSec,
        'aika_txt' => $aikaSec && function_exists('formatAikaSec') ? formatAikaSec($aikaSec) : '',
    ];
}

function iltarastiVaihdaRata(PDO $pdo, array $kilpailu, array $rivi, int $sarjaId): array
{
    if (!kisaOnIltarasti($kilpailu)) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Radan vaihto on vain iltarasteille.'];
    }
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1 || $sarjaId < 1) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Valitse rata.'];
    }
    $st = $pdo->prepare('SELECT id, nimi FROM sarjat WHERE id = ? AND kilpailu_id = ? LIMIT 1');
    $st->execute([$sarjaId, (int) $kilpailu['id']]);
    $sarja = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sarja) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Rataa ei löydy.'];
    }
    $pdo->prepare('UPDATE kilpailijat SET sarja_id = ? WHERE id = ? AND kilpailu_id = ?')
        ->execute([$sarjaId, $kid, (int) $kilpailu['id']]);
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }

    return [
        'ok' => true,
        'taso' => 'ok',
        'message' => 'Rata vaihdettu: ' . $sarja['nimi'] . '.',
        'sarja_id' => $sarjaId,
        'sarja' => (string) $sarja['nimi'],
    ];
}

/**
 * Päivitä yhden henkilön väliajat ja (T/E) leimatarkistus nykyisen radan mukaan.
 *
 * @return array{ok:bool,tarkistettu:bool,valiajat:bool,message:string}
 */
function iltarastiTarkistaKilpailijaUudelleen(PDO $pdo, array $kilpailu, array $rivi): array
{
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1) {
        return ['ok' => false, 'tarkistettu' => false, 'valiajat' => false, 'message' => 'Henkilö puuttuu.'];
    }
    if (!function_exists('emitLukijaHaeLeimatKannasta')) {
        require_once __DIR__ . '/emit_lukija.php';
    }
    if (!function_exists('leimaTarkistaKilpailija')) {
        require_once __DIR__ . '/leimantarkastus.php';
    }
    $st = $pdo->prepare('
        SELECT k.*, s.nimi AS sarja, s.rata_nimi
        FROM kilpailijat k
        LEFT JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.id = ?
    ');
    $st->execute([$kid]);
    $taysi = $st->fetch(PDO::FETCH_ASSOC);
    if ($taysi) {
        $rivi = $taysi;
    }
    if (function_exists('pirilaTyhjennaSarjaRastitCache')) {
        pirilaTyhjennaSarjaRastitCache();
    }
    $leimat = emitLukijaHaeLeimatKannasta($pdo, $kid);
    if ($leimat === []) {
        return ['ok' => true, 'tarkistettu' => false, 'valiajat' => false, 'message' => 'Ei tallennettuja leimoja.'];
    }
    $rivi['id'] = $kid;
    if (function_exists('emitLukijaKirjoitaValiajatLeimoista')) {
        emitLukijaKirjoitaValiajatLeimoista($pdo, $rivi, $leimat);
    }
    $kesk = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');
    if (in_array($kesk, ['H', 'K'], true)) {
        if (function_exists('rastilippuJonota')) {
            rastilippuJonota($pdo, $kilpailu);
        }

        return ['ok' => true, 'tarkistettu' => false, 'valiajat' => true, 'message' => 'Väliajat päivitetty radan mukaan.'];
    }
    $tarkistettu = false;
    if (function_exists('leimaTarkistaKilpailija')) {
        try {
            leimaTarkistaKilpailija($pdo, $kilpailu, $rivi, $leimat, null, null, true, 'iltarasti-tark');
            $tarkistettu = true;
        } catch (Throwable $e) {
            $tarkistettu = false;
        }
    }
    $varm = iltarastiVarmistaEiMaastossaLeimoilla($pdo, $kilpailu, $rivi, $leimat);
    if (!empty($varm['kirjoitettu'])) {
        iltarastiTallennaAikaKannasta($pdo, $kilpailu, $rivi);
        if (function_exists('rastilippuJonota')) {
            rastilippuJonota($pdo, $kilpailu);
        }

        return [
            'ok' => true,
            'tarkistettu' => true,
            'valiajat' => true,
            'message' => (string) ($varm['message'] ?? 'Esitys. Leimat luettu.'),
        ];
    }
    if ($tarkistettu) {
        $stK = $pdo->prepare('SELECT keskhyl FROM kilpailijat WHERE id = ?');
        $stK->execute([$kid]);
        $keskNyt = mb_strtoupper(mb_substr(trim((string) $stK->fetchColumn()), 0, 1), 'UTF-8');
        if (in_array($keskNyt, ['T', 'E'], true)) {
            iltarastiTallennaAikaKannasta($pdo, $kilpailu, $rivi);
        }
        if (function_exists('rastilippuJonota')) {
            rastilippuJonota($pdo, $kilpailu);
        }

        return ['ok' => true, 'tarkistettu' => true, 'valiajat' => true, 'message' => 'Leimat ja väliajat tarkistettu uudelleen.'];
    }
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }

    return ['ok' => true, 'tarkistettu' => false, 'valiajat' => true, 'message' => 'Väliajat päivitetty radan mukaan.'];
}

/**
 * Iltarastissa: jos leimat on luettu mutta keskhyl on vielä maastossa, merkitse Esitys.
 *
 * @param list<array<string,mixed>> $leimat
 * @return array{ok:bool,keskhyl?:string,status?:string,esitys?:bool,kirjoitettu?:bool,message?:string}
 */
function iltarastiVarmistaEiMaastossaLeimoilla(PDO $pdo, array $kilpailu, array $rivi, array $leimat = []): array
{
    if (!function_exists('kisaOnIltarasti') || !kisaOnIltarasti($kilpailu)) {
        return ['ok' => false];
    }
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1) {
        return ['ok' => false];
    }
    $st = $pdo->prepare('SELECT * FROM kilpailijat WHERE id = ?');
    $st->execute([$kid]);
    $nyky = $st->fetch(PDO::FETCH_ASSOC) ?: $rivi;
    $kesk = mb_strtoupper(mb_substr(trim((string) ($nyky['keskhyl'] ?? '')), 0, 1), 'UTF-8');
    if (in_array($kesk, ['T', 'E', 'H', 'K'], true)) {
        return ['ok' => true, 'keskhyl' => $kesk, 'esitys' => $kesk === 'E', 'kirjoitettu' => false];
    }
    if ($leimat === []) {
        if (!function_exists('emitLukijaHaeLeimatKannasta')) {
            require_once __DIR__ . '/emit_lukija.php';
        }
        $leimat = emitLukijaHaeLeimatKannasta($pdo, $kid);
    }
    if ($leimat === []) {
        return [
            'ok' => true,
            'keskhyl' => $kesk !== '' ? $kesk : '-',
            'esitys' => false,
            'kirjoitettu' => false,
        ];
    }
    $status = iltarastiStatusKeskhyl('E');
    try {
        $pdo->prepare('UPDATE kilpailijat SET keskhyl = ?, status = ?, lasna = 1 WHERE id = ?')
            ->execute(['E', $status, $kid]);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE kilpailijat SET keskhyl = ?, lasna = 1 WHERE id = ?')
            ->execute(['E', $kid]);
    }

    return [
        'ok' => true,
        'keskhyl' => 'E',
        'status' => $status,
        'esitys' => true,
        'kirjoitettu' => true,
        'message' => 'Esitys. Leimat luettu.',
    ];
}

/**
 * Maastossa-tilassa olevat, joilla on jo leimat: laske T/E.
 */
function iltarastiTarkistaMaastossaJoillaLeimat(PDO $pdo, array $kilpailu): int
{
    $kilpailuId = (int) ($kilpailu['id'] ?? 0);
    if ($kilpailuId < 1) {
        return 0;
    }
    $st = $pdo->prepare("
        SELECT k.*
        FROM kilpailijat k
        WHERE k.kilpailu_id = ?
          AND (k.keskhyl IS NULL OR TRIM(k.keskhyl) = '' OR k.keskhyl = '-')
          AND EXISTS (SELECT 1 FROM emit_leimat e WHERE e.kilpailija_id = k.id)
    ");
    $st->execute([$kilpailuId]);
    $lkm = 0;
    foreach ($st as $rivi) {
        $t = iltarastiTarkistaKilpailijaUudelleen($pdo, $kilpailu, $rivi);
        if (!empty($t['tarkistettu'])) {
            $lkm++;
        }
    }

    return $lkm;
}

/**
 * Radan tallennuksen jälkeen: päivitä sen kilpailijoiden väliajat ja T/E-tarkistus.
 */
function iltarastiTarkistaRadanKilpailijat(PDO $pdo, array $kilpailu, int $rataId): int
{
    $kilpailuId = (int) ($kilpailu['id'] ?? 0);
    if ($kilpailuId < 1 || $rataId < 1) {
        return 0;
    }
    if (!function_exists('pirilaTyhjennaSarjaRastitCache')) {
        require_once __DIR__ . '/pirila_radat.php';
    }
    pirilaTyhjennaSarjaRastitCache();
    $st = $pdo->prepare('SELECT tunnus FROM radat WHERE id = ? AND kilpailu_id = ?');
    $st->execute([$rataId, $kilpailuId]);
    $tunnus = trim((string) $st->fetchColumn());
    if ($tunnus === '') {
        return 0;
    }
    $ss = $pdo->prepare('SELECT id FROM sarjat WHERE kilpailu_id = ? AND rata_nimi = ?');
    $ss->execute([$kilpailuId, $tunnus]);
    $sarjaIdt = array_map('intval', $ss->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($sarjaIdt === []) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($sarjaIdt), '?'));
    $hs = $pdo->prepare("SELECT * FROM kilpailijat WHERE kilpailu_id = ? AND sarja_id IN ($ph)");
    $hs->execute(array_merge([$kilpailuId], $sarjaIdt));
    $lkm = 0;
    foreach ($hs as $rivi) {
        $t = iltarastiTarkistaKilpailijaUudelleen($pdo, $kilpailu, $rivi);
        if (!empty($t['valiajat']) || !empty($t['tarkistettu'])) {
            $lkm++;
        }
    }

    return $lkm;
}

function iltarastiPaivitaSeuranTilat(PDO $pdo, int $seuraId): void
{
    if ($seuraId < 1) {
        return;
    }
    try {
        $tanaan = iltarastiTanaan();
        $pdo->prepare("
            UPDATE kilpailut SET tila = 'tulossa'
            WHERE luonne IN ('iltarasti','suunnistus') AND COALESCE(pirila_synkka,0) = 0
              AND seura_id = ? AND paiva > ?
              AND tila NOT IN ('arkisto')
        ")->execute([$seuraId, $tanaan]);
        $pdo->prepare("
            UPDATE kilpailut SET tila = 'kaynnissa'
            WHERE luonne IN ('iltarasti','suunnistus') AND COALESCE(pirila_synkka,0) = 0
              AND seura_id = ? AND paiva = ?
              AND tila NOT IN ('arkisto')
        ")->execute([$seuraId, $tanaan]);
        $pdo->prepare("
            UPDATE kilpailut SET tila = 'valmis'
            WHERE luonne IN ('iltarasti','suunnistus') AND COALESCE(pirila_synkka,0) = 0
              AND seura_id = ? AND paiva < ?
              AND tila NOT IN ('arkisto')
        ")->execute([$seuraId, $tanaan]);
    } catch (Throwable $e) {
    }
}

function iltarastiLuoPaikallinen(
    PDO $pdo,
    int $seuraId,
    string $nimi,
    ?string $pvm,
    ?string $paikka,
    ?int $omistajaId = null,
    string $tyyppi = 'henkilokohtainen'
): array {
    iltarastiVarmista($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    if (function_exists('tiliSeuraSallii') && !tiliSeuraSallii($pdo, $seuraId, 'iltarastit')) {
        throw new RuntimeException('Iltarastit-palvelu ei ole käytössä tällä seuralla.');
    }
    $tyyppi = $tyyppi === 'viesti' ? 'viesti' : 'henkilokohtainen';
    $nimi = trim($nimi);
    if ($nimi === '') {
        $nimi = $tyyppi === 'viesti' ? 'Viestikilpailu' : 'Suunnistustapahtuma';
    }
    $pvm = iltarastiPvmKannasta($pvm);
    if ($pvm === null) {
        throw new RuntimeException('Anna tapahtuman päivämäärä.');
    }
    $paikka = trim((string) $paikka);
    $tila = iltarastiTilaPaivasta($pvm);
    $token = function_exists('generoiApiToken') ? generoiApiToken() : bin2hex(random_bytes(16));
    $pdo->prepare('
        INSERT INTO kilpailut
            (nimi, paiva, paikka, tila, kisa_tyyppi, api_token, pirila_synkka, paakayttaja_id, seura_id,
             ilmoittautuminen_auki, testikisa, jaettu_jono, luonne, tyyppi, alku_at, loppu_at)
        VALUES (?, ?, ?, ?, \'normaali\', ?, 0, ?, ?, 0, 0, 0, \'suunnistus\', ?, NULL, NULL)
    ')->execute([
        mb_substr($nimi, 0, 255),
        $pvm ?: null,
        $paikka !== '' ? mb_substr($paikka, 0, 255) : null,
        $tila,
        $token,
        $omistajaId && $omistajaId > 0 ? $omistajaId : null,
        $seuraId,
        $tyyppi,
    ]);
    $id = (int) $pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM kilpailut WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Tapahtuman luonti epäonnistui.');
    }

    return $row;
}

/**
 * @return list<array<string,mixed>>
 */
function iltarastiHaeKayttajat(PDO $pdo, int $seuraId): array
{
    if ($seuraId < 1) {
        return [];
    }
    $st = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.aktiivinen, u.role, j.rooli AS jasen_rooli
        FROM users u
        INNER JOIN seura_jasenet j ON j.user_id = u.id AND j.seura_id = ?
        WHERE (u.role = 'iltarasti' OR j.palvelut = 'iltarastit')
          AND j.tila <> 'poistettu'
        ORDER BY FIELD(j.rooli, 'omistaja', 'admin', 'jasen'), u.username
    ");
    $st->execute([$seuraId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function iltarastiHaeSeuranKayttaja(PDO $pdo, int $seuraId, int $userId): ?array
{
    if ($seuraId < 1 || $userId < 1) {
        return null;
    }
    $st = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.aktiivinen, u.role, j.rooli AS jasen_rooli
        FROM users u
        INNER JOIN seura_jasenet j ON j.user_id = u.id AND j.seura_id = ?
        WHERE u.id = ?
          AND (u.role = 'iltarasti' OR j.palvelut = 'iltarastit')
          AND j.tila <> 'poistettu'
        LIMIT 1
    ");
    $st->execute([$seuraId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function iltarastiVoiMuokataKayttajaa(PDO $pdo, int $seuraId, int $kohdeId): bool
{
    if (!iltarastiOnPaakayttaja()) {
        return false;
    }
    $kohde = iltarastiHaeSeuranKayttaja($pdo, $seuraId, $kohdeId);
    if (!$kohde) {
        return false;
    }
    if (($kohde['role'] ?? '') === 'admin' && !(function_exists('onAdmin') && onAdmin())) {
        return false;
    }
    if (($kohde['jasen_rooli'] ?? '') === 'omistaja'
        && iltarastiSeuraRooli() !== 'omistaja'
        && !(function_exists('onAdmin') && onAdmin())
    ) {
        return false;
    }

    return true;
}

function iltarastiLuoKayttaja(
    PDO $pdo,
    int $seuraId,
    string $uname,
    string $salasana,
    ?string $email,
    string $taso,
    ?int $parentId
): int {
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu. Anna seuran nimi ennen käyttäjien lisäämistä.');
    }
    $uname = trim($uname);
    if ($uname === '' || strlen($uname) < 3) {
        throw new RuntimeException('Käyttäjätunnuksen tulee olla vähintään 3 merkkiä.');
    }
    if (function_exists('tiliValidoiSahkoposti')) {
        $email = tiliValidoiSahkoposti($pdo, $email, null, $seuraId);
    } elseif (trim((string) $email) === '') {
        throw new RuntimeException('Sähköposti on pakollinen.');
    }
    if (function_exists('tiliValidoiSalasana')) {
        tiliValidoiSalasana($salasana);
    } elseif (strlen($salasana) < 8) {
        throw new RuntimeException('Salasanan tulee olla vähintään 8 merkkiä.');
    }
    $jasenRooli = $taso === 'paakayttaja' ? 'admin' : 'jasen';
    if (function_exists('tiliHaeKayttajaTunnuksella') && tiliHaeKayttajaTunnuksella($pdo, $uname)) {
        throw new RuntimeException('Käyttäjätunnus on jo käytössä.');
    }
    if (function_exists('kayttajaRoolitVarmista')) {
        kayttajaRoolitVarmista($pdo);
    }
    $pdo->prepare('
        INSERT INTO users (username, password, role, email, paakayttaja_id)
        VALUES (?, ?, \'iltarasti\', ?, ?)
    ')->execute([
        $uname,
        password_hash($salasana, PASSWORD_DEFAULT),
        $email,
        $parentId && $parentId > 0 ? $parentId : null,
    ]);
    $uid = (int) $pdo->lastInsertId();
    if (function_exists('tiliAsetaKayttajanSeura')) {
        tiliAsetaKayttajanSeura($pdo, $uid, $seuraId, $jasenRooli, 'iltarastit');
    }

    return $uid;
}

function iltarastiPoistaKayttaja(PDO $pdo, int $seuraId, int $userId): void
{
    if ($seuraId < 1 || $userId < 1) {
        throw new RuntimeException('Käyttäjä puuttuu.');
    }
    if (!iltarastiVoiMuokataKayttajaa($pdo, $seuraId, $userId)) {
        throw new RuntimeException('Ei oikeutta poistaa tätä tunnusta.');
    }
    $oma = function_exists('kayttajaId') ? (int) (kayttajaId() ?? 0) : 0;
    if ($oma > 0 && $oma === $userId) {
        throw new RuntimeException('Et voi poistaa omaa tunnustasi.');
    }
    $pdo->prepare("
        UPDATE seura_jasenet SET tila = 'poistettu'
        WHERE seura_id = ? AND user_id = ? AND rooli <> 'omistaja'
    ")->execute([$seuraId, $userId]);
    try {
        $pdo->prepare('UPDATE users SET aktiivinen = 0 WHERE id = ? AND role = \'iltarasti\'')
            ->execute([$userId]);
    } catch (Throwable $e) {
    }
}

function iltarastiVaihdaSalasana(PDO $pdo, int $seuraId, int $userId, string $salasana): void
{
    if ($userId < 1) {
        throw new RuntimeException('Käyttäjä puuttuu.');
    }
    if (!iltarastiVoiMuokataKayttajaa($pdo, $seuraId, $userId)) {
        throw new RuntimeException('Ei oikeutta vaihtaa tämän tunnuksen salasanaa.');
    }
    if (function_exists('tiliValidoiSalasana')) {
        tiliValidoiSalasana($salasana);
    } elseif (strlen($salasana) < 8) {
        throw new RuntimeException('Salasanan tulee olla vähintään 8 merkkiä.');
    }
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($salasana, PASSWORD_DEFAULT), $userId]);
}

function iltarastiTallennaAikaKannasta(PDO $pdo, array $kilpailu, array $rivi): ?int
{
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1) {
        return null;
    }
    if (!function_exists('leimaTarkistaKilpailija')) {
        require_once __DIR__ . '/leimantarkastus.php';
    }
    if (!function_exists('emitLukijaHaeLeimatKannasta')) {
        require_once __DIR__ . '/emit_lukija.php';
    }
    $leimat = emitLukijaHaeLeimatKannasta($pdo, $kid);
    if ($leimat === []) {
        return null;
    }
    $henkilo = $rivi;
    $henkilo['id'] = $kid;
    $tulos = leimaTarkistaKilpailija(
        $pdo,
        $kilpailu,
        $henkilo,
        $leimat,
        null,
        null,
        false,
        'iltarasti-paatos'
    );
    $sec = isset($tulos['aika_sec']) ? (int) $tulos['aika_sec'] : 0;
    if ($sec <= 0 && function_exists('leimaHaeMaaliLeima') && function_exists('leimaTarkistusPuhdista')) {
        $puhd = leimaTarkistusPuhdista($leimat);
        $rata = function_exists('leimaHaeRataKilpailijalle')
            ? leimaHaeRataKilpailijalle($pdo, (int) ($kilpailu['id'] ?? 0), $henkilo)
            : null;
        $maali = leimaHaeMaaliLeima(is_array($rata['koodit'] ?? null) ? $rata['koodit'] : [], $puhd);
        if ($maali) {
            $sec = (int) ($maali['kello_sec'] ?? $maali['aika_sec'] ?? 0);
        }
    }
    if ($sec <= 0) {
        return null;
    }
    $lahtoAt = !empty($tulos['lahto_at']) ? (string) $tulos['lahto_at'] : null;
    if (function_exists('leimaTallennaAikaRivi')) {
        leimaTallennaAikaRivi($pdo, $kid, $sec, 'iltarasti-paatos', $lahtoAt);
    } elseif (function_exists('jahBridgeTallennaAika')) {
        jahBridgeTallennaAika($pdo, $kid, $sec, null, 'iltarasti-paatos', $lahtoAt, null);
    } else {
        $sarjaId = (int) ($rivi['sarja_id'] ?? 0);
        if (function_exists('tallennaKilpailijanAjat')) {
            tallennaKilpailijanAjat($pdo, $kid, $sarjaId, null, null, $sec, false, false, true, false);
        }
    }

    return $sec;
}

/**
 * @return list<array<string,mixed>>
 */
function iltarastiHaeNimella(PDO $pdo, int $kilpailuId, int $sarjaId, string $nimi, int $emit = 0): array
{
    if ($kilpailuId < 1) {
        return [];
    }
    if ($emit > 0 && function_exists('emitLukijaHaeEmit')) {
        $r = emitLukijaHaeEmit($pdo, $kilpailuId, $emit, true);
        if ($r) {
            return [$r];
        }
    }
    $nimi = trim($nimi);
    if ($nimi === '') {
        return [];
    }
    $like = '%' . mb_strtolower($nimi, 'UTF-8') . '%';
    $sql = '
        SELECT k.id, k.numero, k.nimi, k.etunimi, k.sukunimi, k.seura, k.status,
               k.lasna, k.keskhyl, k.sarja_id, k.osuus, k.emit_koodi, k.rata,
               k.pirila_lahto_at, s.nimi AS sarja, s.rata_nimi
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.kilpailu_id = ?
          AND (
            LOWER(CONCAT(COALESCE(k.sukunimi, \'\'), \' \', COALESCE(k.etunimi, \'\'))) LIKE ?
            OR LOWER(COALESCE(k.nimi, \'\')) LIKE ?
            OR LOWER(COALESCE(k.sukunimi, \'\')) LIKE ?
          )
    ';
    $params = [$kilpailuId, $like, $like, $like];
    if ($sarjaId > 0) {
        $sql .= ' AND k.sarja_id = ?';
        $params[] = $sarjaId;
    }
    $sql .= ' ORDER BY k.sukunimi, k.etunimi, k.id LIMIT 20';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function iltarastiNimetSyotteesta(string $etu, string $suku): array
{
    $etu = trim($etu);
    $suku = trim($suku);
    if ($suku === '') {
        throw new RuntimeException('Anna vähintään sukunimi.');
    }
    if (function_exists('normalisoiKilpailijaNimet')) {
        return normalisoiKilpailijaNimet($etu, $suku);
    }
    $nimi = trim($suku . ' ' . $etu);

    return ['etunimi' => $etu, 'sukunimi' => $suku, 'nimi' => $nimi];
}

function iltarastiVarmistaHenkilo(
    PDO $pdo,
    array $kilpailu,
    int $sarjaId,
    string $etu,
    string $suku,
    string $seura,
    int $emit = 0,
    int $olemassaId = 0
): array {
    $kilpailuId = (int) ($kilpailu['id'] ?? 0);
    if ($olemassaId > 0) {
        $st = $pdo->prepare('
            SELECT k.*, s.nimi AS sarja
            FROM kilpailijat k
            JOIN sarjat s ON s.id = k.sarja_id
            WHERE k.id = ? AND k.kilpailu_id = ?
        ');
        $st->execute([$olemassaId, $kilpailuId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Henkilöä ei löydy.');
        }

        return $row;
    }
    if ($emit > 0 && function_exists('emitLukijaHaeEmit')) {
        $r = emitLukijaHaeEmit($pdo, $kilpailuId, $emit, true);
        if ($r) {
            return $r;
        }
    }
    $nimet = iltarastiNimetSyotteesta($etu, $suku);
    $osumat = iltarastiHaeNimella($pdo, $kilpailuId, $sarjaId, trim($nimet['sukunimi'] . ' ' . $nimet['etunimi']), $emit);
    if (count($osumat) === 1) {
        return $osumat[0];
    }
    if (count($osumat) > 1) {
        throw new RuntimeException('Useita osumia nimellä. Täsmennä nimeä tai valitse listasta.');
    }
    if ($sarjaId < 1) {
        throw new RuntimeException('Valitse rata.');
    }
    $numero = function_exists('emitLukijaSeuraavaNumero')
        ? emitLukijaSeuraavaNumero($pdo, $kilpailuId)
        : (function_exists('seuraavaKilpailijaNumero') ? seuraavaKilpailijaNumero($pdo, $kilpailuId) : null);
    $pdo->prepare('
        INSERT INTO kilpailijat
            (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi, seura, emit_koodi, status, lasna, keskhyl)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'OK\', 0, NULL)
    ')->execute([
        $kilpailuId,
        $sarjaId,
        $numero,
        $nimet['nimi'],
        $nimet['etunimi'] !== '' ? $nimet['etunimi'] : null,
        $nimet['sukunimi'],
        $seura !== '' ? $seura : null,
        $emit > 0 ? $emit : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    if ($emit > 0 && function_exists('emitHenkilotTallenna')) {
        emitHenkilotTallenna(
            $pdo,
            $emit,
            (string) $nimet['etunimi'],
            (string) $nimet['sukunimi'],
            $seura,
            (int) ($kilpailu['seura_id'] ?? 0),
            function_exists('kayttajaId') ? (int) (kayttajaId() ?? 0) : 0
        );
    }
    $st = $pdo->prepare('
        SELECT k.*, s.nimi AS sarja
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.id = ?
    ');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Henkilön lisäys epäonnistui.');
    }

    return $row;
}

function iltarastiKirjaaMaastoon(
    PDO $pdo,
    array $kilpailu,
    int $sarjaId,
    string $etu,
    string $suku,
    string $seura,
    int $emit = 0,
    int $olemassaId = 0,
    bool $ilmanAikaa = false
): array {
    if (!kisaOnIltarasti($kilpailu)) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Vain iltarasteille.'];
    }
    try {
        $rivi = iltarastiVarmistaHenkilo($pdo, $kilpailu, $sarjaId, $etu, $suku, $seura, $emit, $olemassaId);
    } catch (Throwable $e) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => $e->getMessage()];
    }
    $kid = (int) $rivi['id'];
    $sets = 'lasna = 1, keskhyl = \'-\', status = \'OK\'';
    $params = [];
    if ($sarjaId > 0 && (int) ($rivi['sarja_id'] ?? 0) !== $sarjaId) {
        $sets .= ', sarja_id = ?';
        $params[] = $sarjaId;
    }
    if ($seura !== '') {
        $sets .= ', seura = ?';
        $params[] = $seura;
    }
    if ($emit > 0) {
        $sets .= ', emit_koodi = ?';
        $params[] = $emit;
    }
    $params[] = $kid;
    $pdo->prepare("UPDATE kilpailijat SET $sets WHERE id = ?")->execute($params);
    $st = $pdo->prepare('
        SELECT k.*, s.nimi AS sarja
        FROM kilpailijat k JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.id = ?
    ');
    $st->execute([$kid]);
    $uusi = $st->fetch(PDO::FETCH_ASSOC) ?: $rivi;
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }
    $viesti = 'Maastoon: ' . trim((string) ($uusi['nimi'] ?? '')) . ' / ' . (string) ($uusi['sarja'] ?? '');
    if ($ilmanAikaa) {
        $viesti .= ' (ilman aikaa).';
    } else {
        $viesti .= '.';
    }

    return [
        'ok' => true,
        'taso' => 'ok',
        'message' => $viesti,
        'keskhyl' => '-',
        'status' => 'OK',
        'kilpailija_id' => $kid,
        'nimi' => (string) ($uusi['nimi'] ?? ''),
        'sarja' => (string) ($uusi['sarja'] ?? ''),
        'sarja_id' => (int) ($uusi['sarja_id'] ?? 0),
        'emit' => (int) ($uusi['emit_koodi'] ?? 0),
        'pirila_lahto_at' => (string) ($uusi['pirila_lahto_at'] ?? ''),
        'ilman_aikaa' => $ilmanAikaa,
    ];
}

function iltarastiKirjaaPois(
    PDO $pdo,
    array $kilpailu,
    int $sarjaId,
    string $etu,
    string $suku,
    string $seura,
    int $emit = 0,
    int $olemassaId = 0
): array {
    if (!kisaOnIltarasti($kilpailu)) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Vain iltarasteille.'];
    }
    $nimiHaku = trim($suku . ' ' . $etu);
    if ($olemassaId < 1) {
        $osumat = iltarastiHaeNimella($pdo, (int) $kilpailu['id'], $sarjaId, $nimiHaku, $emit);
        if (count($osumat) > 1) {
            return [
                'ok' => false,
                'taso' => 'varoitus',
                'message' => 'Useita osumia. Valitse henkilö.',
                'osumat' => $osumat,
            ];
        }
        if ($osumat !== []) {
            $olemassaId = (int) $osumat[0]['id'];
        }
    }
    try {
        $rivi = iltarastiVarmistaHenkilo($pdo, $kilpailu, $sarjaId, $etu, $suku, $seura, $emit, $olemassaId);
    } catch (Throwable $e) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => $e->getMessage()];
    }
    $kid = (int) $rivi['id'];
    $pdo->prepare('UPDATE kilpailijat SET lasna = 1, keskhyl = \'T\', status = \'OK\' WHERE id = ?')
        ->execute([$kid]);
    $st = $pdo->prepare('
        SELECT k.*, s.nimi AS sarja
        FROM kilpailijat k JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.id = ?
    ');
    $st->execute([$kid]);
    $uusi = $st->fetch(PDO::FETCH_ASSOC) ?: $rivi;
    if (function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }

    return [
        'ok' => true,
        'taso' => 'ok',
        'message' => 'Pois maastosta: ' . trim((string) ($uusi['nimi'] ?? '')) . ' / ' . (string) ($uusi['sarja'] ?? '') . ' (ilman aikaa).',
        'keskhyl' => 'T',
        'status' => 'OK',
        'kilpailija_id' => $kid,
        'nimi' => (string) ($uusi['nimi'] ?? ''),
        'sarja' => (string) ($uusi['sarja'] ?? ''),
        'sarja_id' => (int) ($uusi['sarja_id'] ?? 0),
        'emit' => (int) ($uusi['emit_koodi'] ?? 0),
        'ilman_aikaa' => true,
        'esitys' => false,
    ];
}
