<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/layout.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/maksu.php';
maksuVarmista($pdo);
kilpailijaVarmistaLukuAikaSarake($pdo);

if (function_exists('tiliVarmistaTaulut')) {
    tiliVarmistaTaulut($pdo);
}
if (function_exists('tiliLataaKayttajaSessioon')) {
    tiliLataaKayttajaSessioon($pdo);
}
vaadiKisahallinta();

$kilpailu = vaadiValittuKilpailu($pdo);
vaadiKilpailunHallinta($kilpailu);
$kilpailu_id = (int) $kilpailu['id'];
if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)) {
    if (!function_exists('iltarastiOhjaaJulkiseen')) {
        require_once __DIR__ . '/../includes/iltarasti.php';
    }
    iltarastiOhjaaJulkiseen('/public/kilpailijat.php');
    require __DIR__ . '/../includes/iltarasti_henkilot_sivu.php';
    return;
}
if (defined('JAH_ILTARASTI_PUBLIC') && JAH_ILTARASTI_PUBLIC) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: /admin/kilpailijat.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}
$pirila = pirilaSynkkaPaalla($kilpailu);
$muokattava_id = isset($_GET['muokkaa']) ? (int) $_GET['muokkaa'] : null;
$viesti = $_GET['viesti'] ?? '';
$viestiVirhe = !empty($_GET['virhe']);

// Maaliajan muokkaus sallittu myös Pirilä-kisassa (lähtö tulee Pirilästä / sarjan lähdöstä)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ajat') {
    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, sarja_id, pirila_lahto_at FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
    $stmt->execute([$id, $kilpailu_id]);
    $rivi = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rivi) {
        $maaliAt = parseDatetimeSec($_POST['maaliaika'] ?? '');
        $aikaSec = parseAikaSecSyote($_POST['aika_sec_syote'] ?? '');
        $poistaMaali = !empty($_POST['poista_maali']);
        $kaytaKasinetto = !empty($_POST['kayta_kasinetto']);
        $sarjaLahto = haeSarjanLahtoaika($pdo, (int) $rivi['sarja_id']);
        $lahtoAt = $rivi['pirila_lahto_at'] ?: $sarjaLahto;

        tallennaKilpailijanAjat(
            $pdo,
            $id,
            (int) $rivi['sarja_id'],
            $lahtoAt,
            $maaliAt,
            $kaytaKasinetto ? $aikaSec : null,
            $poistaMaali,
            false,
            $kaytaKasinetto,
            $pirila
        );
    }

    header('Location: kilpailijat.php?viesti=' . urlencode('Ajat tallennettu') . '&muokkaa=' . $id);
    exit;
}

// Maksutila voidaan merkitä myös Pirilä-kisassa (jos joskus käytössä)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_maksu') {
    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $tila = $_POST['maksu_tila'] ?? '';
    if (!in_array($tila, ['ei_vaadita', 'odottaa', 'maksettu'], true)) {
        $tila = 'odottaa';
    }
    $stmt = $pdo->prepare('
        UPDATE kilpailijat SET maksu_tila = ?
        WHERE id = ? AND kilpailu_id = ?
    ');
    $stmt->execute([$tila, $id, $kilpailu_id]);
    header('Location: kilpailijat.php?viesti=' . urlencode('Maksutila päivitetty'));
    exit;
}

estaaMuokkausJosPirila($kilpailu);

/**
 * Kun kilpailija lisätään/päivitetään myöhemmin, maali-jonon ei_loydu-rivit
 * pitää yhdistää uudelleen (maalinappi + numerokirjaus ennen kilpailijaa).
 */
