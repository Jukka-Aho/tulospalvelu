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

require_once __DIR__ . '/../includes/kisa_osoite.php';

kisaOsoiteSiisti("  Mannerheimintie   5,  Helsinki ") === 'Mannerheimintie 5, Helsinki' || fail('siisti');
kisaOsoiteSiisti('') === '' || fail('tyhja');

$json = '[{"lat":"60.1699","lon":"24.9384","display_name":"Helsinki"}]';
$p = kisaOsoitePisteJsonista($json);
$p && abs($p['lat'] - 60.1699) < 0.0001 || fail('lat');
$p && abs($p['lng'] - 24.9384) < 0.0001 || fail('lng');
kisaOsoitePisteJsonista('[]') === null || fail('tyhja json');
kisaOsoitePisteJsonista('not-json') === null || fail('rikki json');
kisaOsoiteOnPiste(['lat' => 60.1, 'lng' => 24.9]) || fail('on piste');
kisaOsoiteOnPiste(['lat' => null, 'lng' => null]) && fail('ei pistetta');
kisaOsoiteOnPiste(['lat' => 0, 'lng' => 0]) && fail('nolla');
ok('osoite apurit');

$src = file_get_contents(__DIR__ . '/../includes/kisa_osoite.php');
str_contains($src, 'nominatim.openstreetmap.org') || fail('nominatim');
str_contains($src, "'osoite'") || fail('sarake osoite');
str_contains($src, "'lat'") || fail('sarake lat');
ok('geokoodaus');

$index = file_get_contents(__DIR__ . '/../public/index.php');
str_contains($index, 'home-kartta') || fail('kartta');
str_contains($index, 'data-home-nakyma') || fail('nakyma');
str_contains($index, 'data-nakyma="lista"') || fail('lista valinta');
str_contains($index, 'data-nakyma="kartta"') || fail('kartta valinta');
str_contains($index, 'leaflet') || fail('leaflet');
str_contains($index, 'etusivu_kartta.js') || fail('kartta js');
str_contains($index, 'etusivuKarttaMerkit') || fail('merkit');
str_contains($index, 'min-width:901px') || fail('desktop molemmat');
ok('etusivu kartta');

$js = file_get_contents(__DIR__ . '/../assets/js/etusivu_kartta.js');
str_contains($js, 'jah-etusivu-nakyma') || fail('muisti');
str_contains($js, 'min-width: 901px') || fail('js desktop');
str_contains($js, 'jahKarttaSuodata') || fail('haku kartta');
str_contains($js, 'setView(merkitKartalla[0]') && fail('yksi merkki ei zoomaa lahelle');
str_contains($js, 'suomiRajat') || fail('suomi rajat');
str_contains($js, 'length <= 1') || fail('yksi merkki koko suomi');
str_contains($js, '#c49a22') || fail('kulta');
str_contains($js, '#111') || fail('musta');
ok('kartta js');

$omat = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($omat, 'name="osoite"') || fail('omat osoite');
str_contains($omat, 'kisaOsoiteTallenna') || fail('omat tallenna');
ok('omat osoite');

$aset = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($aset, 'name="osoite"') || fail('asetukset osoite');
str_contains($aset, 'kisaOsoiteTallenna') || fail('asetukset tallenna');
ok('kilpailu asetukset');

$ilta = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($ilta, 'name="osoite"') || fail('iltarasti osoite');
str_contains($ilta, 'kisaOsoiteTallenna') || fail('iltarasti tallenna');
ok('iltarasti asetukset');

$tili = file_get_contents(__DIR__ . '/../includes/tili.php');
str_contains($tili, 'kisaOsoiteVarmista') || fail('tili varmista');
ok('migraatio');

echo "all tests passed\n";
