<?php

/**
 * Kahden laitteen maaliajanotto: leimat (nappi) + numerot järjestyksessä.
 * Yhdistäminen tapahtuu sijan mukaan; numeroiden järjestystä voi korjata.
 */

function maaliJonoSarakeOlemassa(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ');
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool) $stmt->fetchColumn();

    return $cache[$key];
}

function maaliJonoVarmistaTaulut(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maali_leimat (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kilpailu_id INT NOT NULL,
            jarjestys INT NOT NULL,
            maaliaika DATETIME NOT NULL,
            kayttaja_id INT NULL,
            laite VARCHAR(100) NULL,
            ajat_id INT NULL,
            luotu TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_maali_leima_jarjestys (kilpailu_id, jarjestys),
            KEY idx_maali_leima_kilpailu (kilpailu_id, maaliaika, id),
            KEY idx_maali_leima_ajat (ajat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maali_jononumerot (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kilpailu_id INT NOT NULL,
            jarjestys INT NOT NULL,
            numero INT NOT NULL,
            kayttaja_id INT NULL,
            laite VARCHAR(100) NULL,
            ajat_id INT NULL,
            luotu TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_maali_jono_jarjestys (kilpailu_id, jarjestys),
            KEY idx_maali_jono_kilpailu (kilpailu_id, jarjestys),
            KEY idx_maali_jono_ajat (ajat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function maaliJonoVaadiKilpailu(PDO $pdo, int $kilpailu_id, string $api_token): array
{
    if ($kilpailu_id < 1) {
        maaliJonoJson(400, ['status' => 'error', 'message' => 'Puuttuu kilpailu_id']);
    }

    $tokenOk = function_exists('varmistaApiToken')
        ? varmistaApiToken($pdo, $kilpailu_id, $api_token)
        : false;
    $sessionOk = isset($_SESSION['user']) && function_exists('onAjanottaja') && onAjanottaja();

    if (!$tokenOk && !$sessionOk) {
        maaliJonoJson(403, ['status' => 'error', 'message' => 'Virheellinen API-avain tai kirjautuminen']);
    }

    $kilpailu = haeKilpailu($pdo, $kilpailu_id);
    if (!$kilpailu) {
        maaliJonoJson(404, ['status' => 'error', 'message' => 'Kilpailua ei löytynyt']);
    }

    if (function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($kilpailu)) {
        maaliJonoJson(403, ['status' => 'error', 'message' => 'Kilpailu käyttää Pirilä-synkkaa. Maalit tulevat Pirilästä.']);
    }

    if ($sessionOk && function_exists('voiAjanottaaKilpailua') && !voiAjanottaaKilpailua($kilpailu) && !$tokenOk) {
        maaliJonoJson(403, ['status' => 'error', 'message' => 'Ei oikeutta tähän kilpailuun']);
    }

    return $kilpailu;
}

function maaliJonoJson(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function maaliJonoAuditLataa(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $f = __DIR__ . '/ajanotto_audit.php';
    if (is_readable($f)) {
        require_once $f;
    }
    $loaded = true;
}

function maaliJonoUudelleennumeroi(PDO $pdo, string $table, int $kilpailu_id): void
{
    $sallitut = ['maali_leimat', 'maali_jononumerot'];
    if (!in_array($table, $sallitut, true)) {
        throw new InvalidArgumentException('Tuntematon taulu');
    }

    $order = $table === 'maali_leimat'
        ? 'maaliaika ASC, id ASC'
        : 'jarjestys ASC, id ASC';

    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE kilpailu_id = ? ORDER BY {$order}");
    $stmt->execute([$kilpailu_id]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $upd = $pdo->prepare("UPDATE {$table} SET jarjestys = ? WHERE id = ?");

    // Vapauta unique (kilpailu_id, jarjestys) väliaikaisilla negatiivisilla arvoilla
    foreach ($ids as $id) {
        $upd->execute([-(int) $id, (int) $id]);
    }
    $i = 1;
    foreach ($ids as $id) {
        $upd->execute([$i, (int) $id]);
        $i++;
    }
}

/**
 * Laske nettoaika maaliajasta (sarjan lähtö). Palauttaa null jos lähtöä ei ole.
 */
function maaliJonoLaskeAikaSec(PDO $pdo, $sarja_id, string $maaliaika): ?int
{
    if (function_exists('laskeAikaSec')) {
        $sec = laskeAikaSec($pdo, $sarja_id, $maaliaika);
        if ($sec !== null) {
            return (int) $sec;
        }
    }
    if (function_exists('haeSarjanLahtoaika')) {
        $lahto = haeSarjanLahtoaika($pdo, (int) $sarja_id);
        if ($lahto) {
            $norm = function_exists('parseDatetimeSec')
                ? (parseDatetimeSec($maaliaika) ?? $maaliaika)
                : $maaliaika;
            $tsMaali = strtotime((string) $norm);
            $tsLahto = strtotime((string) $lahto);
            if ($tsMaali !== false && $tsLahto !== false) {
                $diff = $tsMaali - $tsLahto;

                return $diff >= 0 ? (int) $diff : null;
            }
        }
    }

    return null;
}

/**
 * Yhdistä leimat ja numerot jarjestys-sijan mukaan.
 * Molempien jarjestys säilyy (poisto jättää aukon): näin keskeltä poistettu
 * pari/leima/numero ei siirrä myöhempiä aikoja väärille kilpailijoille.
 *
 * @return array{rivit:array, leimat_lkm:int, numerot_lkm:int, yhdistetty_lkm:int}
 */
function yhdistaMaalijono(PDO $pdo, int $kilpailu_id): array
{
    maaliJonoVarmistaTaulut($pdo);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        $leimaStmt = $pdo->prepare('
            SELECT id, jarjestys, maaliaika, ajat_id, aika_sec
            FROM maali_leimat
            WHERE kilpailu_id = ?
            ORDER BY jarjestys ASC, id ASC
            FOR UPDATE
        ');
        $leimaStmt->execute([$kilpailu_id]);
        $leimat = $leimaStmt->fetchAll(PDO::FETCH_ASSOC);

        $nroStmt = $pdo->prepare('
            SELECT id, jarjestys, numero, ajat_id
            FROM maali_jononumerot
            WHERE kilpailu_id = ?
            ORDER BY jarjestys ASC, id ASC
            FOR UPDATE
        ');
        $nroStmt->execute([$kilpailu_id]);
        $numerot = $nroStmt->fetchAll(PDO::FETCH_ASSOC);

        // EI uudelleennumeroida leimoja eikä numeroita. Tiivis uudelleennumerointi
        // leimoille + aukolliset numerot hajotti parit ja poisti myöhemmät ajat.

        $edellisetAjat = [];
        foreach ($leimat as $l) {
            if (!empty($l['ajat_id'])) {
                $edellisetAjat[(int) $l['ajat_id']] = true;
            }
        }
        foreach ($numerot as $n) {
            if (!empty($n['ajat_id'])) {
                $edellisetAjat[(int) $n['ajat_id']] = true;
            }
        }

        $hasLahde = maaliJonoSarakeOlemassa($pdo, 'ajat', 'lahde');
        $hasStatus = maaliJonoSarakeOlemassa($pdo, 'kilpailijat', 'status');

        $kCols = 'id, numero, nimi, sarja_id';
        if (maaliJonoSarakeOlemassa($pdo, 'kilpailijat', 'etunimi')) {
            $kCols .= ', etunimi, sukunimi';
        }
        if ($hasStatus) {
            $kCols .= ', status';
        }

        $kStmt = $pdo->prepare("SELECT {$kCols} FROM kilpailijat WHERE kilpailu_id = ?");
        $kStmt->execute([$kilpailu_id]);
        $kilpailijat = [];
        foreach ($kStmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $kilpailijat[(int) $k['numero']] = $k;
        }

        $ajatStmt = $pdo->prepare('
            SELECT a.id, a.kilpailija_id, a.maaliaika, a.aika_sec
                ' . ($hasLahde ? ', a.lahde' : '') . '
            FROM ajat a
            JOIN kilpailijat k ON k.id = a.kilpailija_id
            WHERE k.kilpailu_id = ?
            FOR UPDATE
        ');
        $ajatStmt->execute([$kilpailu_id]);
        $ajatByKilpailija = [];
        $ajatById = [];
        foreach ($ajatStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $ajatByKilpailija[(int) $a['kilpailija_id']] = $a;
            $ajatById[(int) $a['id']] = $a;
        }

        $leimatBySija = [];
        foreach ($leimat as $l) {
            $leimatBySija[(int) $l['jarjestys']] = $l;
        }
        $numerotBySija = [];
        foreach ($numerot as $n) {
            $numerotBySija[(int) $n['jarjestys']] = $n;
        }

        $sijat = array_values(array_unique(array_merge(
            array_keys($leimatBySija),
            array_keys($numerotBySija)
        )));
        sort($sijat, SORT_NUMERIC);

        $keepAjat = [];
        $seenNumerot = [];
        $rivit = [];
        $yhdistetty = 0;

        $updLeima = $pdo->prepare('UPDATE maali_leimat SET ajat_id = ?, jarjestys = ? WHERE id = ?');
        $updNro = $pdo->prepare('UPDATE maali_jononumerot SET ajat_id = ? WHERE id = ?');

        foreach ($sijat as $sija) {
            $leima = $leimatBySija[$sija] ?? null;
            $nro = $numerotBySija[$sija] ?? null;
            $rivi = [
                'sija' => $sija,
                'leima_id' => $leima ? (int) $leima['id'] : null,
                'numero_id' => $nro ? (int) $nro['id'] : null,
                'numero' => $nro ? (int) $nro['numero'] : null,
                'nimi' => null,
                'sarja' => null,
                'maaliaika' => $leima ? (string) $leima['maaliaika'] : null,
                'aika_sec' => null,
                'ajat_id' => null,
                'tila' => 'odottaa',
                'viesti' => null,
            ];

            if ($leima && !$nro) {
                $rivi['tila'] = 'odottaa_numeroa';
                $updLeima->execute([null, $sija, (int) $leima['id']]);
                $rivit[] = $rivi;
                continue;
            }

            if (!$leima && $nro) {
                $rivi['tila'] = 'odottaa_aikaa';
                $rivi = maaliJonoTaydennaKilpailija($rivi, $kilpailijat[(int) $nro['numero']] ?? null);
                $updNro->execute([null, (int) $nro['id']]);
                $rivit[] = $rivi;
                continue;
            }

            $numero = (int) $nro['numero'];
            $k = $kilpailijat[$numero] ?? null;
            $rivi = maaliJonoTaydennaKilpailija($rivi, $k);

            if (isset($seenNumerot[$numero])) {
                $rivi['tila'] = 'duplikaatti';
                $rivi['viesti'] = 'Numero on jo jonossa sijalla ' . $seenNumerot[$numero];
                $updLeima->execute([null, $sija, (int) $leima['id']]);
                $updNro->execute([null, (int) $nro['id']]);
                $rivit[] = $rivi;
                continue;
            }
            $seenNumerot[$numero] = $sija;

            if (!$k) {
                $rivi['tila'] = 'ei_loydu';
                $rivi['viesti'] = 'Numeroa ei löydy';
                $updLeima->execute([null, $sija, (int) $leima['id']]);
                $updNro->execute([null, (int) $nro['id']]);
                $rivit[] = $rivi;
                continue;
            }

            $status = $hasStatus ? (string) ($k['status'] ?? 'OK') : 'OK';
            if ($status !== '' && $status !== 'OK') {
                $rivi['tila'] = 'konflikti';
                $rivi['viesti'] = 'Status: ' . $status;
                $updLeima->execute([null, $sija, (int) $leima['id']]);
                $updNro->execute([null, (int) $nro['id']]);
                $rivit[] = $rivi;
                continue;
            }

            $kid = (int) $k['id'];
            $olemassa = $ajatByKilpailija[$kid] ?? null;
            // Älä ylikirjoita mobiili-/hallinta-aikaa. Päivitä vain jos aika on jo
            // linkitetty tähän jonopariin tai merkitty jonosta tulleeksi.
            $linkitettyTahan = $olemassa && (
                (!empty($leima['ajat_id']) && (int) $leima['ajat_id'] === (int) $olemassa['id'])
                || (!empty($nro['ajat_id']) && (int) $nro['ajat_id'] === (int) $olemassa['id'])
            );
            $omaAika = $olemassa && (
                $linkitettyTahan
                || isset($edellisetAjat[(int) $olemassa['id']])
                || ($hasLahde && ($olemassa['lahde'] ?? '') === 'jono')
            );
            // Ulkopuolinen maaliaika (esim. mobiiliruudukko) → konflikti, ei päivitystä
            if ($olemassa && !$linkitettyTahan && !(
                ($hasLahde && ($olemassa['lahde'] ?? '') === 'jono')
                || isset($edellisetAjat[(int) $olemassa['id']])
            )) {
                $rivi['tila'] = 'konflikti';
                $rivi['viesti'] = 'Kilpailijalla on jo maaliaika (ei jonosta)';
                $rivi['ajat_id'] = (int) $olemassa['id'];
                $updLeima->execute([null, $sija, (int) $leima['id']]);
                $updNro->execute([null, (int) $nro['id']]);
                $rivit[] = $rivi;
                continue;
            }

            if ($olemassa && !$omaAika) {
                $rivi['tila'] = 'konflikti';
                $rivi['viesti'] = 'Kilpailijalla on jo maaliaika';
                $rivi['ajat_id'] = (int) $olemassa['id'];
                $updLeima->execute([null, $sija, (int) $leima['id']]);
                $updNro->execute([null, (int) $nro['id']]);
                $rivit[] = $rivi;
                continue;
            }

            $maaliaika = (string) $leima['maaliaika'];
            $aika_sec = isset($leima['aika_sec']) && (int) $leima['aika_sec'] > 0
                ? (int) $leima['aika_sec']
                : maaliJonoLaskeAikaSec($pdo, $k['sarja_id'], $maaliaika);

            if ($omaAika) {
                $ajatId = (int) $olemassa['id'];
                $pdo->prepare('UPDATE ajat SET maaliaika = ?, aika_sec = ? WHERE id = ?')
                    ->execute([$maaliaika, $aika_sec, $ajatId]);
                if ($hasLahde) {
                    $pdo->prepare("UPDATE ajat SET lahde = 'jono' WHERE id = ?")->execute([$ajatId]);
                }
            } else {
                try {
                    if ($hasLahde) {
                        $pdo->prepare('
                            INSERT INTO ajat (kilpailija_id, maaliaika, aika_sec, kayttaja_id, lahde)
                            VALUES (?, ?, ?, ?, ?)
                        ')->execute([
                            $kid,
                            $maaliaika,
                            $aika_sec,
                            function_exists('kayttajaId') ? kayttajaId() : null,
                            'jono',
                        ]);
                    } else {
                        $pdo->prepare('
                            INSERT INTO ajat (kilpailija_id, maaliaika, aika_sec, kayttaja_id)
                            VALUES (?, ?, ?, ?)
                        ')->execute([
                            $kid,
                            $maaliaika,
                            $aika_sec,
                            function_exists('kayttajaId') ? kayttajaId() : null,
                        ]);
                    }
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        $rivi['tila'] = 'konflikti';
                        $rivi['viesti'] = 'Kilpailijalla on jo maaliaika';
                        $updLeima->execute([null, $sija, (int) $leima['id']]);
                        $updNro->execute([null, (int) $nro['id']]);
                        $rivit[] = $rivi;
                        continue;
                    }
                    throw $e;
                }
                $ajatId = (int) $pdo->lastInsertId();
                $ajatByKilpailija[$kid] = [
                    'id' => $ajatId,
                    'kilpailija_id' => $kid,
                    'maaliaika' => $maaliaika,
                    'aika_sec' => $aika_sec,
                    'lahde' => 'jono',
                ];
                $ajatById[$ajatId] = $ajatByKilpailija[$kid];
            }

            $keepAjat[$ajatId] = true;
            $updLeima->execute([$ajatId, $sija, (int) $leima['id']]);
            $updNro->execute([$ajatId, (int) $nro['id']]);

            $rivi['tila'] = 'yhdistetty';
            $rivi['ajat_id'] = $ajatId;
            $rivi['aika_sec'] = $aika_sec;
            $yhdistetty++;
            if ($aika_sec > 0 && $numero > 0) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoMuista($kilpailu_id, $k, $numero, (int) $aika_sec, $ajatId);
            }
            $rivit[] = $rivi;
        }

        // Poista jonosta irronneet ajat (ei enää keepAjat-listalla).
        foreach (array_keys($edellisetAjat) as $vanhaId) {
            if (isset($keepAjat[$vanhaId])) {
                continue;
            }
            if (!isset($ajatById[$vanhaId])) {
                continue;
            }
            if ($hasLahde && ($ajatById[$vanhaId]['lahde'] ?? '') !== 'jono') {
                continue;
            }
            $pdo->prepare('DELETE FROM ajat WHERE id = ?')->execute([$vanhaId]);
        }

        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }

        $maalissaNumerot = [];
        foreach ($ajatByKilpailija as $kid => $_a) {
            foreach ($kilpailijat as $nro => $k) {
                if ((int) ($k['id'] ?? 0) === (int) $kid) {
                    $maalissaNumerot[] = (int) $nro;
                    break;
                }
            }
        }
        sort($maalissaNumerot);

        return [
            'rivit' => $rivit,
            'leimat_lkm' => count($leimat),
            'numerot_lkm' => count($numerot),
            'yhdistetty_lkm' => $yhdistetty,
            'maalissa_numerot' => $maalissaNumerot,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Yhdistää maali-jonon uudelleen kilpailijan lisäyksen/päivityksen jälkeen.
 * Turvallinen kutsua hallinnasta: virheet eivät kaada tallennusta.
 */
function maaliJonoSynkkaaKilpailunJalkeen(PDO $pdo, int $kilpailu_id): array
{
    if ($kilpailu_id <= 0) {
        return ['yhdistetty_lkm' => 0];
    }
    try {
        return yhdistaMaalijono($pdo, $kilpailu_id);
    } catch (Throwable $e) {
        error_log('maaliJonoSynkkaaKilpailunJalkeen: ' . $e->getMessage());
        return [
            'yhdistetty_lkm' => 0,
            'virhe' => $e->getMessage(),
        ];
    }
}

function maaliJonoTaydennaKilpailija(array $rivi, ?array $k): array
{
    if (!$k) {
        return $rivi;
    }
    $rivi['nimi'] = function_exists('kilpailijaNimiRivista')
        ? kilpailijaNimiRivista($k)
        : (string) ($k['nimi'] ?? '');
    $rivi['kilpailija_id'] = (int) $k['id'];

    return $rivi;
}

function maaliJonoLisaaLeima(PDO $pdo, int $kilpailu_id, string $maaliaika, ?string $laite = null): array
{
    maaliJonoVarmistaTaulut($pdo);
    $norm = function_exists('parseDatetimeSec')
        ? (parseDatetimeSec($maaliaika) ?? date('Y-m-d H:i:s'))
        : date('Y-m-d H:i:s');

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('SELECT id FROM maali_leimat WHERE kilpailu_id = ? FOR UPDATE')->execute([$kilpailu_id]);
        $pdo->prepare('SELECT id FROM maali_jononumerot WHERE kilpailu_id = ? FOR UPDATE')->execute([$kilpailu_id]);
        $jarjestys = maaliJonoSeuraavaLeimaJarjestys($pdo, $kilpailu_id);

        $pdo->prepare('
            INSERT INTO maali_leimat (kilpailu_id, jarjestys, maaliaika, kayttaja_id, laite)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $kilpailu_id,
            $jarjestys,
            $norm,
            function_exists('kayttajaId') ? kayttajaId() : null,
            $laite !== null ? substr($laite, 0, 100) : null,
        ]);
        $id = (int) $pdo->lastInsertId();

        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }
        $tila['leima_id'] = $id;
        $tila['maaliaika'] = $norm;
        maaliJonoAuditLataa();
        if (function_exists('ajanottoAuditKirjaa')) {
            ajanottoAuditKirjaa($pdo, $kilpailu_id, 'leima_lisays', [
                'kohde' => 'leima',
                'kohde_id' => $id,
                'uusi' => $norm,
                'lisatieto' => 'jarjestys=' . $jarjestys,
                'laite' => $laite,
            ]);
        }

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function maaliJonoPeruViimeinenLeima(PDO $pdo, int $kilpailu_id): array
{
    maaliJonoVarmistaTaulut($pdo);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('
            SELECT id, maaliaika
            FROM maali_leimat
            WHERE kilpailu_id = ?
            ORDER BY maaliaika DESC, id DESC
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$kilpailu_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $tila = yhdistaMaalijono($pdo, $kilpailu_id);
            if ($ownTx) {
                $pdo->commit();
            }
            $tila['peruttu'] = null;

            return $tila;
        }
        $pdo->prepare('DELETE FROM maali_leimat WHERE id = ?')->execute([(int) $r['id']]);
        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }
        $tila['peruttu'] = [
            'id' => (int) $r['id'],
            'maaliaika' => $r['maaliaika'],
        ];

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Poista yksittäinen aikaleima id:llä (Maalinappi-lista).
 */
function maaliJonoPoistaLeima(PDO $pdo, int $kilpailu_id, int $id): array
{
    maaliJonoVarmistaTaulut($pdo);
    if ($id < 1) {
        throw new InvalidArgumentException('Virheellinen aikaleima');
    }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('
            SELECT id, jarjestys, maaliaika, laite, ajat_id
            FROM maali_leimat
            WHERE id = ? AND kilpailu_id = ?
            FOR UPDATE
        ');
        $stmt->execute([$id, $kilpailu_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new RuntimeException('Aikaleimaa ei löytynyt');
        }
        $pdo->prepare('DELETE FROM maali_leimat WHERE id = ? AND kilpailu_id = ?')
            ->execute([$id, $kilpailu_id]);
        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }
        $tila['peruttu'] = [
            'id' => (int) $r['id'],
            'maaliaika' => $r['maaliaika'],
        ];
        maaliJonoAuditLataa();
        if (function_exists('ajanottoPeruutusTallenna')) {
            ajanottoPeruutusTallenna($pdo, $kilpailu_id, 'leima', [
                'maaliaika' => $r['maaliaika'],
                'jarjestys' => (int) $r['jarjestys'],
                'laite' => $r['laite'] ?? null,
            ]);
        }
        if (function_exists('ajanottoAuditKirjaa')) {
            ajanottoAuditKirjaa($pdo, $kilpailu_id, 'leima_poisto', [
                'kohde' => 'leima',
                'kohde_id' => (int) $r['id'],
                'vanha' => $r['maaliaika'],
                'lisatieto' => 'jarjestys=' . (int) $r['jarjestys'],
            ]);
        }

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Seuraava leiman jarjestys: täytä ensimmäinen aukko (esim. poiston jälkeen),
 * muuten max+1. Sama logiikka kuin numeroilla → parit pysyvät kohdakkain.
 */
function maaliJonoSeuraavaLeimaJarjestys(PDO $pdo, int $kilpailu_id): int
{
    $stmt = $pdo->prepare('SELECT jarjestys FROM maali_leimat WHERE kilpailu_id = ?');
    $stmt->execute([$kilpailu_id]);
    $kaytossa = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $kaytossa[(int) $j] = true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM maali_jononumerot WHERE kilpailu_id = ?');
    $stmt->execute([$kilpailu_id]);
    $nroLkm = (int) $stmt->fetchColumn();

    $maxLeima = $kaytossa === [] ? 0 : max(array_keys($kaytossa));
    $raja = max($nroLkm, $maxLeima);
    for ($i = 1; $i <= $raja; $i++) {
        if (!isset($kaytossa[$i])) {
            return $i;
        }
    }

    return $raja + 1;
}

/**
 * Seuraava numeron jarjestys: täytä ensimmäinen aukko (esim. poiston jälkeen),
 * muuten max+1. Näin uudet numerot asettuvat vapaaseen aikaleimaan.
 */
function maaliJonoSeuraavaNumeroJarjestys(PDO $pdo, int $kilpailu_id): int
{
    $stmt = $pdo->prepare('SELECT jarjestys FROM maali_jononumerot WHERE kilpailu_id = ?');
    $stmt->execute([$kilpailu_id]);
    $kaytossa = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $kaytossa[(int) $j] = true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM maali_leimat WHERE kilpailu_id = ?');
    $stmt->execute([$kilpailu_id]);
    $leimaLkm = (int) $stmt->fetchColumn();

    $maxNro = $kaytossa === [] ? 0 : max(array_keys($kaytossa));
    $raja = max($leimaLkm, $maxNro);
    for ($i = 1; $i <= $raja; $i++) {
        if (!isset($kaytossa[$i])) {
            return $i;
        }
    }

    return $raja + 1;
}

function maaliJonoNumeroListalla(PDO $pdo, int $kilpailu_id, int $numero): bool
{
    if ($kilpailu_id < 1 || $numero < 1) {
        return false;
    }
    $stmt = $pdo->prepare('
        SELECT id FROM kilpailijat
        WHERE kilpailu_id = ? AND numero = ?
        LIMIT 1
    ');
    $stmt->execute([$kilpailu_id, $numero]);

    return (bool) $stmt->fetchColumn();
}

function maaliJonoLisaaNumero(PDO $pdo, int $kilpailu_id, int $numero, ?string $laite = null): array
{
    maaliJonoVarmistaTaulut($pdo);
    if ($numero < 1) {
        throw new InvalidArgumentException('Virheellinen numero');
    }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('SELECT id FROM maali_jononumerot WHERE kilpailu_id = ? FOR UPDATE')->execute([$kilpailu_id]);
        $pdo->prepare('SELECT id FROM maali_leimat WHERE kilpailu_id = ? FOR UPDATE')->execute([$kilpailu_id]);

        if (!maaliJonoNumeroListalla($pdo, $kilpailu_id, $numero)) {
            throw new InvalidArgumentException('Numeroa #' . $numero . ' ei ole kilpailijalistassa');
        }

        // Sama numero ei saa olla jonossa kahdesti
        $dup = $pdo->prepare('
            SELECT id, jarjestys FROM maali_jononumerot
            WHERE kilpailu_id = ? AND numero = ?
            LIMIT 1
        ');
        $dup->execute([$kilpailu_id, $numero]);
        $olemassaJono = $dup->fetch(PDO::FETCH_ASSOC);
        if ($olemassaJono) {
            throw new InvalidArgumentException(
                'Numero #' . $numero . ' on jo jonossa (sija ' . (int) $olemassaJono['jarjestys'] . ')'
            );
        }

        // Jos kilpailijalla on jo maaliaika (esim. mobiiliruudukko), älä lisää jonoon
        $joMaalissa = $pdo->prepare('
            SELECT a.id, a.maaliaika
            FROM ajat a
            JOIN kilpailijat k ON k.id = a.kilpailija_id
            WHERE k.kilpailu_id = ? AND k.numero = ?
            LIMIT 1
        ');
        $joMaalissa->execute([$kilpailu_id, $numero]);
        $aika = $joMaalissa->fetch(PDO::FETCH_ASSOC);
        if ($aika) {
            throw new InvalidArgumentException(
                'Numero #' . $numero . ' on jo maalissa'
                . (!empty($aika['maaliaika']) ? (' (' . substr((string) $aika['maaliaika'], 11, 8) . ')') : '')
            );
        }

        $jarjestys = maaliJonoSeuraavaNumeroJarjestys($pdo, $kilpailu_id);
        $pdo->prepare('
            INSERT INTO maali_jononumerot (kilpailu_id, jarjestys, numero, kayttaja_id, laite)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $kilpailu_id,
            $jarjestys,
            $numero,
            function_exists('kayttajaId') ? kayttajaId() : null,
            $laite !== null ? substr($laite, 0, 100) : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }
        $tila['numero_id'] = $id;
        maaliJonoAuditLataa();
        if (function_exists('ajanottoAuditKirjaa')) {
            ajanottoAuditKirjaa($pdo, $kilpailu_id, 'numero_lisays', [
                'kohde' => 'numero',
                'kohde_id' => $id,
                'numero' => $numero,
                'lisatieto' => 'jarjestys=' . $jarjestys,
                'laite' => $laite,
            ]);
        }

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function maaliJonoPoistaNumero(PDO $pdo, int $kilpailu_id, int $id): array
{
    maaliJonoVarmistaTaulut($pdo);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('
            SELECT id, jarjestys, numero, laite
            FROM maali_jononumerot
            WHERE id = ? AND kilpailu_id = ?
            FOR UPDATE
        ');
        $stmt->execute([$id, $kilpailu_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new RuntimeException('Numeroa ei löytynyt');
        }
        $pdo->prepare('DELETE FROM maali_jononumerot WHERE id = ? AND kilpailu_id = ?')
            ->execute([$id, $kilpailu_id]);
        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }
        maaliJonoAuditLataa();
        if (function_exists('ajanottoPeruutusTallenna')) {
            ajanottoPeruutusTallenna($pdo, $kilpailu_id, 'numero', [
                'numero' => (int) $r['numero'],
                'jarjestys' => (int) $r['jarjestys'],
                'laite' => $r['laite'] ?? null,
            ]);
        }
        if (function_exists('ajanottoAuditKirjaa')) {
            ajanottoAuditKirjaa($pdo, $kilpailu_id, 'numero_poisto', [
                'kohde' => 'numero',
                'kohde_id' => (int) $r['id'],
                'numero' => (int) $r['numero'],
                'vanha' => (string) $r['numero'],
            ]);
        }

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function maaliJonoMuokkaaNumero(PDO $pdo, int $kilpailu_id, int $id, int $numero): array
{
    if ($numero < 1) {
        throw new InvalidArgumentException('Virheellinen numero');
    }
    if (!maaliJonoNumeroListalla($pdo, $kilpailu_id, $numero)) {
        throw new InvalidArgumentException('Numeroa #' . $numero . ' ei ole kilpailijalistassa');
    }
    maaliJonoVarmistaTaulut($pdo);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('
            SELECT id, numero FROM maali_jononumerot
            WHERE id = ? AND kilpailu_id = ?
            FOR UPDATE
        ');
        $stmt->execute([$id, $kilpailu_id]);
        $rivi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rivi) {
            throw new RuntimeException('Riviä ei löytynyt');
        }
        $vanha = (int) $rivi['numero'];
        if ($vanha !== $numero) {
            $dup = $pdo->prepare('
                SELECT id, jarjestys FROM maali_jononumerot
                WHERE kilpailu_id = ? AND numero = ? AND id != ?
                LIMIT 1
            ');
            $dup->execute([$kilpailu_id, $numero, $id]);
            $olemassaJono = $dup->fetch(PDO::FETCH_ASSOC);
            if ($olemassaJono) {
                throw new InvalidArgumentException(
                    'Numero #' . $numero . ' on jo jonossa (sija ' . (int) $olemassaJono['jarjestys'] . ')'
                );
            }
            $joMaalissa = $pdo->prepare('
                SELECT a.id, a.maaliaika
                FROM ajat a
                JOIN kilpailijat k ON k.id = a.kilpailija_id
                WHERE k.kilpailu_id = ? AND k.numero = ?
                LIMIT 1
            ');
            $joMaalissa->execute([$kilpailu_id, $numero]);
            $aika = $joMaalissa->fetch(PDO::FETCH_ASSOC);
            if ($aika) {
                throw new InvalidArgumentException(
                    'Numero #' . $numero . ' on jo maalissa'
                    . (!empty($aika['maaliaika']) ? (' (' . substr((string) $aika['maaliaika'], 11, 8) . ')') : '')
                );
            }
        }
        $pdo->prepare('UPDATE maali_jononumerot SET numero = ? WHERE id = ? AND kilpailu_id = ?')
            ->execute([$numero, $id, $kilpailu_id]);
        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function maaliJonoSiirraNumero(PDO $pdo, int $kilpailu_id, int $id, string $suunta): array
{
    maaliJonoVarmistaTaulut($pdo);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('
            SELECT id, jarjestys
            FROM maali_jononumerot
            WHERE kilpailu_id = ?
            ORDER BY jarjestys ASC, id ASC
            FOR UPDATE
        ');
        $stmt->execute([$kilpailu_id]);
        $rivit = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $idx = null;
        $byJarjestys = [];
        foreach ($rivit as $i => $r) {
            $byJarjestys[(int) $r['jarjestys']] = (int) $r['id'];
            if ((int) $r['id'] === $id) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            throw new RuntimeException('Riviä ei löytynyt');
        }

        $ja = (int) $rivit[$idx]['jarjestys'];
        $upd = $pdo->prepare('UPDATE maali_jononumerot SET jarjestys = ? WHERE id = ?');

        // Siirry ensin viereiseen tyhjään aukkoon (poiston jättämä), älä hyppää toisen numeron päälle.
        if ($suunta === 'ylos' && $ja > 1 && !isset($byJarjestys[$ja - 1])) {
            $upd->execute([$ja - 1, $id]);
        } elseif ($suunta === 'alas' && !isset($byJarjestys[$ja + 1])) {
            $leimaLkmStmt = $pdo->prepare('SELECT COUNT(*) FROM maali_leimat WHERE kilpailu_id = ?');
            $leimaLkmStmt->execute([$kilpailu_id]);
            $leimaLkm = (int) $leimaLkmStmt->fetchColumn();
            if ($ja < max($leimaLkm, $ja + 1)) {
                $upd->execute([$ja + 1, $id]);
            }
        } else {
            $kohde = $suunta === 'alas' ? $idx + 1 : $idx - 1;
            if ($kohde >= 0 && $kohde < count($rivit)) {
                $a = $rivit[$idx];
                $b = $rivit[$kohde];
                $jb = (int) $b['jarjestys'];
                $upd->execute([-$ja, (int) $a['id']]);
                $upd->execute([$ja, (int) $b['id']]);
                $upd->execute([$jb, (int) $a['id']]);
            }
        }

        $tila = yhdistaMaalijono($pdo, $kilpailu_id);
        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }

        return $tila;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Poista kilpailijan maaliaikaan liittyvät leimat ja numerot jonosta.
 * Ei tiivistä muiden numeroiden sijaa (aukko säilyy).
 */
function maaliJonoPoistaKilpailijanMaali(
    PDO $pdo,
    int $kilpailu_id,
    int $kilpailija_id,
    ?int $ajatId = null,
    ?int $numero = null
): void {
    if ($kilpailu_id <= 0) {
        return;
    }
    maaliJonoVarmistaTaulut($pdo);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('SELECT id FROM maali_leimat WHERE kilpailu_id = ? FOR UPDATE')->execute([$kilpailu_id]);
        $pdo->prepare('SELECT id FROM maali_jononumerot WHERE kilpailu_id = ? FOR UPDATE')->execute([$kilpailu_id]);

        $leimaIds = [];
        $nroIds = [];
        $sijat = [];

        if ($ajatId && $ajatId > 0) {
            $st = $pdo->prepare('SELECT id, jarjestys FROM maali_leimat WHERE kilpailu_id = ? AND ajat_id = ?');
            $st->execute([$kilpailu_id, $ajatId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $leimaIds[(int) $r['id']] = true;
                $sijat[(int) $r['jarjestys']] = true;
            }
            $st = $pdo->prepare('SELECT id, jarjestys FROM maali_jononumerot WHERE kilpailu_id = ? AND ajat_id = ?');
            $st->execute([$kilpailu_id, $ajatId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nroIds[(int) $r['id']] = true;
                $sijat[(int) $r['jarjestys']] = true;
            }
        }

        if ($numero !== null && $numero > 0) {
            $st = $pdo->prepare('SELECT id, jarjestys FROM maali_jononumerot WHERE kilpailu_id = ? AND numero = ?');
            $st->execute([$kilpailu_id, $numero]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nroIds[(int) $r['id']] = true;
                $sijat[(int) $r['jarjestys']] = true;
            }
        }

        // Poista myös samalla sijalla oleva pari (leima ↔ numero)
        foreach (array_keys($sijat) as $sija) {
            $st = $pdo->prepare('SELECT id FROM maali_leimat WHERE kilpailu_id = ? AND jarjestys = ?');
            $st->execute([$kilpailu_id, $sija]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $leimaIds[(int) $id] = true;
            }
            $st = $pdo->prepare('SELECT id FROM maali_jononumerot WHERE kilpailu_id = ? AND jarjestys = ?');
            $st->execute([$kilpailu_id, $sija]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $nroIds[(int) $id] = true;
            }
        }

        foreach (array_keys($leimaIds) as $id) {
            $pdo->prepare('DELETE FROM maali_leimat WHERE id = ? AND kilpailu_id = ?')
                ->execute([(int) $id, $kilpailu_id]);
        }
        foreach (array_keys($nroIds) as $id) {
            $pdo->prepare('DELETE FROM maali_jononumerot WHERE id = ? AND kilpailu_id = ?')
                ->execute([(int) $id, $kilpailu_id]);
        }

        // Synkkaa jäljellä olevat parit (numeroita ei tiivistetä)
        yhdistaMaalijono($pdo, $kilpailu_id);

        if ($ownTx) {
            $pdo->commit();
            if (function_exists('jaettuJonoLahetaOdotus') || is_readable(__DIR__ . '/kisa_liput.php')) {
                require_once __DIR__ . '/kisa_liput.php';
                jaettuJonoLahetaOdotus($pdo);
            }
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function maaliJonoTila(PDO $pdo, int $kilpailu_id): array
{
    return maaliJonoTilaVainLuku($pdo, $kilpailu_id);
}

/**
 * Lue jonon tila ilman kirjoituksia (pollaus).
 */
function maaliJonoTilaVainLuku(PDO $pdo, int $kilpailu_id): array
{
    maaliJonoVarmistaTaulut($pdo);

    $leimaStmt = $pdo->prepare('
        SELECT id, jarjestys, maaliaika, ajat_id
        FROM maali_leimat
        WHERE kilpailu_id = ?
        ORDER BY jarjestys ASC, id ASC
    ');
    $leimaStmt->execute([$kilpailu_id]);
    $leimat = $leimaStmt->fetchAll(PDO::FETCH_ASSOC);

    $nroStmt = $pdo->prepare('
        SELECT id, jarjestys, numero, ajat_id
        FROM maali_jononumerot
        WHERE kilpailu_id = ?
        ORDER BY jarjestys ASC, id ASC
    ');
    $nroStmt->execute([$kilpailu_id]);
    $numerot = $nroStmt->fetchAll(PDO::FETCH_ASSOC);

    $hasStatus = maaliJonoSarakeOlemassa($pdo, 'kilpailijat', 'status');
    $kCols = 'id, numero, nimi, sarja_id';
    if (maaliJonoSarakeOlemassa($pdo, 'kilpailijat', 'etunimi')) {
        $kCols .= ', etunimi, sukunimi';
    }
    if ($hasStatus) {
        $kCols .= ', status';
    }

    $kStmt = $pdo->prepare("SELECT {$kCols} FROM kilpailijat WHERE kilpailu_id = ?");
    $kStmt->execute([$kilpailu_id]);
    $kilpailijat = [];
    foreach ($kStmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
        if ($k['numero'] !== null && $k['numero'] !== '') {
            $kilpailijat[(int) $k['numero']] = $k;
        }
    }

    $ajatStmt = $pdo->prepare('
        SELECT a.id, a.kilpailija_id, a.maaliaika, a.aika_sec
        FROM ajat a
        JOIN kilpailijat k ON k.id = a.kilpailija_id
        WHERE k.kilpailu_id = ?
    ');
    $ajatStmt->execute([$kilpailu_id]);
    $ajatByKilpailija = [];
    $ajatById = [];
    foreach ($ajatStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $ajatByKilpailija[(int) $a['kilpailija_id']] = $a;
        $ajatById[(int) $a['id']] = $a;
    }

    $leimatBySija = [];
    foreach ($leimat as $l) {
        $leimatBySija[(int) $l['jarjestys']] = $l;
    }
    $numerotBySija = [];
    foreach ($numerot as $n) {
        $numerotBySija[(int) $n['jarjestys']] = $n;
    }

    $sijat = array_values(array_unique(array_merge(
        array_keys($leimatBySija),
        array_keys($numerotBySija)
    )));
    sort($sijat, SORT_NUMERIC);

    $seenNumerot = [];
    $rivit = [];
    $yhdistetty = 0;

    foreach ($sijat as $sija) {
        $leima = $leimatBySija[$sija] ?? null;
        $nro = $numerotBySija[$sija] ?? null;
        $rivi = [
            'sija' => $sija,
            'leima_id' => $leima ? (int) $leima['id'] : null,
            'numero_id' => $nro ? (int) $nro['id'] : null,
            'numero' => $nro ? (int) $nro['numero'] : null,
            'nimi' => null,
            'sarja' => null,
            'maaliaika' => $leima ? (string) $leima['maaliaika'] : null,
            'aika_sec' => null,
            'ajat_id' => null,
            'tila' => 'odottaa',
            'viesti' => null,
        ];

        if ($leima && !$nro) {
            $rivi['tila'] = 'odottaa_numeroa';
            $rivi['viesti'] = 'Odottaa numeroa';
            if (!empty($leima['ajat_id']) && isset($ajatById[(int) $leima['ajat_id']])) {
                $rivi['ajat_id'] = (int) $leima['ajat_id'];
                $rivi['aika_sec'] = $ajatById[$rivi['ajat_id']]['aika_sec'] ?? null;
            }
            $rivit[] = $rivi;
            continue;
        }

        if (!$leima && $nro) {
            $rivi['tila'] = 'odottaa_aikaa';
            $rivi['viesti'] = 'Odottaa aikaleimaa';
            $rivi = maaliJonoTaydennaKilpailija($rivi, $kilpailijat[(int) $nro['numero']] ?? null);
            if (!empty($nro['ajat_id']) && isset($ajatById[(int) $nro['ajat_id']])) {
                $rivi['ajat_id'] = (int) $nro['ajat_id'];
                $rivi['aika_sec'] = $ajatById[$rivi['ajat_id']]['aika_sec'] ?? null;
                $rivi['maaliaika'] = $ajatById[$rivi['ajat_id']]['maaliaika'] ?? $rivi['maaliaika'];
            }
            $rivit[] = $rivi;
            continue;
        }

        $numero = (int) $nro['numero'];
        $k = $kilpailijat[$numero] ?? null;
        $rivi = maaliJonoTaydennaKilpailija($rivi, $k);

        if (isset($seenNumerot[$numero])) {
            $rivi['tila'] = 'duplikaatti';
            $rivi['viesti'] = 'Numero on jo jonossa sijalla ' . $seenNumerot[$numero];
            $rivit[] = $rivi;
            continue;
        }
        $seenNumerot[$numero] = $sija;

        if (!$k) {
            $rivi['tila'] = 'ei_loydu';
            $rivi['viesti'] = 'Numeroa ei löydy listalta';
            $rivit[] = $rivi;
            continue;
        }

        $status = $hasStatus ? (string) ($k['status'] ?? 'OK') : 'OK';
        if ($status !== '' && $status !== 'OK') {
            $rivi['tila'] = 'konflikti';
            $rivi['viesti'] = 'Status: ' . $status;
            $rivit[] = $rivi;
            continue;
        }

        $kid = (int) $k['id'];
        $ajatId = null;
        if (!empty($leima['ajat_id']) && !empty($nro['ajat_id'])
            && (int) $leima['ajat_id'] === (int) $nro['ajat_id']
        ) {
            $ajatId = (int) $leima['ajat_id'];
        } elseif (!empty($leima['ajat_id']) && isset($ajatById[(int) $leima['ajat_id']])) {
            $ajatId = (int) $leima['ajat_id'];
        } elseif (isset($ajatByKilpailija[$kid])) {
            $ajatId = (int) $ajatByKilpailija[$kid]['id'];
        }

        if ($ajatId && isset($ajatById[$ajatId])) {
            $rivi['tila'] = 'yhdistetty';
            $rivi['ajat_id'] = $ajatId;
            $rivi['aika_sec'] = $ajatById[$ajatId]['aika_sec'] ?? null;
            $rivi['maaliaika'] = $leima
                ? (string) $leima['maaliaika']
                : (string) ($ajatById[$ajatId]['maaliaika'] ?? '');
            $yhdistetty++;
        } elseif (isset($ajatByKilpailija[$kid])
            && (empty($leima['ajat_id']) || (int) ($ajatByKilpailija[$kid]['id'] ?? 0) !== (int) $leima['ajat_id'])
        ) {
            $rivi['tila'] = 'konflikti';
            $rivi['viesti'] = 'Kilpailijalla on jo maaliaika';
            $rivi['ajat_id'] = (int) $ajatByKilpailija[$kid]['id'];
        } else {
            $rivi['tila'] = 'odottaa';
            $rivi['viesti'] = 'Odottaa yhdistämistä';
        }
        $rivit[] = $rivi;
    }

    $peruutukset = [];
    if (is_readable(__DIR__ . '/ajanotto_audit.php')) {
        require_once __DIR__ . '/ajanotto_audit.php';
        if (function_exists('ajanottoPeruutusLista')) {
            $peruutukset = ajanottoPeruutusLista($pdo, $kilpailu_id, 8);
        }
    }

    $maalissaNumerot = [];
    foreach ($ajatByKilpailija as $kid => $a) {
        foreach ($kilpailijat as $nro => $k) {
            if ((int) $k['id'] === (int) $kid) {
                $maalissaNumerot[] = (int) $nro;
                break;
            }
        }
    }
    sort($maalissaNumerot);

    return [
        'rivit' => $rivit,
        'leimat_lkm' => count($leimat),
        'numerot_lkm' => count($numerot),
        'yhdistetty_lkm' => $yhdistetty,
        'peruutukset' => $peruutukset,
        'maalissa_numerot' => $maalissaNumerot,
    ];
}
