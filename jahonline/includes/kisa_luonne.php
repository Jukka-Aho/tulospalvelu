<?php

/**
 * Kisan luonne (suunnistus, hiihto, juoksu...) ja Pirilä-yhteyksien muisti.
 *
 * Luonne tulee Pirilän kilpailulajista (kilpparam.kilplaji) tai hallinnan lomakkeelta.
 * Se vaikuttaa vain siihen, mitkä valikot näkyvät: sivujen URLit ja API toimivat aina.
 * NULL-luonne = kaikki valikot näkyvät (vanhat kisat).
 */

function kisaLuonneVarmista(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

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

    $lisaa($pdo, 'luonne', 'luonne VARCHAR(20) NULL DEFAULT NULL');
    $lisaa($pdo, 'pirila_laji', 'pirila_laji CHAR(1) NULL DEFAULT NULL');

    $ampuOli = false;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `kilpailut` LIKE 'ampumapaikat'");
        $ampuOli = (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $ampuOli = true;
    }
    $lisaa($pdo, 'ampumapaikat', 'ampumapaikat TINYINT NOT NULL DEFAULT 0');
    if (!$ampuOli) {
        try {
            $pdo->exec("
                UPDATE `kilpailut`
                SET ampumapaikat = 1
                WHERE luonne IN ('ampumasuunnistus','ampumahiihto','sotilas5ottelu','meri5ottelu')
            ");
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS pirila_yhteydet (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kilpailu_id INT NOT NULL,
                kayttaja_id INT NULL,
                lahde VARCHAR(40) NULL,
                tyyppi VARCHAR(10) NULL,
                asema VARCHAR(64) NOT NULL DEFAULT \'\',
                kansio VARCHAR(255) NOT NULL DEFAULT \'\',
                pirila_nimi VARCHAR(255) NULL,
                laji CHAR(1) NULL,
                ensimmainen_at DATETIME NOT NULL,
                viimeksi_at DATETIME NOT NULL,
                UNIQUE KEY uq_pirila_yhteys (kilpailu_id, asema, kansio(190)),
                KEY idx_pirila_yhteys_kilpailu (kilpailu_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } catch (Throwable $e) {
    }
}

/** @return array<string,string> luonne => näyttönimi */
function kisaLuonteet(): array
{
    return [
        'suunnistus' => 'Suunnistus',
        'hiihto' => 'Hiihto',
        'juoksu' => 'Juoksu',
        'ampumahiihto' => 'Ampumahiihto',
        'ampumasuunnistus' => 'Ampumasuunnistus',
        'uinti' => 'Uinti',
        'sotilas5ottelu' => 'Sotilasviisiottelu',
        'meri5ottelu' => 'Meriviisiottelu',
        'iltarasti' => 'Iltarastit',
        'muu' => 'Muu',
    ];
}

function kisaLuonneNormalisoi($arvo): ?string
{
    $v = strtolower(trim((string) ($arvo ?? '')));
    if ($v === '') {
        return null;
    }

    return array_key_exists($v, kisaLuonteet()) ? $v : null;
}

/** Uuden JAHOnline-kisan oletusluonne. Pirilä-suunnistus saa luonteen suunnistus. */
function kisaLuonneOletusUudelle(string $kisaTyyppi = 'normaali'): ?string
{
    if ($kisaTyyppi === 'pirila') {
        return 'suunnistus';
    }

    return 'juoksu';
}

/** Pirilän kilplaji-kirjain -> luonne (sama taulukko kuin ApiLuonneLajista Pirilässä). */
function kisaLuonneLajista(?string $laji): ?string
{
    $k = strtoupper(substr(trim((string) $laji), 0, 1));
    if ($k === '') {
        return null;
    }

    return [
        'S' => 'suunnistus',
        'H' => 'hiihto',
        'J' => 'juoksu',
        'A' => 'ampumahiihto',
        'U' => 'uinti',
        'B' => 'ampumasuunnistus',
        'P' => 'sotilas5ottelu',
        'N' => 'meri5ottelu',
    ][$k] ?? 'muu';
}

function kisaLuonneLabel($luonne): string
{
    $v = kisaLuonneNormalisoi($luonne);

    return $v === null ? '' : kisaLuonteet()[$v];
}

function kisaOnPirila(array $kilpailu): bool
{
    if (function_exists('pirilaSynkkaPaalla')) {
        return pirilaSynkkaPaalla($kilpailu);
    }
    if (array_key_exists('pirila_synkka', $kilpailu) && $kilpailu['pirila_synkka'] !== null && $kilpailu['pirila_synkka'] !== '') {
        return (int) $kilpailu['pirila_synkka'] > 0;
    }

    return ($kilpailu['kisa_tyyppi'] ?? 'normaali') === 'pirila';
}

/**
 * Paikallinen suunnistus (ent. iltarastit): Emit, radat, Rastilippu. Ei Pirilä-synkkaa.
 */
function kisaOnIltarasti(array $kilpailu): bool
{
    if (kisaOnPirila($kilpailu)) {
        return false;
    }
    $luonne = kisaLuonneNormalisoi($kilpailu['luonne'] ?? null);

    return $luonne === 'iltarasti' || $luonne === 'suunnistus';
}

/** SQL-ehto paikalliselle suunnistukselle (alias esim. k tai tyhjä). */
function kisaPaikallinenSuunnistusSql(string $alias = ''): string
{
    $a = $alias === '' ? '' : $alias . '.';

    return "({$a}luonne IN ('iltarasti','suunnistus') AND COALESCE({$a}pirila_synkka,0) = 0"
        . " AND COALESCE({$a}kisa_tyyppi,'normaali') <> 'pirila')";
}

function etusivuKisaTyyppiLabel(array $k): string
{
    if (kisaOnIltarasti($k) || kisaOnPirila($k)) {
        return 'Suunnistus';
    }
    $l = kisaLuonneLabel($k['luonne'] ?? null);

    return $l !== '' ? $l : 'Juoksu';
}

/** @return array<string,string> malli => näyttönimi */
function tapahtumaMallit(): array
{
    return [
        'suunnistus' => 'Suunnistus',
        'juoksu' => 'Juoksu',
        'pirila' => 'Suunnistus-Pirilä',
    ];
}

function tapahtumaMalliKoodi(array $kilpailu): string
{
    if (kisaOnPirila($kilpailu)) {
        return 'pirila';
    }
    if (kisaOnIltarasti($kilpailu)) {
        return 'suunnistus';
    }

    return 'juoksu';
}

function tapahtumaMalliLabel(array $kilpailu): string
{
    $koodi = tapahtumaMalliKoodi($kilpailu);

    return tapahtumaMallit()[$koodi] ?? 'Juoksu';
}

function kisaTapahtumaPvmKannasta(?string $pvm): ?string
{
    if (function_exists('iltarastiPvmKannasta')) {
        $p = iltarastiPvmKannasta((string) $pvm);
        if ($p !== null && $p !== '') {
            return $p;
        }
    }
    $s = trim((string) $pvm);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }

    return kisaPvmIso($s);
}

function kisaLuoJuoksu(
    PDO $pdo,
    int $seuraId,
    string $nimi,
    ?string $pvm,
    ?string $paikka,
    ?int $omistajaId = null
): array {
    kisaLuonneVarmista($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    if (function_exists('tiliSeuraSallii') && !tiliSeuraSallii($pdo, $seuraId, 'kilpailut')) {
        throw new RuntimeException('Juoksu-palvelu ei ole käytössä tällä seuralla.');
    }
    $nimi = trim($nimi);
    if ($nimi === '') {
        throw new RuntimeException('Anna kilpailun nimi.');
    }
    $pvmStr = kisaTapahtumaPvmKannasta($pvm);
    if ($pvmStr === null || $pvmStr === '') {
        throw new RuntimeException('Anna kilpailun päivämäärä.');
    }
    $paikka = trim((string) $paikka);
    $tila = function_exists('iltarastiTilaPaivasta') ? iltarastiTilaPaivasta($pvmStr) : 'tulossa';
    $token = function_exists('generoiApiToken') ? generoiApiToken() : bin2hex(random_bytes(16));
    $pdo->prepare('
        INSERT INTO kilpailut
            (nimi, paiva, paikka, tila, kisa_tyyppi, api_token, pirila_synkka, paakayttaja_id, seura_id,
             ilmoittautuminen_auki, testikisa, jaettu_jono, luonne, tyyppi)
        VALUES (?, ?, ?, ?, \'normaali\', ?, 0, ?, ?, 0, 0, 0, \'juoksu\', \'henkilokohtainen\')
    ')->execute([
        mb_substr($nimi, 0, 255),
        $pvmStr,
        $paikka !== '' ? mb_substr($paikka, 0, 255) : null,
        $tila,
        $token,
        $omistajaId && $omistajaId > 0 ? $omistajaId : null,
        $seuraId,
    ]);

    return kisaHaeLuotu($pdo, (int) $pdo->lastInsertId());
}

function kisaLuoPirila(
    PDO $pdo,
    int $seuraId,
    string $nimi,
    ?string $pvm,
    ?string $paikka,
    ?int $omistajaId = null
): array {
    kisaLuonneVarmista($pdo);
    if ($seuraId < 1) {
        throw new RuntimeException('Seura puuttuu.');
    }
    if (function_exists('tiliSeuraSallii') && !tiliSeuraSallii($pdo, $seuraId, 'pirila')) {
        throw new RuntimeException('Suunnistus-Pirilä ei ole käytössä tällä seuralla.');
    }
    $nimi = trim($nimi);
    if ($nimi === '') {
        $nimi = 'Suunnistus';
    }
    $pvmStr = kisaTapahtumaPvmKannasta($pvm);
    if ($pvmStr === null || $pvmStr === '') {
        throw new RuntimeException('Anna tapahtuman päivämäärä.');
    }
    $paikka = trim((string) $paikka);
    $tila = function_exists('iltarastiTilaPaivasta') ? iltarastiTilaPaivasta($pvmStr) : 'tulossa';
    $token = function_exists('generoiApiToken') ? generoiApiToken() : bin2hex(random_bytes(16));
    $pdo->prepare('
        INSERT INTO kilpailut
            (nimi, paiva, paikka, tila, kisa_tyyppi, api_token, pirila_synkka, paakayttaja_id, seura_id,
             ilmoittautuminen_auki, testikisa, jaettu_jono, luonne, tyyppi)
        VALUES (?, ?, ?, ?, \'pirila\', ?, 1, ?, ?, 0, 0, 0, \'suunnistus\', \'henkilokohtainen\')
    ')->execute([
        mb_substr($nimi, 0, 255),
        $pvmStr,
        $paikka !== '' ? mb_substr($paikka, 0, 255) : null,
        $tila,
        $token,
        $omistajaId && $omistajaId > 0 ? $omistajaId : null,
        $seuraId,
    ]);

    return kisaHaeLuotu($pdo, (int) $pdo->lastInsertId());
}

function kisaHaeLuotu(PDO $pdo, int $id): array
{
    if ($id < 1) {
        throw new RuntimeException('Tapahtuman luonti epäonnistui.');
    }
    $st = $pdo->prepare('SELECT * FROM kilpailut WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Tapahtuman luonti epäonnistui.');
    }

    return $row;
}

function kisaOnJuoksu(array $kilpailu): bool
{
    return kisaLuonneNormalisoi($kilpailu['luonne'] ?? null) === 'juoksu';
}

/**
 * Juoksun TV/online: älä listaa kesken olevia (ei rastiseurantaa).
 * Suunnistuksessa «maastossa» jää. Hallinnan kilpailijalista ei käytä tätä.
 */
function kisaPiilotaMatkalla(array $kilpailu): bool
{
    return kisaOnJuoksu($kilpailu);
}

/**
 * Näytetäänkö valikko tämän kisan luonteella.
 *
 * @param string $valikko ratatiedot | emit | ajanotto | ampuma
 */
function kisaNaytaValikko(array $kilpailu, string $valikko): bool
{
    $luonne = kisaLuonneNormalisoi($kilpailu['luonne'] ?? null);
    if ($luonne === null) {
        return true;
    }

    $saannot = [
        'ratatiedot' => ['suunnistus', 'ampumasuunnistus', 'iltarasti'],
        'emit' => ['suunnistus', 'ampumasuunnistus', 'iltarasti'],
        'ajanotto' => ['hiihto', 'ampumahiihto', 'juoksu', 'uinti', 'sotilas5ottelu', 'meri5ottelu', 'muu'],
        'ampuma' => ['ampumasuunnistus', 'ampumahiihto', 'sotilas5ottelu', 'meri5ottelu'],
    ];
    if (!isset($saannot[$valikko])) {
        return true;
    }

    return in_array($luonne, $saannot[$valikko], true);
}

/** Onko kisaan merkitty ampumapaikat (valikko ja käyttöoikeus). */
function kisaOnAmpumapaikat(array $kilpailu): bool
{
    if (array_key_exists('ampumapaikat', $kilpailu) && $kilpailu['ampumapaikat'] !== null && $kilpailu['ampumapaikat'] !== '') {
        return (int) $kilpailu['ampumapaikat'] > 0;
    }

    return kisaNaytaValikko($kilpailu, 'ampuma');
}

/** Näytetäänkö ampumasakot tuloslistassa. */
function kisaNaytaSakot(array $kilpailu): bool
{
    return kisaOnAmpumapaikat($kilpailu);
}

/** Pirilän päivämäärä (d.m.yyyy tai yyyy-mm-dd) -> yyyy-mm-dd. */
function kisaPvmIso($pvm): ?string
{
    $s = trim((string) ($pvm ?? ''));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        [$y, $kk, $p] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m)) {
        [$p, $kk, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($y < 100) {
            $y += 2000;
        }
    } else {
        return null;
    }

    return checkdate($kk, $p, $y) ? sprintf('%04d-%02d-%02d', $y, $kk, $p) : null;
}

/** Muista Pirilä-aseman yhteys kisaan (asema + kansio yksilöi työaseman). */
function kisaMuistaPirilaYhteys(PDO $pdo, int $kilpailuId, ?int $kayttajaId, array $body): void
{
    kisaLuonneVarmista($pdo);
    $leikkaa = static fn ($v, int $n): string => mb_substr(trim((string) ($v ?? '')), 0, $n);
    $laji = strtoupper($leikkaa($body['laji'] ?? '', 1));

    $pdo->prepare('
        INSERT INTO pirila_yhteydet
            (kilpailu_id, kayttaja_id, lahde, tyyppi, asema, kansio, pirila_nimi, laji, ensimmainen_at, viimeksi_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            kayttaja_id = VALUES(kayttaja_id),
            lahde = VALUES(lahde),
            tyyppi = VALUES(tyyppi),
            pirila_nimi = VALUES(pirila_nimi),
            laji = VALUES(laji),
            viimeksi_at = NOW()
    ')->execute([
        $kilpailuId,
        $kayttajaId,
        $leikkaa($body['lahde'] ?? '', 40) ?: null,
        $leikkaa($body['tyyppi'] ?? '', 10) ?: null,
        $leikkaa($body['asema'] ?? '', 64),
        $leikkaa($body['kansio'] ?? '', 255),
        $leikkaa($body['pirila_nimi'] ?? '', 255) ?: null,
        $laji !== '' ? $laji : null,
    ]);
}

/** @return list<array<string,mixed>> */
function kisaPirilaYhteydet(PDO $pdo, int $kilpailuId): array
{
    kisaLuonneVarmista($pdo);
    try {
        $st = $pdo->prepare('
            SELECT asema, kansio, pirila_nimi, laji, lahde, ensimmainen_at, viimeksi_at
            FROM pirila_yhteydet WHERE kilpailu_id = ? ORDER BY viimeksi_at DESC
        ');
        $st->execute([$kilpailuId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
