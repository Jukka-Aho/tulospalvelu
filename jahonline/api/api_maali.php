<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/pirila_mode.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Virheellinen JSON-data',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $kilpailu_id = (int) ($data['kilpailu_id'] ?? 0);
    $numero = (int) ($data['numero'] ?? 0);
    $api_token = (string) ($data['api_token'] ?? '');
    $maaliaika = (string) ($data['maaliaika'] ?? date('Y-m-d H:i:s'));
    $korvaa = !empty($data['korvaa']) || !empty($data['korvaa_maaliaika']);

    if ($kilpailu_id < 1 || $numero < 1) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Puuttuvat tiedot',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokenOk = varmistaApiToken($pdo, $kilpailu_id, $api_token);
    $sessionOk = isset($_SESSION['user']) && function_exists('onAjanottaja') && onAjanottaja();
    if (!$tokenOk && !$sessionOk) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Virheellinen API-avain tai kirjautuminen',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $kilpailu = haeKilpailu($pdo, $kilpailu_id);
    if (!$kilpailu) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Kilpailua ei löytynyt',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($kilpailu)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Kilpailu käyttää Pirilä-synkkaa. Maalit tulevat Pirilästä.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($sessionOk && function_exists('voiAjanottaaKilpailua') && !voiAjanottaaKilpailua($kilpailu) && !$tokenOk) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Ei oikeutta tähän kilpailuun',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (function_exists('parseDatetimeSec')) {
        $norm = parseDatetimeSec($maaliaika);
        if ($norm !== null) {
            $maaliaika = $norm;
        }
    }

    $stmt = $pdo->prepare('
        SELECT *
        FROM kilpailijat
        WHERE kilpailu_id = ?
          AND numero = ?
        LIMIT 1
    ');
    $stmt->execute([$kilpailu_id, $numero]);
    $kilpailija = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kilpailija) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'code' => 'tuntematon_numero',
            'message' => 'Numeroa #' . $numero . ' ei ole kilpailijalistassa',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT id, maaliaika, aika_sec
        FROM ajat
        WHERE kilpailija_id = ?
        LIMIT 1
    ');
    $stmt->execute([(int) $kilpailija['id']]);
    $olemassa = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($olemassa && !$korvaa) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'code' => 'duplicate',
            'message' => 'Kilpailija on jo maalissa',
            'korvaa_mahdollinen' => true,
            'vanha_maaliaika' => $olemassa['maaliaika'] ?? null,
            'kilpailija' => function_exists('kilpailijaNimiRivista')
                ? kilpailijaNimiRivista($kilpailija)
                : (string) ($kilpailija['nimi'] ?? ''),
            'kilpailija_id' => (int) $kilpailija['id'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $aika_sec = null;
    if (function_exists('laskeAikaSec')) {
        $aika_sec = laskeAikaSec($pdo, $kilpailija['sarja_id'], $maaliaika);
    }

    $laite = isset($data['laite']) ? substr((string) $data['laite'], 0, 100) : null;
    $korvattu = false;

    if ($olemassa && $korvaa) {
        $pdo->prepare('
            UPDATE ajat
            SET maaliaika = ?, aika_sec = ?, kayttaja_id = COALESCE(?, kayttaja_id)
            WHERE kilpailija_id = ?
        ')->execute([
            $maaliaika,
            $aika_sec,
            function_exists('kayttajaId') ? kayttajaId() : null,
            (int) $kilpailija['id'],
        ]);
        $korvattu = true;

        if (is_readable(__DIR__ . '/../includes/ajanotto_audit.php')) {
            require_once __DIR__ . '/../includes/ajanotto_audit.php';
            ajanottoAuditKirjaa($pdo, $kilpailu_id, 'aika_paivitys', [
                'kohde' => 'ajat',
                'kohde_id' => (int) $olemassa['id'],
                'numero' => $numero,
                'kilpailija_id' => (int) $kilpailija['id'],
                'vanha' => $olemassa['maaliaika'] ?? null,
                'uusi' => $maaliaika,
                'lisatieto' => 'korvaa_maaliaika'
                    . ($aika_sec !== null ? ('; aika_sec=' . $aika_sec) : ''),
                'laite' => $laite,
            ]);
        }
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO ajat (kilpailija_id, maaliaika, aika_sec, kayttaja_id)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            (int) $kilpailija['id'],
            $maaliaika,
            $aika_sec,
            function_exists('kayttajaId') ? kayttajaId() : null,
        ]);

        if (is_readable(__DIR__ . '/../includes/ajanotto_audit.php')) {
            require_once __DIR__ . '/../includes/ajanotto_audit.php';
            ajanottoAuditKirjaa($pdo, $kilpailu_id, 'aika_lisays', [
                'kohde' => 'ajat',
                'numero' => $numero,
                'kilpailija_id' => (int) $kilpailija['id'],
                'uusi' => $maaliaika,
                'lisatieto' => $aika_sec !== null ? ('aika_sec=' . $aika_sec) : null,
                'laite' => $laite,
            ]);
        }
    }

    // Poista sama numero jonosta, jotta Numerot-syöttö ei yritä toista maalia
    if (is_readable(__DIR__ . '/../includes/maali_jono.php')) {
        require_once __DIR__ . '/../includes/maali_jono.php';
        try {
            maaliJonoVarmistaTaulut($pdo);
            $nroStmt = $pdo->prepare('
                SELECT id FROM maali_jononumerot
                WHERE kilpailu_id = ? AND numero = ?
            ');
            $nroStmt->execute([$kilpailu_id, $numero]);
            foreach ($nroStmt->fetchAll(PDO::FETCH_COLUMN) as $nroId) {
                $pdo->prepare('DELETE FROM maali_jononumerot WHERE id = ? AND kilpailu_id = ?')
                    ->execute([(int) $nroId, $kilpailu_id]);
            }
            if (function_exists('yhdistaMaalijono')) {
                yhdistaMaalijono($pdo, $kilpailu_id);
            }
        } catch (Throwable $e) {
            // Älä kaada maalin tallennusta jonosiivouksen takia
        }
    }

    http_response_code($korvattu ? 200 : 201);
    echo json_encode([
        'status' => 'ok',
        'message' => $korvattu ? 'Maaliaika korvattu' : 'Maali tallennettu',
        'korvattu' => $korvattu,
        'kilpailija' => function_exists('kilpailijaNimiRivista')
            ? kilpailijaNimiRivista($kilpailija)
            : (string) ($kilpailija['nimi'] ?? ''),
        'kilpailija_id' => (int) $kilpailija['id'],
        'aika_sec' => $aika_sec,
        'maaliaika' => $maaliaika,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tietokantavirhe',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Palvelinvirhe',
    ], JSON_UNESCAPED_UNICODE);
}
