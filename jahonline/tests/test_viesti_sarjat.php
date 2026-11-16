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

require_once __DIR__ . '/../includes/viesti_sarjat.php';

viestiSarjaOsuuksiaArvo(0) === 1 || fail('min 1');
viestiSarjaOsuuksiaArvo(4) === 4 || fail('4');
viestiSarjaOsuuksiaArvo(99) === 30 || fail('max 30');
ok('osuuksia raja');

$aset = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($aset, 'viestiSarjatTallenna') || fail('asetukset tallenna sarjat');
str_contains($aset, 'name="sarja_nimi[]"') || fail('asetukset sarja nimi');
str_contains($aset, 'name="sarja_osuuksia[]"') || fail('asetukset osuuksia');
str_contains($aset, '>Sarjat<') || fail('asetukset sarjat otsikko');
str_contains($aset, 'Lisää sarja') || fail('asetukset lisaa sarja');
ok('asetukset');

$sarjat = file_get_contents(__DIR__ . '/../admin/sarjat.php');
str_contains($sarjat, 'name="osuuksia"') || fail('sarjat osuuksia kentta');
str_contains($sarjat, 'Montako osuutta') || fail('sarjat osuuksia ohje');
ok('sarjat sivu');

$h = file_get_contents(__DIR__ . '/../includes/helpers.php');
str_contains($h, 'int $sarjaId = 0') || fail('viestiOsuusLkm sarja');
str_contains($h, 'sarja_osuuksia') || fail('live tulokset sarja osuuksia');
ok('helpers');

$emit = file_get_contents(__DIR__ . '/../includes/emit_lukija.php');
str_contains($emit, 'viestiOsuusLkm($pdo, (int) $kilpailu[\'id\'], $osuus, $sarjaId)') || fail('emit sarja');
ok('emit');

echo "all tests passed\n";
