<?php

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

$helpers = file_get_contents(__DIR__ . '/../includes/helpers.php');
$fn = 'function haeSarjanOnlinePisteet';
$pos = strpos($helpers, $fn);
$pos !== false || fail('haeSarjanOnlinePisteet puuttuu');
$chunk = substr($helpers, $pos, 900);
str_contains($chunk, 'online_max') && fail('online-pisteet ei saa päätellä leimoista');
str_contains($chunk, 'SELECT COALESCE(valia_lkm, 0)') || fail('online-pisteet valia_lkm:stä');
str_contains($chunk, 'return [];') || fail('valia_lkm=0 → ei sarakkeita');

$karttaPos = strpos($helpers, 'function haeValiajatKartta');
$karttaPos !== false || fail('haeValiajatKartta puuttuu');
$kartta = substr($helpers, $karttaPos, 1600);
str_contains($kartta, 'rasti_koodi IS NOT NULL')
    || fail('rastiväliajat vain Emit-leimoista');

$haeVa = strpos($helpers, 'function haeValiajat(');
$haeVa !== false || fail('haeValiajat puuttuu');
str_contains(substr($helpers, $haeVa, 800), 'rasti_koodi IS NOT NULL')
    || fail('haeValiajat vain Emit');

$nav = file_get_contents(__DIR__ . '/../includes/tulospalvelu.php');
str_contains($nav, "'label' => 'Online tulokset'") || fail('nav Online tulokset');
str_contains($nav, 'Online-väliajat') && fail('nav vanha Online-väliajat');

$kuori = file_get_contents(__DIR__ . '/../public/kilpailu.php');
str_contains($kuori, "'label' => 'Online tulokset'") || fail('kuori Online tulokset');
str_contains($kuori, 'Online-väliajat') && fail('kuori vanha Online-väliajat');

$seuranta = file_get_contents(__DIR__ . '/../public/seuranta.php');
str_contains($seuranta, '>Online tulokset</a>') || fail('seuranta chip Online tulokset');

echo "ok: online tulokset\n";