function kilpailijatSynkkaaMaaliJono(PDO $pdo, int $kilpailu_id): void
{
    if (!is_readable(__DIR__ . '/../includes/maali_jono.php')) {
        return;
    }
    require_once __DIR__ . '/../includes/maali_jono.php';
    if (function_exists('maaliJonoSynkkaaKilpailunJalkeen')) {
        maaliJonoSynkkaaKilpailunJalkeen($pdo, $kilpailu_id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }

    $action = $_POST['action'] ?? 'create';
    $redirect = 'kilpailijat.php';

    if ($action === 'create') {
        $nimet = normalisoiKilpailijaNimet(
            (string) ($_POST['etunimi'] ?? ''),
            (string) ($_POST['sukunimi'] ?? '')
        );
        $numero = parseKilpailijaNumeroSyote($_POST['numero'] ?? '');
        if ($numero !== null && kilpailijaNumeroVarattu($pdo, $kilpailu_id, $numero)) {
            header('Location: kilpailijat.php?viesti=' . urlencode('Numero #' . $numero . ' on jo käytössä') . '&virhe=1');
            exit;
        }
        try {
            $stmt = $pdo->prepare('
                INSERT INTO kilpailijat
                (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi, seura, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $kilpailu_id,
                $_POST['sarja_id'],
                $numero,
                $nimet['nimi'],
                $nimet['etunimi'],
                $nimet['sukunimi'],
                $_POST['seura'],
                normalisoiKilpailijaStatus($_POST['status'] ?? 'OK'),
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                header('Location: kilpailijat.php?viesti=' . urlencode('Numero on jo käytössä') . '&virhe=1');
                exit;
            }
            throw $e;
        }
        $uusiId = (int) $pdo->lastInsertId();
        $maaliAt = parseDatetimeSec($_POST['maaliaika'] ?? '');
        $aikaSec = parseAikaSecSyote($_POST['aika_sec_syote'] ?? '');
        if ($maaliAt !== null || $aikaSec !== null) {
            $sarjaLahto = haeSarjanLahtoaika($pdo, (int) $_POST['sarja_id']);
            $kaytaKasinetto = !empty($_POST['kayta_kasinetto']);
            tallennaKilpailijanAjat(
                $pdo,
                $uusiId,
                (int) $_POST['sarja_id'],
                $sarjaLahto,
                $maaliAt,
                $kaytaKasinetto ? $aikaSec : null,
                false,
                false,
                $kaytaKasinetto
            );
        }
        kilpailijatSynkkaaMaaliJono($pdo, $kilpailu_id);
        $redirect .= '?viesti=' . urlencode('Kilpailija lisätty');
    }

    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $nimet = normalisoiKilpailijaNimet(
            (string) ($_POST['etunimi'] ?? ''),
            (string) ($_POST['sukunimi'] ?? '')
        );
        $numero = parseKilpailijaNumeroSyote($_POST['numero'] ?? '');
        if ($numero !== null && kilpailijaNumeroVarattu($pdo, $kilpailu_id, $numero, $id)) {
            header('Location: kilpailijat.php?viesti=' . urlencode('Numero #' . $numero . ' on jo käytössä') . '&virhe=1&muokkaa=' . $id);
            exit;
        }
        try {
            $stmt = $pdo->prepare('
                UPDATE kilpailijat
                SET sarja_id = ?, numero = ?, nimi = ?, etunimi = ?, sukunimi = ?, seura = ?, status = ?, maksu_tila = ?
                WHERE id = ? AND kilpailu_id = ?
            ');
            $maksuTila = $_POST['maksu_tila'] ?? 'ei_vaadita';
            if (!in_array($maksuTila, ['ei_vaadita', 'odottaa', 'maksettu'], true)) {
                $maksuTila = 'ei_vaadita';
            }
            $stmt->execute([
                $_POST['sarja_id'],
                $numero,
                $nimet['nimi'],
                $nimet['etunimi'],
                $nimet['sukunimi'],
                $_POST['seura'],
                normalisoiKilpailijaStatus($_POST['status'] ?? 'OK'),
                $maksuTila,
                $id,
                $kilpailu_id,
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                header('Location: kilpailijat.php?viesti=' . urlencode('Numero on jo käytössä') . '&virhe=1&muokkaa=' . $id);
                exit;
            }
            throw $e;
        }

        $maaliAt = parseDatetimeSec($_POST['maaliaika'] ?? '');
        $aikaSec = parseAikaSecSyote($_POST['aika_sec_syote'] ?? '');
        $poistaMaali = !empty($_POST['poista_maali']);
        $kaytaKasinetto = !empty($_POST['kayta_kasinetto']);
        $sarjaLahto = haeSarjanLahtoaika($pdo, (int) $_POST['sarja_id']);

        if ($poistaMaali || $maaliAt !== null || ($kaytaKasinetto && $aikaSec !== null)) {
            tallennaKilpailijanAjat(
                $pdo,
                $id,
                (int) $_POST['sarja_id'],
                $sarjaLahto,
                $maaliAt,
                $kaytaKasinetto ? $aikaSec : null,
                $poistaMaali,
                false,
                $kaytaKasinetto
            );
        } elseif ($sarjaLahto) {
            // Sarja vaihtui → päivitä netto jos maali on
            $ajo = $pdo->prepare('SELECT maaliaika FROM ajat WHERE kilpailija_id = ?');
            $ajo->execute([$id]);
            $vanhaMaali = $ajo->fetchColumn();
            if ($vanhaMaali) {
                $sec = laskeAikaSec($pdo, (int) $_POST['sarja_id'], $vanhaMaali);
                $pdo->prepare('UPDATE ajat SET aika_sec = ? WHERE kilpailija_id = ?')
                    ->execute([$sec, $id]);
            }
        }

        kilpailijatSynkkaaMaaliJono($pdo, $kilpailu_id);
        $redirect .= '?viesti=' . urlencode('Kilpailija tallennettu');
    }

    if ($action === 'set_status') {
        $stmt = $pdo->prepare('
            UPDATE kilpailijat
            SET status = ?
            WHERE id = ? AND kilpailu_id = ?
        ');
        $stmt->execute([
            normalisoiKilpailijaStatus($_POST['status'] ?? 'OK'),
            $_POST['id'],
            $kilpailu_id
        ]);
        kilpailijatSynkkaaMaaliJono($pdo, $kilpailu_id);
    }

    if ($action === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        $meta = $pdo->prepare('
            SELECT k.numero, a.id AS ajat_id
            FROM kilpailijat k
            LEFT JOIN ajat a ON a.kilpailija_id = k.id
            WHERE k.id = ? AND k.kilpailu_id = ?
            LIMIT 1
        ');
        $meta->execute([$delId, $kilpailu_id]);
        $info = $meta->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare('DELETE FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
        $stmt->execute([$delId, $kilpailu_id]);

        if (is_readable(__DIR__ . '/../includes/maali_jono.php')) {
            require_once __DIR__ . '/../includes/maali_jono.php';
            if (function_exists('maaliJonoPoistaKilpailijanMaali')) {
                maaliJonoPoistaKilpailijanMaali(
                    $pdo,
                    $kilpailu_id,
                    $delId,
                    !empty($info['ajat_id']) ? (int) $info['ajat_id'] : null,
                    isset($info['numero']) && $info['numero'] !== null && $info['numero'] !== ''
                        ? (int) $info['numero']
                        : null
                );
            }
        }

        $redirect .= '?viesti=' . urlencode('Kilpailija poistettu');
    }

    if ($action === 'numeroi_puuttuvat') {
        $n = function_exists('numeroiPuuttuvatKilpailijat')
            ? numeroiPuuttuvatKilpailijat($pdo, $kilpailu_id)
            : 0;
        if ($n > 0) {
            kilpailijatSynkkaaMaaliJono($pdo, $kilpailu_id);
        }
        $redirect .= '?viesti=' . urlencode(
            $n > 0
                ? ('Numeroitu ' . $n . ' kilpailijaa.')
                : 'Ei numeroimattomia kilpailijoita.'
        );
    }

    if ($action === 'csv_import' && isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        $tuotu = 0;
        $virheet = 0;

        if ($handle) {

            $sarjaMap = [];
            $stmt = $pdo->prepare('SELECT id, nimi FROM sarjat WHERE kilpailu_id = ?');
            $stmt->execute([$kilpailu_id]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sarjaMap[mb_strtolower(trim($s['nimi']))] = $s['id'];
            }

            while (($row = fgetcsv($handle, 0, ';')) !== false) {

                if (count($row) < 3) {
                    $row = str_getcsv($row[0] ?? '', ',');
                }

                if (count($row) < 3) {
                    $virheet++;
                    continue;
                }

                $numero = (int) trim($row[0]);
                $col1 = trim($row[1] ?? '');
                $col2 = trim($row[2] ?? '');
                // Uusi: numero;etunimi;sukunimi;seura;sarja;status
                // Vanha: numero;nimi;seura;sarja;status
                $onUusi = count($row) >= 5 && isset($sarjaMap[mb_strtolower(trim($row[4] ?? ''))]);
                if ($onUusi) {
                    $etunimi = $col1;
                    $sukunimi = $col2;
                    $seura = trim($row[3] ?? '');
                    $sarjaNimi = mb_strtolower(trim($row[4] ?? ''));
                    $status = normalisoiKilpailijaStatus($row[5] ?? 'OK');
                } else {
                    $jaettu = jaaNimiKenttiin($col1);
                    $etunimi = $jaettu['etunimi'];
                    $sukunimi = $jaettu['sukunimi'];
                    $seura = $col2;
                    $sarjaNimi = mb_strtolower(trim($row[3] ?? ''));
                    $status = normalisoiKilpailijaStatus($row[4] ?? 'OK');
                }

                $nimet = normalisoiKilpailijaNimet($etunimi, $sukunimi);

                if (!$numero || $nimet['nimi'] === 'Nimetön' || !isset($sarjaMap[$sarjaNimi])) {
                    $virheet++;
                    continue;
                }

                try {
                    $insert = $pdo->prepare('
                        INSERT INTO kilpailijat
                        (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi, seura, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $insert->execute([
                        $kilpailu_id,
                        $sarjaMap[$sarjaNimi],
                        $numero,
                        $nimet['nimi'],
                        $nimet['etunimi'],
                        $nimet['sukunimi'],
                        $seura,
                        $status
                    ]);
                    $tuotu++;
                } catch (PDOException $e) {
                    $virheet++;
                }
            }

            fclose($handle);
        }

        if ($tuotu > 0) {
            kilpailijatSynkkaaMaaliJono($pdo, $kilpailu_id);
        }
        $redirect .= '?viesti=' . urlencode("Tuotu $tuotu, virheitä $virheet");
    }

    header('Location: ' . $redirect);
    exit;
}

// Korjaa vanhat orvot: leima+numero ilman kilpailijaa → yhdistä kun kilpailija on jo listalla
if (is_readable(__DIR__ . '/../includes/maali_jono.php')) {
    require_once __DIR__ . '/../includes/maali_jono.php';
    if (function_exists('maaliJonoVarmistaTaulut') && function_exists('maaliJonoSynkkaaKilpailunJalkeen')) {
        try {
            maaliJonoVarmistaTaulut($pdo);
            $orpo = $pdo->prepare('
                SELECT COUNT(*)
                FROM maali_jononumerot n
                INNER JOIN maali_leimat l
                    ON l.kilpailu_id = n.kilpailu_id AND l.jarjestys = n.jarjestys
                WHERE n.kilpailu_id = ?
                  AND n.ajat_id IS NULL
                  AND l.ajat_id IS NULL
            ');
            $orpo->execute([$kilpailu_id]);
            if ((int) $orpo->fetchColumn() > 0) {
                maaliJonoSynkkaaKilpailunJalkeen($pdo, $kilpailu_id);
            }
        } catch (Throwable $e) {
            error_log('kilpailijat maali-jono korjaus: ' . $e->getMessage());
        }
    }
}

$stmt = $pdo->prepare('
    SELECT id, nimi
    FROM sarjat
    WHERE kilpailu_id = ?
    ORDER BY jarjestys, nimi
');
$stmt->execute([$kilpailu_id]);
$sarjat = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT
        kilpailijat.*,
        sarjat.nimi AS sarja,
        lahdot.lahtoaika AS sarja_lahtoaika,
        lahdot.nimi AS lahto_nimi,
        ajat.maaliaika,
        ajat.aika_sec
    FROM kilpailijat
    JOIN sarjat ON kilpailijat.sarja_id = sarjat.id
    LEFT JOIN lahdot ON lahdot.id = sarjat.lahto_id
    LEFT JOIN ajat ON ajat.kilpailija_id = kilpailijat.id
    WHERE kilpailijat.kilpailu_id = ?
    ORDER BY sarjat.jarjestys, sarjat.nimi, kilpailijat.numero IS NULL, kilpailijat.numero, kilpailijat.sukunimi, kilpailijat.etunimi
');
$stmt->execute([$kilpailu_id]);
$kilpailijat = $stmt->fetchAll(PDO::FETCH_ASSOC);

$duplikaattiNumerot = [];
$numeroLaskuri = [];
foreach ($kilpailijat as $k) {
    if ($k['numero'] === null || $k['numero'] === '' || (int) $k['numero'] < 1) {
        continue;
    }
    $n = (int) $k['numero'];
    $numeroLaskuri[$n] = ($numeroLaskuri[$n] ?? 0) + 1;
}
foreach ($numeroLaskuri as $n => $lkm) {
    if ($lkm > 1) {
        $duplikaattiNumerot[$n] = $lkm;
    }
}

$muokattava = null;
$muokattavaLahto = null;
if ($muokattava_id) {
    $stmt = $pdo->prepare('
        SELECT
            k.*,
            a.maaliaika,
            a.aika_sec,
            l.lahtoaika AS sarja_lahtoaika,
            l.nimi AS lahto_nimi
        FROM kilpailijat k
        LEFT JOIN ajat a ON a.kilpailija_id = k.id
        LEFT JOIN sarjat s ON s.id = k.sarja_id
        LEFT JOIN lahdot l ON l.id = s.lahto_id
        WHERE k.id = ? AND k.kilpailu_id = ?
    ');
    $stmt->execute([$muokattava_id, $kilpailu_id]);
    $muokattava = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($muokattava) {
        $muokattavaLahto = $muokattava['sarja_lahtoaika'] ?? null;
    }
}

$nakyma = 'lista';
if ($muokattava) {
    $nakyma = 'muokkaa';
} elseif (!$pirila && isset($_GET['uusi'])) {
    $nakyma = 'uusi';
}

pageStart('Kilpailijat', ['admin' => true, 'active' => 'kilpailijat', 'pdo' => $pdo]);
?>

<h1>Kilpailijat</h1>
<p class="hallinta-takaisin"><a class="btn-secondary" href="index.php">← Kisan hallinta</a></p>
<p class="muted"><?= htmlspecialchars($kilpailu['nimi']) ?></p>

<?php pirilaVirheIlmoitus(); ?>
<?php pirilaTilaIlmoitus($kilpailu); ?>

<?php if ($viesti): ?>
    <p class="notice<?= !empty($viestiVirhe) ? ' notice-varoitus' : '' ?>"><?= htmlspecialchars($viesti) ?></p>
<?php endif; ?>

<?php if ($nakyma === 'lista' && $duplikaattiNumerot !== []): ?>
    <p class="notice notice-varoitus">
        Duplikaattinumeroita: <?= htmlspecialchars(implode(', ', array_map(
            static fn($n) => '#' . $n . ' (' . $duplikaattiNumerot[$n] . '×)',
            array_keys($duplikaattiNumerot)
        ))) ?>. Korjaa ennen ajanottoa.
    </p>
<?php endif; ?>

<?php if ($nakyma === 'muokkaa' && $pirila): ?>
<section class="kisa-lomake-osio is-editing">
    <h2>Muokkaa maaliaikaa · <?= htmlspecialchars(kilpailijaNimiRivista($muokattava)) ?> (#<?= (int) ($muokattava['numero'] ?? 0) ?>)</h2>
    <p class="muted">
        Lähtö tulee Pirilästä / sarjan lähdöstä
        (<?= $muokattavaLahto
            ? htmlspecialchars(substr($muokattavaLahto, 11, 8))
            : (!empty($muokattava['pirila_lahto_at']) ? htmlspecialchars(substr((string) $muokattava['pirila_lahto_at'], 11, 8)) : 'ei asetettu') ?>).
        Nettoaika lasketaan siitä, ellei syötä käsin.
    </p>
    <?php
    $lukuajat = kilpailijaLukuajat($pdo, (int) $muokattava['id']);
    if ($lukuajat !== []):
    ?>
        <p class="muted">Mobiililukijan lukuajat:
            <?php foreach ($lukuajat as $luku): ?>
                <br><?= htmlspecialchars($luku['aika']) ?>
                <?php if ($luku['viesti'] !== ''): ?>
                    · <?= htmlspecialchars($luku['viesti']) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
    <?php
    $emitLeimat = kilpailijaEmitLeimat($pdo, (int) $muokattava['id']);
    if ($emitLeimat !== []):
    ?>
        <p class="muted">Lukijan leimat:
            <?php foreach ($emitLeimat as $leima): ?>
                <br><?= (int) $leima['jarjestys'] ?>. koodi <?= htmlspecialchars($leima['koodi']) ?> · <?= htmlspecialchars($leima['kello']) ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="update_ajat">
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">

        <label>Maaliaika (sekunnin tarkkuus)</label>
        <input type="datetime-local" name="maaliaika" step="1"
               value="<?= htmlspecialchars(datetimeLocalSec($muokattava['maaliaika'] ?? null)) ?>">

        <label>Nettoaika (valinnainen)</label>
        <input type="text" name="aika_sec_syote" placeholder="lasketaan automaattisesti lähdöstä" value="">
        <label class="checkbox-label">
            <input type="checkbox" name="kayta_kasinetto" value="1">
            Käytä käsin syötettyä nettoaikaa (älä laske lähdöstä)
        </label>
        <?php if (isset($muokattava['aika_sec']) && $muokattava['aika_sec'] !== null): ?>
            <p class="muted">Nykyinen netto: <?= htmlspecialchars(formatAikaSec($muokattava['aika_sec'])) ?></p>
        <?php endif; ?>

        <label class="checkbox-label">
            <input type="checkbox" name="poista_maali" value="1" id="poista_maali_pirila">
            Poista maaliaika
        </label>
        <p class="notice notice-varoitus poista-maali-huom">
            Huom: maaliajan poisto poistaa ajan myös <strong>Maalinappi</strong>- ja
            <strong>Numerot</strong>-ikkunoista (aikaleima ja numero).
            Online-tuloksista aika häviää.
        </p>

        <div class="form-actions">
            <button type="submit">Tallenna ajat</button>
            <a class="btn-secondary" href="kilpailijat.php">Takaisin listaan</a>
        </div>
    </form>
</section>

<?php elseif ($nakyma === 'muokkaa' && !$pirila): ?>
<section class="kisa-lomake-osio is-editing">
    <h2>Muokkaa kilpailijaa</h2>
    <p class="muted">
        Lähtöaika määritellään <a href="lahdot.php">Lähdöt</a>-sivulla ja kytketään
        <a href="sarjat.php">sarjaan</a>.
    </p>
    <?php
    $lukuajat = kilpailijaLukuajat($pdo, (int) $muokattava['id']);
    if ($lukuajat !== []):
    ?>
        <p class="muted">Mobiililukijan lukuajat:
            <?php foreach ($lukuajat as $luku): ?>
                <br><?= htmlspecialchars($luku['aika']) ?>
                <?php if ($luku['viesti'] !== ''): ?>
                    · <?= htmlspecialchars($luku['viesti']) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
    <?php
    $emitLeimat = kilpailijaEmitLeimat($pdo, (int) $muokattava['id']);
    if ($emitLeimat !== []):
    ?>
        <p class="muted">Lukijan leimat:
            <?php foreach ($emitLeimat as $leima): ?>
                <br><?= (int) $leima['jarjestys'] ?>. koodi <?= htmlspecialchars($leima['koodi']) ?> · <?= htmlspecialchars($leima['kello']) ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">

        <label>Numero <span class="muted">(valinnainen)</span></label>
        <input type="number" name="numero" min="1"
               placeholder="esim. <?= (int) seuraavaKilpailijaNumero($pdo, $kilpailu_id) ?>"
               value="<?= htmlspecialchars($muokattava['numero'] !== null ? (string) $muokattava['numero'] : '') ?>">

        <label>Etunimi</label>
        <input type="text" name="etunimi" required maxlength="100"
               value="<?= htmlspecialchars($muokattava['etunimi'] ?? (jaaNimiKenttiin((string) ($muokattava['nimi'] ?? ''))['etunimi'])) ?>">

        <label>Sukunimi</label>
        <input type="text" name="sukunimi" required maxlength="100"
               value="<?= htmlspecialchars($muokattava['sukunimi'] ?? (jaaNimiKenttiin((string) ($muokattava['nimi'] ?? ''))['sukunimi'])) ?>">

        <label>Seura</label>
        <input type="text" name="seura" value="<?= htmlspecialchars((string) ($muokattava['seura'] ?? '')) ?>">

        <label>Sarja</label>
        <select name="sarja_id" required>
            <?php foreach ($sarjat as $sarja): ?>
                <option value="<?= (int) $sarja['id'] ?>"
                    <?= ((int) ($muokattava['sarja_id'] ?? 0) === (int) $sarja['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sarja['nimi']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Status</label>
        <select name="status">
            <?php foreach (kilpailijaStatukset() as $st): ?>
                <option value="<?= $st ?>"
                    <?= (normalisoiKilpailijaStatus($muokattava['status'] ?? 'OK') === $st) ? 'selected' : '' ?>>
                    <?= $st ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Maksu</label>
        <select name="maksu_tila">
            <?php
            $maksuNyt = $muokattava['maksu_tila'] ?? 'ei_vaadita';
            foreach (['ei_vaadita' => 'Ei vaadita', 'odottaa' => 'Odottaa', 'maksettu' => 'Maksettu'] as $arvo => $label):
            ?>
                <option value="<?= $arvo ?>" <?= $maksuNyt === $arvo ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>

        <label>Sarjan lähtöaika</label>
        <input type="text" readonly
               value="<?= $muokattavaLahto
                    ? htmlspecialchars($muokattavaLahto)
                    : 'Ei lähtöä — määritä Lähdöt → Sarjat' ?>">

        <label>Maaliaika (sekunnin tarkkuus)</label>
        <input type="datetime-local" name="maaliaika" step="1"
               value="<?= htmlspecialchars(datetimeLocalSec($muokattava['maaliaika'] ?? null)) ?>">

        <label>Nettoaika (valinnainen)</label>
        <input type="text" name="aika_sec_syote" placeholder="lasketaan automaattisesti lähdöstä" value="">
        <label class="checkbox-label">
            <input type="checkbox" name="kayta_kasinetto" value="1">
            Käytä käsin syötettyä nettoaikaa (älä laske lähdöstä)
        </label>
        <?php if (isset($muokattava['aika_sec']) && $muokattava['aika_sec'] !== null): ?>
            <p class="muted">Nykyinen netto: <?= htmlspecialchars(formatAikaSec($muokattava['aika_sec'])) ?></p>
        <?php endif; ?>

        <?php if (!empty($muokattava['maaliaika'])): ?>
        <label class="checkbox-label">
            <input type="checkbox" name="poista_maali" value="1" id="poista_maali">
            Poista maaliaika
        </label>
        <p class="notice notice-varoitus poista-maali-huom">
            Huom: maaliajan poisto poistaa ajan myös <strong>Maalinappi</strong>- ja
            <strong>Numerot</strong>-ikkunoista (aikaleima ja numero).
            Online-tuloksista aika häviää.
        </p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit">Tallenna muutokset</button>
            <a class="btn-secondary" href="kilpailijat.php">Takaisin listaan</a>
        </div>
    </form>

    <form method="post" class="form-actions" style="margin-top:1.25rem"
          onsubmit="return confirm('Oletko varma, että haluat poistaa tämän kilpailijan?');">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
        <button type="submit" class="btn-danger">Poista kilpailija</button>
    </form>
</section>

<?php elseif ($nakyma === 'uusi'): ?>
<section class="kisa-lomake-osio is-editing">
    <h2>Lisää kilpailija</h2>
    <p class="muted">
        Lähtöaika määritellään <a href="lahdot.php">Lähdöt</a>-sivulla ja kytketään
        <a href="sarjat.php">sarjaan</a>.
    </p>

    <?php if ($sarjat === []): ?>
        <p class="notice">Luo ensin vähintään yksi <a href="sarjat.php">sarja</a>.</p>
        <p><a class="btn-secondary" href="kilpailijat.php">Takaisin listaan</a></p>
    <?php else: ?>
    <form method="post" class="form-grid">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="create">

        <label>Numero <span class="muted">(valinnainen — ilmoittautuneille annetaan myöhemmin)</span></label>
        <input type="number" name="numero" min="1"
               placeholder="esim. <?= (int) seuraavaKilpailijaNumero($pdo, $kilpailu_id) ?>" value="">

        <label>Etunimi</label>
        <input type="text" name="etunimi" required maxlength="100" value="">

        <label>Sukunimi</label>
        <input type="text" name="sukunimi" required maxlength="100" value="">

        <label>Seura</label>
        <input type="text" name="seura" value="">

        <label>Sarja</label>
        <select name="sarja_id" required>
            <?php foreach ($sarjat as $sarja): ?>
                <option value="<?= (int) $sarja['id'] ?>"><?= htmlspecialchars($sarja['nimi']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Status</label>
        <select name="status">
            <?php foreach (kilpailijaStatukset() as $st): ?>
                <option value="<?= $st ?>" <?= $st === 'OK' ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>

        <label>Maaliaika (sekunnin tarkkuus, valinnainen)</label>
        <input type="datetime-local" name="maaliaika" step="1" value="">

        <label>Nettoaika (valinnainen)</label>
        <input type="text" name="aika_sec_syote" placeholder="lasketaan automaattisesti lähdöstä" value="">
        <label class="checkbox-label">
            <input type="checkbox" name="kayta_kasinetto" value="1">
            Käytä käsin syötettyä nettoaikaa (älä laske lähdöstä)
        </label>

        <div class="form-actions">
            <button type="submit">Lisää kilpailija</button>
            <a class="btn-secondary" href="kilpailijat.php">Peruuta</a>
        </div>
    </form>
    <?php endif; ?>
</section>

<?php else: ?>
<section class="kisa-lista-osio">
    <div class="kisa-lista-header kilpailijat-lista-header">
        <div>
            <h2>Osallistujat (<?= count($kilpailijat) ?>)</h2>
            <p class="muted">Sarja, numero, nimi, seura, läsnäolo ja sen lukuaika, luettu maaliaika sekä sakot.</p>
        </div>
        <?php if (!$pirila): ?>
            <div class="kilpailijat-lista-toiminnot">
                <?php
                $ilmanNumeroa = 0;
                foreach ($kilpailijat as $kr) {
                    if ($kr['numero'] === null || $kr['numero'] === '' || (int) $kr['numero'] < 1) {
                        $ilmanNumeroa++;
                    }
                }
                ?>
                <?php if ($ilmanNumeroa > 0): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Annetaanko juoksevat numerot <?= (int) $ilmanNumeroa ?> kilpailijalle, joilla numero puuttuu?');">
                        <?= csrfKentta() ?>
                        <input type="hidden" name="action" value="numeroi_puuttuvat">
                        <button type="submit" class="btn-secondary">Anna numerot puuttuville (<?= (int) $ilmanNumeroa ?>)</button>
                    </form>
                <?php endif; ?>
                <a class="btn kisa-btn-valitse kilpailijat-lisaa-btn" href="kilpailijat.php?uusi=1">Lisää kilpailija</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($kilpailijat === []): ?>
        <p class="home-empty muted">Ei kilpailijoita vielä.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table kilpailijat-taulukko">
                <thead>
                    <tr>
                        <th>Sarja</th>
                        <th>Numero</th>
                        <th>Nimi</th>
                        <th>Seura</th>
                        <th>Läsnä</th>
                        <th>Luettu maaliaika</th>
                        <th>Sakot</th>
                        <th>Maksu</th>
                        <th class="col-toiminnot">Toiminnot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kilpailijat as $k): ?>
                        <?php
                        $kid = (int) $k['id'];
                        $etu = trim((string) ($k['etunimi'] ?? ''));
                        $suku = trim((string) ($k['sukunimi'] ?? ''));
                        if ($etu === '' && $suku === '' && !empty($k['nimi'])) {
                            $jaettu = jaaNimiKenttiin((string) $k['nimi']);
                            $etu = $jaettu['etunimi'];
                            $suku = $jaettu['sukunimi'];
                        }
                        $nimi = trim($suku . ' ' . $etu);
                        if ($nimi === '') {
                            $nimi = (string) ($k['nimi'] ?? '—');
                        }
                        $nroInt = ($k['numero'] !== null && $k['numero'] !== '') ? (int) $k['numero'] : 0;
                        $onDup = $nroInt > 0 && isset($duplikaattiNumerot[$nroInt]);
                        ?>
                        <tr class="<?= $onDup ? 'is-duplikaatti-numero' : '' ?>">
                            <td><?= htmlspecialchars((string) ($k['sarja'] ?? '')) ?></td>
                            <td class="col-numero">
                                <?= htmlspecialchars(formatKilpailijaNumero($k['numero'] ?? null)) ?>
                                <?php if ($onDup): ?>
                                    <span class="badge badge-varoitus" title="Sama numero usealla kilpailijalla">duplikaatti</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($nimi) ?>
                                <?php $lisattyAika = kilpailijaKelloTeksti($k['mobiili_lisatty_at'] ?? null); ?>
                                <?php if ($lisattyAika !== ''): ?>
                                    <br><span class="muted">Lisätty lukijalla <?= htmlspecialchars($lisattyAika) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($k['seura'] ?? '')) ?></td>
                            <td>
                                <?= htmlspecialchars(kilpailijaLasnaTeksti($k + ['luonne' => $kilpailu['luonne'] ?? ''])) ?>
                                <?php $lasnaAika = kilpailijaKelloTeksti($k['mobiili_lasna_at'] ?? null); ?>
                                <?php if ($lasnaAika !== ''): ?>
                                    <br><span class="muted"><?= htmlspecialchars($lasnaAika) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php $maaliAika = kilpailijaKelloTeksti($k['mobiili_maali_at'] ?? null); ?><?= $maaliAika !== '' ? htmlspecialchars($maaliAika) : '—' ?></td>
                            <td><?= htmlspecialchars(kilpailijaSakkoTeksti($k)) ?></td>
                            <td>
                                <?php
                                $maksuTila = (string) ($k['maksu_tila'] ?? 'ei_vaadita');
                                ?>
                                <?php if ($maksuTila === 'odottaa'): ?>
                                    <span class="badge badge-varoitus">Odottaa</span>
                                    <?php if (!empty($k['maksu_viite'])): ?>
                                        <span class="muted"><?= htmlspecialchars(muotoileViite((string) $k['maksu_viite'])) ?></span>
                                    <?php endif; ?>
                                    <form method="post" class="maksu-kuittaus">
                                        <?= csrfKentta() ?>
                                        <input type="hidden" name="action" value="set_maksu">
                                        <input type="hidden" name="id" value="<?= $kid ?>">
                                        <input type="hidden" name="maksu_tila" value="maksettu">
                                        <button type="submit" class="btn-small">Maksettu</button>
                                    </form>
                                <?php elseif ($maksuTila === 'maksettu'): ?>
                                    <span class="badge">Maksettu</span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-toiminnot">
                                <a class="btn-small btn-secondary kisa-btn-muokkaa" href="kilpailijat.php?muokkaa=<?= $kid ?>">
                                    <?= $pirila ? 'Maaliaika' : 'Muokkaa' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!$pirila): ?>
<section class="kisa-lomake-osio" style="margin-top:1.5rem">
    <h2>CSV-tuonti</h2>
    <p class="muted">Muoto: <code>numero;etunimi;sukunimi;seura;sarja;status</code> (status valinnainen: OK / DNF / DNS / DSQ). Vanha muoto <code>numero;nimi;seura;sarja</code> toimii edelleen.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="csv_import">
        <label>CSV-tiedosto</label>
        <input type="file" name="csv" accept=".csv,.txt" required>
        <div class="form-actions">
            <button type="submit">Tuo kilpailijat</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('form.form-grid').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var chk = form.querySelector('input[name="poista_maali"]');
            if (!chk || !chk.checked) return;
            var ok = window.confirm(
                'Haluatko varmasti poistaa maaliajan?\n\n'
                + 'Aika poistetaan myös Maalinappi- ja Numerot-ikkunoista '
                + '(aikaleima ja numero), sekä online-tuloksista.'
            );
            if (!ok) {
                ev.preventDefault();
            }
        });
    });
})();
</script>

<?php pageEnd(); ?>
