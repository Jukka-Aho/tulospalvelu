<?php

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function ok($msg)
{
    echo "ok: $msg\n";
}

require_once __DIR__ . '/../includes/kilpailu_poisto.php';

kilpailuPoistoVahvistusTeksti(['nimi' => 'Testikisa']) === 'Testikisa' || fail('nimi teksti');
kilpailuPoistoVahvistusTeksti(['nimi' => '  ']) === 'POISTA' || fail('tyhja nimi');
kilpailuPoistoVahvistusOk(['nimi' => 'Testikisa'], ['vahvista_nimi' => 'Testikisa']) || fail('vahvistus ok');
kilpailuPoistoVahvistusOk(['nimi' => 'Testikisa'], ['vahvista_nimi' => 'testikisa']) && fail('vahvistus kirjainkoko');
kilpailuPoistoVahvistusOk(['nimi' => 'Testikisa'], ['vahvista_nimi' => '']) && fail('vahvistus tyhja');
kilpailuPoistoVahvistusOk(['nimi' => 'Testikisa'], []) && fail('vahvistus puuttuu');
ok('vahvistus');

$html = kilpailuPoistoLomakeHtml(['id' => 7, 'nimi' => 'Ilta'], [
    'kohde' => 'tapahtuman',
    'btn_class' => 'btn-danger btn-small omat-poista',
]);
str_contains($html, 'data-poista-kisa') || fail('lomake data');
str_contains($html, 'data-kisa-nimi="Ilta"') || fail('lomake nimi');
str_contains($html, 'name="vahvista_nimi"') || fail('lomake vahvista');
str_contains($html, 'name="toiminto"') || fail('lomake toiminto');
str_contains($html, 'value="poista_kilpailu"') || fail('lomake arvo');
str_contains($html, 'name="kilpailu_id"') || fail('lomake id');
str_contains($html, 'value="7"') || fail('lomake id arvo');
$js = kilpailuPoistoVahvistusJs();
str_contains($js, 'window.prompt') || fail('js prompt');
str_contains($js, 'vahvista_nimi') || fail('js kentta');
ok('lomake');

$omat = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($omat, 'kilpailu_poisto.php') || fail('omat include');
str_contains($omat, 'poista_kilpailu') || fail('omat toiminto');
str_contains($omat, 'kilpailuPoistoLomakeHtml') || fail('omat lomake');
substr_count($omat, 'kilpailuPoistoLomakeHtml') >= 2 || fail('omat molemmat listat');
str_contains($omat, '>Avaa</button>') || fail('omat avaa');
str_contains($omat, 'omat-kisa-tiedot') || fail('omat kehys');
str_contains($omat, '#15803d') || fail('avaa vihrea');
str_contains($omat, '#dc2626') || fail('poista punainen');
ok('omat tapahtumat');

$index = file_get_contents(__DIR__ . '/../admin/index.php');
str_contains($index, 'kilpailu_poisto.php') || fail('index include');
str_contains($index, "toiminto === 'poista_kilpailu'") || fail('index post');
str_contains($index, 'kilpailuPoistoLomakeHtml') || fail('index lomake');
ok('hallinta');

$lista = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($lista, 'kilpailuPoistaHallinnasta') || fail('lista poisto');
str_contains($lista, 'kilpailuPoistoLomakeHtml') || fail('lista lomake');
ok('kilpailulista');

$aset = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($aset, 'Poista tapahtuma') || fail('asetukset otsikko');
str_contains($aset, 'poista_kilpailu') || fail('asetukset toiminto');
ok('asetukset');

if (is_readable(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $nimi = 'Poistotesti ' . bin2hex(random_bytes(3));
        $pdo->prepare("
            INSERT INTO kilpailut (nimi, paiva, tila, kisa_tyyppi, testikisa, luonne, tyyppi)
            VALUES (?, CURDATE(), 'luonnos', 'normaali', 1, 'juoksu', 'henkilokohtainen')
        ")->execute([$nimi]);
        $id = (int) $pdo->lastInsertId();
        $id > 0 || fail('testikisa id');
        try {
            $pdo->prepare('INSERT INTO sarjat (kilpailu_id, nimi) VALUES (?, ?)')->execute([$id, 'H21']);
        } catch (Throwable $e) {
        }
        $k = ['id' => $id, 'nimi' => $nimi];
        kilpailuPoistoVahvistusOk($k, ['vahvista_nimi' => 'vaarin']) && fail('db vahvistus vaarin');
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM kilpailut WHERE id = ?');
            $st->execute([$id]);
            (int) $st->fetchColumn() === 1 || fail('testikisa kannassa');
            kilpailuPoistaTietokannasta($pdo, $id);
            $st->execute([$id]);
            (int) $st->fetchColumn() === 0 || fail('poisto db');
            $stSarja = $pdo->prepare('SELECT COUNT(*) FROM sarjat WHERE kilpailu_id = ?');
            $stSarja->execute([$id]);
            (int) $stSarja->fetchColumn() === 0 || fail('sarjat cascade');
            ok('tietokanta');
        } catch (Throwable $e) {
            try {
                $pdo->prepare('DELETE FROM sarjat WHERE kilpailu_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM kilpailut WHERE id = ?')->execute([$id]);
            } catch (Throwable $e2) {
            }
            fail('db poisto: ' . $e->getMessage());
        }
    }
}

echo "all tests passed\n";
