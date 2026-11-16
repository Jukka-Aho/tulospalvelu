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

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ssl_tulokset.php';
require_once __DIR__ . '/../includes/tili.php';

sslAikaKentta(3661, 'OK') === '010101' || fail('aika 1h1m1s');
sslAikaKentta(45, 'OK') === '000045' || fail('aika 45s');
sslAikaKentta(null, 'DNF') === 'KES   ' || fail('dnf');
sslAikaKentta(null, 'DSQ') === 'HYL   ' || fail('dsq');
sslAikaKentta(null, 'DNS') === null || fail('dns skip');
sslAikaKentta(null, 'OK') === null || fail('ei aikaa skip');
ok('aika');

$ryhmat = [
    'H21' => [
        'matka' => '10.5',
        'maalissa' => [
            [
                'nimi' => 'Meikäläinen Matti',
                'etunimi' => 'Matti',
                'sukunimi' => 'Meikäläinen',
                'seura' => 'HS',
                'status' => 'OK',
                'maali_aika_sec' => 3723,
                'lisenssi' => '123456',
            ],
        ],
        'ei_maaleissa' => [
            [
                'nimi' => 'Keskeyttäjä',
                'etunimi' => 'Kalle',
                'sukunimi' => 'Keskeyttäjä',
                'seura' => 'Angelniemen Ankkuri',
                'status' => 'DNF',
                'maali_aika_sec' => null,
            ],
        ],
        'maastossa' => [],
    ],
];
$txt = sslTiedostoMerkkijono(['nimi' => 'Testikisa'], $ryhmat);
$lines = preg_split("/\r\n/", trim($txt, "\r\n")) ?: [];
count($lines) >= 3 || fail('rivit');
str_starts_with($lines[0], '999999') || fail('otsikko koodi');
strlen($lines[0]) === 53 || fail('otsikko pituus ' . strlen($lines[0]));
substr($lines[0], -1) === '0' || fail('otsikko nolla');
str_starts_with($lines[1], '999998') || fail('sarja koodi');
str_contains($lines[1], 'H21') || fail('sarja nimi');
str_contains($lines[1], '10,5') || fail('sarja matka');
$tulos = null;
foreach ($lines as $ln) {
    if (str_starts_with($ln, '123456')) {
        $tulos = $ln;
        break;
    }
}
is_string($tulos) || fail('tulosrivi');
strlen($tulos) === 53 || fail('tulos pituus ' . strlen((string) $tulos));
str_contains($tulos, 'Meik') || fail('sukunimi');
str_contains($tulos, 'H21') || fail('tulos sarja');
str_ends_with($tulos, '010203') || fail('tulos aika ' . substr((string) $tulos, -6));
$kes = null;
foreach ($lines as $ln) {
    if (str_contains($ln, 'KES   ')) {
        $kes = $ln;
        break;
    }
}
is_string($kes) || fail('kes rivi');
ok('tiedosto');

tiliSslHintaEuro() === 10.0 || fail('hinta');
tiliLaskutusKuukausi('2026-03-15 12:00:00') === '2026-03' || fail('kk');
tiliLaskutusKuukausiSiirra('2026-01', -1) === '2025-12' || fail('kk siirto');
tiliLaskutuspostiOk('lasku@seura.fi') || fail('posti ok');
tiliLaskutuspostiOk('') && fail('posti tyhja');
tiliLaskutuspostiOk('', true) || fail('ei laskutusta ilman postia');
tiliSeuraLaskutuspostiOk(['laskutus_email' => '', 'ei_laskutusta' => 0]) && fail('seura ilman postia');
tiliSeuraLaskutuspostiOk(['laskutus_email' => 'a@b.fi']) || fail('seura posti');
tiliLaskutuspostiPuuttuuTeksti() === 'Laskutussähköposti puuttuu' || fail('puute teksti');
ok('laskutus');

$aset = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($aset, 'laskutus kuukausittain') || fail('asetukset kuukausi');
str_contains($aset, 'laskutus vuosittain') && fail('asetukset ei vuosittain');
$oma = file_get_contents(__DIR__ . '/../admin/oma_seura.php');
str_contains($oma, 'laskutetaan kuukausittain') || fail('oma kuukausi');
str_contains($oma, 'SSL-tulokset liitolle') || fail('oma ssl');
str_contains($oma, 'kerran kuukaudessa') || fail('oma kerran kk');
str_contains($oma, 'laskutusposti-esto') || fail('oma harmaa liitanta');
str_contains($oma, 'tiliLaskutuspostiHuomioHtml') || fail('oma posti puuttuu');
str_contains($oma, 'tiliLaskutuspostiLiitantaJs') || fail('oma posti js');
$seurat = file_get_contents(__DIR__ . '/../admin/seurat.php');
str_contains($seurat, 'laskutusposti-esto') || fail('seurat harmaa liitanta');
$vienti = file_get_contents(__DIR__ . '/../admin/tulokset_vienti.php');
str_contains($vienti, 'sslTuloksetNappiHtml') || fail('vienti ssl nappi');
$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'sslTuloksetKuoriLinkki') || fail('nav ssl');
$sslSivu = file_get_contents(__DIR__ . '/../public/tulokset_ssl.php');
str_contains($sslSivu, 'sslTuloksetLaskutusOk') || fail('ssl sivu posti');
$tp = file_get_contents(__DIR__ . '/../includes/tulospalvelu.php');
str_contains($tp, 'tulokset_ssl.php') || fail('tp ssl');
is_readable(__DIR__ . '/../public/tulokset_ssl.php') || fail('ssl sivu');
ok('ui');

echo "all tests passed\n";
