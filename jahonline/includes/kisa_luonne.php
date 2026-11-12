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

/** Uuden JAHOnline-kisan oletusluonne. Pirilä jättää tyhjäksi (laji tulee synkasta). */
function kisaLuonneOletusUudelle(string $kisaTyyppi = 'normaali'): ?string
{
    if ($kisaTyyppi === 'pirila') {
        return null;
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

function kisaOnIltarasti(array $kilpailu): bool
{
    return kisaLuonneNormalisoi($kilpailu['luonne'] ?? null) === 'iltarasti';
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
