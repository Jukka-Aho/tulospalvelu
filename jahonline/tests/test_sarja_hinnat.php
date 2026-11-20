<?php

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

$maksu = file_get_contents(__DIR__ . '/../includes/maksu.php');
str_contains($maksu, 'maksu_sarjoittain') || fail('kilpailut sarjahinta-sarake');
str_contains($maksu, 'ennakko_maksu_eur') || fail('sarjat ennakko-sarake');
str_contains($maksu, 'paikanpaa_maksu_eur') || fail('sarjat paikanpaa-sarake');

$help = file_get_contents(__DIR__ . '/../includes/helpers.php');
str_contains($help, 'function kisaMaksuSarjoittain') || fail('kisaMaksuSarjoittain');
str_contains($help, 'function ilmoMaksuEurIlmoittautuvalle') || fail('ilmoMaksuEurIlmoittautuvalle');
str_contains($help, 'function paikanpaaMaksuEurIlmoittautuvalle') || fail('paikanpaaMaksuEurIlmoittautuvalle');
str_contains($help, 'ennakko_maksu_eur, paikanpaa_maksu_eur') || fail('ilmoittautumisen sarjavalinta hakee hinnat');

$kisa = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($kisa, 'name="maksu_sarjoittain"') || fail('kisa valinta');
str_contains($kisa, 'Yksi hinta kaikille sarjoille') || fail('yksi hinta');
str_contains($kisa, 'Sarjakohtaiset hinnat') || fail('sarjahinnat');
str_contains($kisa, 'maksu_sarjoittain = ?') || fail('kisa tallentaa valinnan');

$sarjat = file_get_contents(__DIR__ . '/../admin/sarjat.php');
str_contains($sarjat, 'name="ennakko_maksu_eur"') || fail('sarja ennakko');
str_contains($sarjat, 'name="paikanpaa_maksu_eur"') || fail('sarja paikanpaa');

$ilmo = file_get_contents(__DIR__ . '/../public/ilmoittautuminen.php');
str_contains($ilmo, 'data-hinta') || fail('ilmo data-hinta');
str_contains($ilmo, 'id="sarja-hinta"') || fail('ilmo hinta-elementti');

$kioski = file_get_contents(__DIR__ . '/../public/paikanpaa_ilmo.php');
str_contains($kioski, 'data-hinta') || fail('kioski data-hinta');

$tall = file_get_contents(__DIR__ . '/../includes/sarja_numerot.php');
str_contains($tall, 'ilmoMaksuEurIlmoittautuvalle') || fail('tallennus kayttaa sarjahintaa');

echo "ok: sarja hinnat\n";
