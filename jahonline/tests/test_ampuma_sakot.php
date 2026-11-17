<?php

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/kisa_luonne.php';

sakkoNaytto('0213') === '0/2/1/3' || fail('0213 -> 0/2/1/3');
sakkoNaytto('0/2/1/3') === '0/2/1/3' || fail('slash säilyy');
sakkoNaytto('') === '' || fail('tyhjä');
kilpailijaSakkoTeksti(['ampsakot' => '1/4/2/3']) === '1/4/2/3' || fail('teksti');
kilpailijaSakkoTeksti([]) === '—' || fail('tyhjä rivi');

kisaNaytaSakot(['luonne' => 'ampumasuunnistus', 'ampumapaikat' => 1]) || fail('ampumasuunnistus');
kisaNaytaSakot(['luonne' => 'juoksu', 'ampumapaikat' => 0]) && fail('juoksu ei sakkoja');

$src = file_get_contents(__DIR__ . '/../includes/helpers.php');
str_contains($src, 'k.ampsakot') || fail('haeLiveTulokset valitsee ampsakot');
$seuranta = file_get_contents(__DIR__ . '/../public/seuranta.php');
str_contains($seuranta, 'tulostaSakkoSolu') || fail('seuranta sakko-sarake');

echo "ok: ampuma sakot\n";
