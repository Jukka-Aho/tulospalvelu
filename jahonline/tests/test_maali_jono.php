<?php

/**
 * Maalinappi + numerot -yhdistämisen testit.
 * php tests/test_maali_jono.php
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/maali_jono.php';

date_default_timezone_set('Europe/Helsinki');

$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if ($cond) {
        echo "OK  $msg\n";
    } else {
        echo "FAIL $msg\n";
        $fail++;
    }
}

maaliJonoVarmistaTaulut($pdo);

$pdo->beginTransaction();

try {
    $pdo->exec("INSERT INTO kilpailut (nimi, paiva, paikka, tila) VALUES ('Jono-testi', CURDATE(), 'Testi', 'kaynnissa')");
    $kid = (int) $pdo->lastInsertId();

    $hasKisaTyyppi = maaliJonoSarakeOlemassa($pdo, 'kilpailut', 'kisa_tyyppi');
    if ($hasKisaTyyppi) {
        $pdo->prepare("UPDATE kilpailut SET kisa_tyyppi = 'normaali' WHERE id = ?")->execute([$kid]);
    }
    if (maaliJonoSarakeOlemassa($pdo, 'kilpailut', 'api_token')) {
        $pdo->prepare('UPDATE kilpailut SET api_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $kid]);
    }

    $pdo->prepare("INSERT INTO lahdot (kilpailu_id, nimi, lahtoaika) VALUES (?, 'Lähtö 1', ?)")
        ->execute([$kid, date('Y-m-d') . ' 10:00:00']);
    $lahtoId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO sarjat (kilpailu_id, nimi, matka, lahto_id, jarjestys) VALUES (?, 'H21', '10 km', ?, 1)")
        ->execute([$kid, $lahtoId]);
    $sarjaId = (int) $pdo->lastInsertId();

    $hasEtunimi = maaliJonoSarakeOlemassa($pdo, 'kilpailijat', 'etunimi');
    foreach ([
        [11, 'Anna Yksi'],
        [22, 'Bertti Kaksi'],
        [33, 'Cecilia Kolme'],
    ] as $i => $row) {
        if ($hasEtunimi) {
            $osat = explode(' ', $row[1], 2);
            $pdo->prepare('INSERT INTO kilpailijat (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$kid, $sarjaId, $row[0], $row[1], $osat[0], $osat[1] ?? '']);
        } else {
            $pdo->prepare('INSERT INTO kilpailijat (kilpailu_id, sarja_id, numero, nimi) VALUES (?, ?, ?, ?)')
                ->execute([$kid, $sarjaId, $row[0], $row[1]]);
        }
    }

    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);

    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 10:30:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 10:31:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 10:32:00');

    $tila = maaliJonoLisaaNumero($pdo, $kid, 11);
    $tila = maaliJonoLisaaNumero($pdo, $kid, 22);
    $tila = maaliJonoLisaaNumero($pdo, $kid, 33);

    assert_true($tila['leimat_lkm'] === 3, 'kolme leimaa');
    assert_true($tila['numerot_lkm'] === 3, 'kolme numeroa');
    assert_true($tila['yhdistetty_lkm'] === 3, 'kolme yhdistettyä');
    assert_true($tila['rivit'][0]['numero'] === 11 && str_contains((string) $tila['rivit'][0]['maaliaika'], '10:30:00'), '1. sija 11 @ 10:30');
    assert_true($tila['rivit'][1]['numero'] === 22 && str_contains((string) $tila['rivit'][1]['maaliaika'], '10:31:00'), '2. sija 22 @ 10:31');
    assert_true($tila['rivit'][2]['numero'] === 33 && str_contains((string) $tila['rivit'][2]['maaliaika'], '10:32:00'), '3. sija 33 @ 10:32');

    $id1 = (int) $tila['rivit'][0]['numero_id'];
    $tila = maaliJonoSiirraNumero($pdo, $kid, $id1, 'alas');
    assert_true($tila['rivit'][0]['numero'] === 22 && str_contains((string) $tila['rivit'][0]['maaliaika'], '10:30:00'), 'siirto: 22 saa ensimmäisen ajan');
    assert_true($tila['rivit'][1]['numero'] === 11 && str_contains((string) $tila['rivit'][1]['maaliaika'], '10:31:00'), 'siirto: 11 saa toisen ajan');

    $id33 = (int) $tila['rivit'][2]['numero_id'];
    $tila = maaliJonoMuokkaaNumero($pdo, $kid, $id33, 33);
    assert_true($tila['rivit'][2]['numero'] === 33, 'muokkaus samaan numeroon onnistuu');
    $tila = maaliJonoMuokkaaNumero($pdo, $kid, $id33, 33);
    assert_true($tila['yhdistetty_lkm'] === 3, 'muokkaus ei hajota pareja');

    $tila = maaliJonoPoistaNumero($pdo, $kid, (int) $tila['rivit'][1]['numero_id']);
    assert_true($tila['numerot_lkm'] === 2, 'poiston jälkeen 2 numeroa');
    assert_true($tila['yhdistetty_lkm'] === 2, 'poiston jälkeen 2 yhdistettyä');
    // Poisto ei saa siirtää muiden numeroiden paikkaa: 33 pysyy sijalla 3 ajan 10:32 kanssa
    assert_true(
        $tila['rivit'][1]['tila'] === 'odottaa_numeroa'
        && str_contains((string) $tila['rivit'][1]['maaliaika'], '10:31:00'),
        'keskimmäinen aikaleima odottaa uutta numeroa'
    );
    assert_true(
        (int) $tila['rivit'][2]['numero'] === 33
        && (int) $tila['rivit'][2]['sija'] === 3
        && str_contains((string) $tila['rivit'][2]['maaliaika'], '10:32:00'),
        '33 pysyy sijalla 3 eikä peri keskimmäistä aikaa'
    );

    // Uusi numero täyttää aukon (sija 2), ei mene listan loppuun
    $tila = maaliJonoLisaaNumero($pdo, $kid, 11);
    assert_true(
        (int) $tila['rivit'][1]['numero'] === 11
        && str_contains((string) $tila['rivit'][1]['maaliaika'], '10:31:00'),
        'uusi numero täyttää poiston jättämän aukon'
    );
    assert_true($tila['yhdistetty_lkm'] === 3, 'aukko täytetty → 3 yhdistettyä');

    $tila = maaliJonoPoistaNumero($pdo, $kid, (int) $tila['rivit'][1]['numero_id']);
    assert_true($tila['yhdistetty_lkm'] === 2, 'uudelleen poisto: 2 yhdistettyä');

    $tila = maaliJonoPeruViimeinenLeima($pdo, $kid);
    assert_true($tila['leimat_lkm'] === 2, 'peru viimeinen leima');
    // Numero 33 on yhä jarjestys=3, leimoja enää 2 → odottaa aikaa
    $viimeinen = $tila['rivit'][count($tila['rivit']) - 1];
    assert_true(
        (int) ($viimeinen['numero'] ?? 0) === 33 && $viimeinen['tila'] === 'odottaa_aikaa',
        'ylimääräinen numero odottaa aikaa poiston jälkeen'
    );
    assert_true($tila['yhdistetty_lkm'] === 1, 'yksi pari jäljellä (sija 1)');

    $stmt = $pdo->prepare('
        SELECT k.numero, a.maaliaika, a.aika_sec
        FROM ajat a
        JOIN kilpailijat k ON k.id = a.kilpailija_id
        WHERE k.kilpailu_id = ?
        ORDER BY a.maaliaika
    ');
    $stmt->execute([$kid]);
    $ajat = $stmt->fetchAll(PDO::FETCH_ASSOC);
    assert_true(count($ajat) === 1, 'ajat-taulussa 1 rivi');
    assert_true((int) $ajat[0]['numero'] === 22, 'aika kilpailijalla 22');
    assert_true($ajat[0]['aika_sec'] !== null && (int) $ajat[0]['aika_sec'] === 30 * 60, 'aika_sec laskettu lähdöstä');

    $heittiTuntematon = false;
    try {
        maaliJonoLisaaNumero($pdo, $kid, 99);
    } catch (InvalidArgumentException $e) {
        $heittiTuntematon = str_contains($e->getMessage(), 'ei ole kilpailijalistassa');
    }
    assert_true($heittiTuntematon, 'tuntematon numero hylätään');
    $heittiJatti = false;
    try {
        maaliJonoLisaaNumero($pdo, $kid, 999999);
    } catch (InvalidArgumentException $e) {
        $heittiJatti = str_contains($e->getMessage(), 'ei ole kilpailijalistassa');
    }
    assert_true($heittiJatti, 'numero #999999 hylätään');

    // --- Kriittinen: keskeltä poistettu kilpailijan maali ei saa pyyhkiä myöhempiä ---
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);

    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 11:00:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 11:01:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 11:02:00');
    maaliJonoLisaaNumero($pdo, $kid, 11);
    maaliJonoLisaaNumero($pdo, $kid, 22);
    $tila = maaliJonoLisaaNumero($pdo, $kid, 33);
    assert_true($tila['yhdistetty_lkm'] === 3, 'uudelleen 3 yhdistettyä');

    $k22 = $pdo->prepare('SELECT id FROM kilpailijat WHERE kilpailu_id = ? AND numero = 22');
    $k22->execute([$kid]);
    $id22 = (int) $k22->fetchColumn();
    $a22 = $pdo->prepare('SELECT id FROM ajat WHERE kilpailija_id = ?');
    $a22->execute([$id22]);
    $ajat22 = (int) $a22->fetchColumn();

    // Sama järjestys kuin hallinnassa: poista ajat, sitten jono
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id = ?')->execute([$id22]);
    maaliJonoPoistaKilpailijanMaali($pdo, $kid, $id22, $ajat22, 22);

    $stmt->execute([$kid]);
    $ajatJalkeen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $numerotJalkeen = array_map(static fn($r) => (int) $r['numero'], $ajatJalkeen);
    sort($numerotJalkeen);
    assert_true($numerotJalkeen === [11, 33], 'keskeltä poisto säilyttää 11 ja 33 ajat');

    $tila = yhdistaMaalijono($pdo, $kid);
    $yhdistetyt = array_values(array_filter($tila['rivit'], static fn($r) => ($r['tila'] ?? '') === 'yhdistetty'));
    assert_true(count($yhdistetyt) === 2, 'keskeltä poiston jälkeen 2 yhdistettyä');
    assert_true(
        (int) $yhdistetyt[0]['numero'] === 11
        && str_contains((string) $yhdistetyt[0]['maaliaika'], '11:00:00'),
        '11 säilyttää ensimmäisen ajan'
    );
    assert_true(
        (int) $yhdistetyt[1]['numero'] === 33
        && str_contains((string) $yhdistetyt[1]['maaliaika'], '11:02:00'),
        '33 säilyttää kolmannen ajan (ei peri keskimmäistä)'
    );

    // Keskimmäisen leiman poisto ei saa siirtää myöhempää aikaa väärälle numerolle
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 12:00:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 12:01:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 12:02:00');
    maaliJonoLisaaNumero($pdo, $kid, 11);
    maaliJonoLisaaNumero($pdo, $kid, 22);
    $tila = maaliJonoLisaaNumero($pdo, $kid, 33);
    $keskiLeimaId = (int) $tila['rivit'][1]['leima_id'];
    $tila = maaliJonoPoistaLeima($pdo, $kid, $keskiLeimaId);
    assert_true(
        (int) $tila['rivit'][0]['numero'] === 11
        && str_contains((string) $tila['rivit'][0]['maaliaika'], '12:00:00'),
        'leima-poisto: 11 @ 12:00'
    );
    $rivi22 = null;
    $rivi33 = null;
    foreach ($tila['rivit'] as $r) {
        if ((int) ($r['numero'] ?? 0) === 22) {
            $rivi22 = $r;
        }
        if ((int) ($r['numero'] ?? 0) === 33) {
            $rivi33 = $r;
        }
    }
    assert_true($rivi22 && $rivi22['tila'] === 'odottaa_aikaa', 'leima-poisto: 22 odottaa aikaa');
    assert_true(
        $rivi33
        && $rivi33['tila'] === 'yhdistetty'
        && str_contains((string) $rivi33['maaliaika'], '12:02:00'),
        'leima-poisto: 33 säilyttää 12:02 eikä peri 12:01'
    );

    // Read-only tila ei saa muuttaa DB:tä
    $ennen = (int) $pdo->query('SELECT COUNT(*) FROM ajat')->fetchColumn();
    $tilaRo = maaliJonoTilaVainLuku($pdo, $kid);
    $jalkeen = (int) $pdo->query('SELECT COUNT(*) FROM ajat')->fetchColumn();
    assert_true($ennen === $jalkeen, 'tilaVainLuku ei muuta ajat-taulua');
    assert_true(($tilaRo['yhdistetty_lkm'] ?? 0) >= 1, 'tilaVainLuku palauttaa yhdistettyjä');

    // --- Mobiiliaika estää saman numeron syötön jonoon ---
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);

    $k11 = $pdo->prepare('SELECT id FROM kilpailijat WHERE kilpailu_id = ? AND numero = 11');
    $k11->execute([$kid]);
    $id11 = (int) $k11->fetchColumn();
    $pdo->prepare('INSERT INTO ajat (kilpailija_id, maaliaika, aika_sec) VALUES (?, ?, ?)')
        ->execute([$id11, date('Y-m-d') . ' 13:00:00', 3600]);

    $blocked = false;
    try {
        maaliJonoLisaaNumero($pdo, $kid, 11);
    } catch (InvalidArgumentException $e) {
        $blocked = str_contains($e->getMessage(), 'jo maalissa');
    }
    assert_true($blocked, 'jo maalissa olevaa numeroa ei voi lisätä jonoon');

    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 13:10:00');
    maaliJonoLisaaNumero($pdo, $kid, 22);
    $dupBlocked = false;
    try {
        maaliJonoLisaaNumero($pdo, $kid, 22);
    } catch (InvalidArgumentException $e) {
        $dupBlocked = str_contains($e->getMessage(), 'jo jonossa');
    }
    assert_true($dupBlocked, 'samaa numeroa ei voi lisätä jonoon kahdesti');

    $stmt->execute([$kid]);
    $ajat11 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n11 = array_values(array_filter($ajat11, static fn($r) => (int) $r['numero'] === 11));
    assert_true(count($n11) === 1, 'mobiiliaika säilyy eikä jonosta tule toista');
    assert_true(str_contains((string) $n11[0]['maaliaika'], '13:00:00'), 'mobiiliaikaa ei ylikirjoiteta');

    // --- Vanha ei_loydu-rivi (ennen kieltoa) yhdistyy kun kilpailija lisätään ---
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);

    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 14:00:00');
    $pdo->prepare('INSERT INTO maali_jononumerot (kilpailu_id, jarjestys, numero) VALUES (?, 1, 99)')
        ->execute([$kid]);
    $tila = yhdistaMaalijono($pdo, $kid);
    $rivi99 = null;
    foreach ($tila['rivit'] as $r) {
        if ((int) ($r['numero'] ?? 0) === 99) {
            $rivi99 = $r;
            break;
        }
    }
    assert_true($rivi99 && $rivi99['tila'] === 'ei_loydu', 'vanha tuntematon numero → ei_loydu');
    assert_true(
        (int) $pdo->query('SELECT COUNT(*) FROM ajat a JOIN kilpailijat k ON k.id = a.kilpailija_id WHERE k.kilpailu_id = ' . (int) $kid)->fetchColumn() === 0,
        'ei ajat-riviä ennen kilpailijaa'
    );

    if ($hasEtunimi) {
        $pdo->prepare('INSERT INTO kilpailijat (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$kid, $sarjaId, 99, 'Uusi Juoksija', 'Uusi', 'Juoksija', 'OK']);
    } else {
        $pdo->prepare('INSERT INTO kilpailijat (kilpailu_id, sarja_id, numero, nimi) VALUES (?, ?, ?, ?)')
            ->execute([$kid, $sarjaId, 99, 'Uusi Juoksija']);
    }

    $synk = maaliJonoSynkkaaKilpailunJalkeen($pdo, $kid);
    assert_true(($synk['yhdistetty_lkm'] ?? 0) >= 1, 'synkka yhdistää myöhemmin lisätyn kilpailijan');

    $stmtAjat = $pdo->prepare('
        SELECT a.maaliaika, a.aika_sec
        FROM ajat a
        JOIN kilpailijat k ON k.id = a.kilpailija_id
        WHERE k.kilpailu_id = ? AND k.numero = 99
    ');
    $stmtAjat->execute([$kid]);
    $aika99 = $stmtAjat->fetch(PDO::FETCH_ASSOC);
    assert_true($aika99 !== false, 'ajat-rivi syntyy synkan jälkeen');
    assert_true(str_contains((string) ($aika99['maaliaika'] ?? ''), '14:00:00'), 'maaliaika 14:00 yhdistyy numerolle 99');

    $tilaJalkeen = maaliJonoTila($pdo, $kid);
    $rivi99b = null;
    foreach ($tilaJalkeen['rivit'] as $r) {
        if ((int) ($r['numero'] ?? 0) === 99) {
            $rivi99b = $r;
            break;
        }
    }
    assert_true($rivi99b && $rivi99b['tila'] === 'yhdistetty', 'jonorivi tila yhdistetty synkan jälkeen');

    // Ylimääräinen leima ei täytä keskiaukkoa (ei varasta #22:n paikkaa)
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 15:00:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 15:01:00');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 15:02:00');
    maaliJonoLisaaNumero($pdo, $kid, 11);
    maaliJonoLisaaNumero($pdo, $kid, 22);
    $tila = maaliJonoLisaaNumero($pdo, $kid, 33);
    $keskiId = (int) $tila['rivit'][1]['leima_id'];
    maaliJonoPoistaLeima($pdo, $kid, $keskiId);
    $tila = maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 15:03:00');
    $r22 = null;
    $r33 = null;
    $uusiLopussa = false;
    foreach ($tila['rivit'] as $r) {
        if ((int) ($r['numero'] ?? 0) === 22) {
            $r22 = $r;
        }
        if ((int) ($r['numero'] ?? 0) === 33) {
            $r33 = $r;
        }
        if (($r['tila'] ?? '') === 'odottaa_numeroa'
            && str_contains((string) ($r['maaliaika'] ?? ''), '15:03:00')
        ) {
            $uusiLopussa = true;
        }
    }
    assert_true($r22 && $r22['tila'] === 'odottaa_aikaa', 'aukon jälkeinen leima ei täytä #22:n paikkaa');
    assert_true(
        $r33 && $r33['tila'] === 'yhdistetty' && str_contains((string) $r33['maaliaika'], '15:02:00'),
        '#33 säilyttää 15:02 ylimääräisen napin jälkeen'
    );
    assert_true($uusiLopussa, 'uusi leima menee jonon perään');

    // Kaksi paritonta leimaa väärässä saapumisjärjestyksessä → järjestys kellon mukaan
    $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id IN (SELECT id FROM kilpailijat WHERE kilpailu_id = ?)')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_leimat WHERE kilpailu_id = ?')->execute([$kid]);
    $pdo->prepare('DELETE FROM maali_jononumerot WHERE kilpailu_id = ?')->execute([$kid]);
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 16:00:02');
    maaliJonoLisaaLeima($pdo, $kid, date('Y-m-d') . ' 16:00:01');
    $tila = maaliJonoTila($pdo, $kid);
    assert_true(
        str_contains((string) ($tila['rivit'][0]['maaliaika'] ?? ''), '16:00:01')
        && str_contains((string) ($tila['rivit'][1]['maaliaika'] ?? ''), '16:00:02'),
        'parittomat leimat järjestetään kellon mukaan'
    );

    echo $fail === 0 ? "\nKaikki testit OK\n" : "\n$fail testi(ä) epäonnistui\n";
} catch (Throwable $e) {
    echo "FAIL poikkeus: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

exit($fail === 0 ? 0 : 1);
