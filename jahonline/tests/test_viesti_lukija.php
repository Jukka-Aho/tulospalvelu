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

$sivu = file_get_contents(__DIR__ . '/../public/viesti_lukija.php');
str_contains($sivu, 'emit_lukija.php') || fail('viestisivu kayttaa samaa lukijaa');
str_contains($sivu, '$emitViestiSivu = true') || fail('viesti lippu');

$php = file_get_contents(__DIR__ . '/../public/emit_lukija.php');
str_contains($php, 'viesti_lukija.php') || fail('emit ohjaa viestisivulle');
str_contains($php, "viesti:") || fail('JAH_EMIT.viesti');
str_contains($php, 'emit_lukija.js?v=20261121maali') || fail('js cache');
str_contains($php, "\$emitCssV = '20261116hamp'") || fail('css cache');
str_contains($php, 'automaatti25') || fail('js versio');
str_contains($php, 'Vaihto / maali') || fail('vaihto nappi');
str_contains($php, 'usb_sarja_boot.js') || fail('sama usb');
str_contains($php, 'emit_proto.js') || fail('sama proto');

$js = file_get_contents(__DIR__ . '/../assets/js/emit_lukija.js');
str_contains($js, 'function viestiHenkiloHtml') || fail('js viesti html');
str_contains($js, 'cfg.viesti') || fail('js cfg.viesti');
str_contains($js, 'JAH_EMIT_VERSIO = \'automaatti25\'') || fail('js versio');
str_contains($js, 'window.JahUsbSerial') || fail('js sama usb');
str_contains($js, 'JahEmitProto') || fail('js sama proto');

$src = file_get_contents(__DIR__ . '/../includes/emit_lukija.php');
str_contains($src, 'function emitLukijaTaydennaViesti') || fail('php taydenna viesti');
str_contains($src, 'k.joukkue_id') || fail('php joukkue_id');
str_contains($src, 'emitLukijaTaydennaViesti($pdo, $kilpailu, $rivi, $vastaus)') || fail('php kutsuu taydenna');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'Viesti-lukija') || fail('nav viesti-lukija');

$help = file_get_contents(__DIR__ . '/../includes/helpers.php');
str_contains($help, "'viesti_lukija'") || fail('julkinenLinkit viesti');

$sw = file_get_contents(__DIR__ . '/../sw-jahonline.js');
str_contains($sw, 'viesti_lukija.php') || fail('pwa viestisivu');
str_contains($sw, 'jahonline-app-v59') || fail('pwa cache');

require_once __DIR__ . '/../includes/emit_lukija_logiikka.php';
$n = emitLukijaViestiNaytto([
    'joukkue_seura' => 'Jämsän Retki',
    'joukkue' => '2',
    'joukkue_numero' => 123,
    'osuus' => 2,
    'etunimi' => 'Matti',
    'sukunimi' => 'Meikäläinen',
]);
$n['joukkue_nimi'] === 'Jämsän Retki 2' || fail('joukkue nimi');
$n['otsikko'] === '123 Jämsän Retki 2' || fail('otsikko');
str_contains($n['osuus_txt'], '2. osuus') || fail('osuus txt');
str_contains($n['osuus_txt'], 'Meikäläinen Matti') || fail('juoksija');

ok('viesti lukija');
echo "all tests passed\n";
