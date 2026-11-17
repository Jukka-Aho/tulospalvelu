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
str_contains($sivu, 'Luo tapahtuma') || fail('yksi luonti');
str_contains($sivu, 'name="malli"') || fail('malli');
str_contains($sivu, "\$sallitutMallit['suunnistus']") || fail('suunnistus tyyppi');
str_contains($sivu, "\$sallitutMallit['juoksu']") || fail('juoksu tyyppi');
str_contains($sivu, "\$sallitutMallit['pirila']") || fail('pirila tyyppi');
str_contains($sivu, 'Suunnistus-Pirilä') || fail('pirila label');
str_contains($sivu, 'value="henkilokohtainen"') || fail('henkilo');
str_contains($sivu, 'value="viesti"') || fail('viesti');
str_contains($sivu, 'luo_tapahtuma') || fail('luo tapahtuma');
str_contains($sivu, 'luo_paikallinen') || fail('luo suunnistus alias');
str_contains($sivu, 'luo_juoksu') || fail('luo juoksu alias');
str_contains($sivu, 'kisaLuoPirila') || fail('luo pirila');
str_contains($sivu, 'kisaLuoJuoksu') || fail('luo juoksu fn');
str_contains($sivu, 'poista_kilpailu') || fail('poista');
str_contains($sivu, 'kilpailuPoistoLomakeHtml') || fail('poisto lomake');
str_contains($sivu, '>Avaa</button>') || fail('avaa nappi');
str_contains($sivu, '>Asetukset</a>') || fail('asetukset nappi');
str_contains($sivu, 'kilpailut.php?muokkaa=') || fail('asetukset muokkaa');
str_contains($sivu, 'omat-kisa-tiedot') || fail('kisa kehys');
str_contains($sivu, 'omat-kisa-toiminnot') || fail('napit kehyksessa');
str_contains($sivu, '#15803d') || fail('avaa vihrea');
str_contains($sivu, '#dc2626') || fail('poista punainen');
str_contains($sivu, 'tiliKayttajaViestiPaalla') || fail('viesti oikeus');
str_contains($sivu, 'tiliKayttajaPirilaPaalla') || fail('pirila oikeus');
str_contains($sivu, 'Luo rastilipputapahtuma') && fail('ei luo rastilipusta');
str_contains($sivu, 'name="rastilippu_event_id"') && fail('rastilippu asetuksissa');
preg_match_all('/<h2>/', $sivu) >= 1 || fail('otsikoita');
str_contains($sivu, '<h2>Suunnistustapahtumat</h2>') && fail('ei erillista suunnistus-lomaketta');
str_contains($sivu, '<h2>Juoksukilpailut</h2>') && fail('ei erillista juoksu-lomaketta');

$ohjaus = file_get_contents(__DIR__ . '/../public/iltarastit.php');
str_contains($ohjaus, 'omat_tapahtumat.php') || fail('vanha hub ohjaa');

$admin = file_get_contents(__DIR__ . '/../admin/iltarastit.php');
str_contains($admin, 'omat_tapahtumat.php') || fail('admin hub ohjaa');

$layout = file_get_contents(__DIR__ . '/../includes/layout.php');
str_contains($layout, 'Omat tapahtumat') || fail('ylapalkki');

$users = file_get_contents(__DIR__ . '/../admin/users.php');
str_contains($users, 'viesti_kilpailu') || fail('users viesti');
str_contains($users, 'suunnistus_pirila') || fail('users pirila');
str_contains($users, 'lisäominaisuudet') || fail('users lisa');
str_contains($users, 'Suunnistus-Pirilä') || fail('users pirila otsikko');

$tili = file_get_contents(__DIR__ . '/../includes/tili.php');
str_contains($tili, 'function tiliKayttajaViestiPaalla') || fail('tili viesti fn');
str_contains($tili, 'function tiliKayttajaPirilaPaalla') || fail('tili pirila fn');
str_contains($tili, 'function tiliSeuraViestiPaalla') || fail('tili seura viesti');
str_contains($tili, 'viesti_kilpailu') || fail('tili sarake');
str_contains($tili, 'suunnistus_pirila') || fail('tili pirila sarake');
str_contains($tili, 'palvelu_pirila') || fail('tili seura pirila');
$seurat = file_get_contents(__DIR__ . '/../admin/seurat.php');
str_contains($seurat, 'name="viesti_kilpailu"') || fail('admin seura viesti');
str_contains($seurat, 'name="palvelu_pirila"') || fail('admin seura pirila');
str_contains($seurat, 'Suunnistus-Pirilä') || fail('admin seura pirila label');
str_contains($seurat, 'Juoksu') || fail('admin seura juoksu');
str_contains($seurat, "name=\"palvelu_iltarastit\"") || fail('admin seura suunnistus');

$luo = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($luo, 'string $tyyppi = \'henkilokohtainen\'') || fail('luo tyyppi');
str_contains($luo, 'Rastilippu-liitäntä on toistaiseksi vain henkilökohtaisissa') || fail('yhdista ei viesti');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'Omat tapahtumat') || fail('nav');
str_contains($nav, '>Kilpailulista<') && fail('nav ei kilpailulistaa');

$asetukset = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($asetukset, 'pirila-kehys') || fail('pirila kehys');
str_contains($asetukset, '$naytaPirilaKehys') || fail('pirila ehdollinen');
str_contains($asetukset, 'kilpailuParsePirilaKentat') || fail('pirila parse');
str_contains($asetukset, "Location: /public/omat_tapahtumat.php") || fail('lista ohjaa omiin');
str_contains($asetukset, '!isset($_GET[\'muokkaa\'])') || fail('asetukset muokkaa jää');

ok('omat tapahtumat');
echo "all tests passed\n";
