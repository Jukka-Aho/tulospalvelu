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

$sivu = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($sivu, 'Omat tapahtumat') || fail('otsikko');
str_contains($sivu, 'Suunnistustapahtumat') || fail('suunnistus');
str_contains($sivu, 'Juoksukilpailut') || fail('juoksu');
str_contains($sivu, 'value="henkilokohtainen"') || fail('henkilo');
str_contains($sivu, 'value="viesti"') || fail('viesti');
str_contains($sivu, 'luo_paikallinen') || fail('luo suunnistus');
str_contains($sivu, 'luo_juoksu') || fail('luo juoksu');
str_contains($sivu, 'poista_kilpailu') || fail('poista');
str_contains($sivu, 'kilpailuPoistoLomakeHtml') || fail('poisto lomake');
str_contains($sivu, '>Avaa</button>') || fail('avaa nappi');
str_contains($sivu, 'omat-kisa-tiedot') || fail('kisa kehys');
str_contains($sivu, 'omat-kisa-toiminnot') || fail('napit kehyksessa');
str_contains($sivu, 'tiliKayttajaViestiPaalla') || fail('viesti oikeus');
str_contains($sivu, 'Luo rastilipputapahtuma') && fail('ei luo rastilipusta');
str_contains($sivu, 'name="rastilippu_event_id"') && fail('rastilippu asetuksissa');

$ohjaus = file_get_contents(__DIR__ . '/../public/iltarastit.php');
str_contains($ohjaus, 'omat_tapahtumat.php') || fail('vanha hub ohjaa');

$admin = file_get_contents(__DIR__ . '/../admin/iltarastit.php');
str_contains($admin, 'omat_tapahtumat.php') || fail('admin hub ohjaa');

$layout = file_get_contents(__DIR__ . '/../includes/layout.php');
str_contains($layout, 'Omat tapahtumat') || fail('ylapalkki');

$users = file_get_contents(__DIR__ . '/../admin/users.php');
str_contains($users, 'viesti_kilpailu') || fail('users viesti');
str_contains($users, 'Lisäominaisuus') || fail('users lisa');

$tili = file_get_contents(__DIR__ . '/../includes/tili.php');
str_contains($tili, 'function tiliKayttajaViestiPaalla') || fail('tili viesti fn');
str_contains($tili, 'function tiliSeuraViestiPaalla') || fail('tili seura viesti');
str_contains($tili, 'viesti_kilpailu') || fail('tili sarake');
$seurat = file_get_contents(__DIR__ . '/../admin/seurat.php');
str_contains($seurat, 'name="viesti_kilpailu"') || fail('admin seura viesti');

$luo = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($luo, 'string $tyyppi = \'henkilokohtainen\'') || fail('luo tyyppi');
str_contains($luo, 'Rastilippu-liitäntä on toistaiseksi vain henkilökohtaisissa') || fail('yhdista ei viesti');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'Omat tapahtumat') || fail('nav');

ok('omat tapahtumat');
echo "all tests passed\n";
