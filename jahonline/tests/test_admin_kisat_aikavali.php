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

require_once __DIR__ . '/../includes/kilpailu_aikavali.php';

$oletus = kilpailuAikavaliOletus();
$tanaan = new DateTimeImmutable(kilpailuAikavaliTanaan(), new DateTimeZone('Europe/Helsinki'));
$odotettuAlku = $tanaan->modify('-14 days')->format('Y-m-d');
$odotettuLoppu = $tanaan->modify('+14 days')->format('Y-m-d');
$oletus['alkaen'] === $odotettuAlku || fail('oletus alkaen');
$oletus['asti'] === $odotettuLoppu || fail('oletus asti');
ok('oletus ±14 pv');

$tyhja = kilpailuParsiaikavali([]);
$tyhja['kaikki'] === false || fail('tyhja ei kaikki');
$tyhja['alkaen'] === $odotettuAlku || fail('tyhja alkaen');
$tyhja['asti'] === $odotettuLoppu || fail('tyhja asti');
ok('tyhjä GET → oletus');

$kaikki = kilpailuParsiaikavali(['kaikki' => '1']);
$kaikki['kaikki'] === true || fail('kaikki lippu');
ok('kaikki=1');

$vali = kilpailuParsiaikavali(['alkaen' => '2026-01-01', 'asti' => '2026-01-31']);
$vali['kaikki'] === false || fail('päivillä ei kaikki');
$vali['alkaen'] === '2026-01-01' || fail('alkaen');
$vali['asti'] === '2026-01-31' || fail('asti');
ok('valittu väli');

$vaarinpain = kilpailuParsiaikavali(['alkaen' => '2026-02-10', 'asti' => '2026-02-01']);
$vaarinpain['alkaen'] === '2026-02-01' || fail('swap alkaen');
$vaarinpain['asti'] === '2026-02-10' || fail('swap asti');
ok('väli kääntyy oikein');

$huono = kilpailuParsiaikavali(['alkaen' => 'ei-pvm', 'asti' => '2026-13-40']);
$huono['alkaen'] === $odotettuAlku || fail('huono alkaen');
ok('virheellinen päivä → oletus');

[$sql, $params] = kilpailuAikavaliSql('k', $vali);
str_contains($sql, 'BETWEEN ? AND ?') || fail('sql between');
$params === ['2026-01-01', '2026-01-31'] || fail('sql params');
ok('sql väli');

[$tyhjaSql] = kilpailuAikavaliSql('k', $kaikki);
$tyhjaSql === '' || fail('kaikki ei sql');
ok('kaikki ilman sql-rajaa');

$acl = file_get_contents(__DIR__ . '/../includes/acl.php');
str_contains($acl, 'function kilpailuSeuraNimi') || fail('seura nimi fn');
str_contains($acl, '?array $aikavali = null') || fail('haeKayttajanKilpailut aikavali');
ok('acl');

$index = file_get_contents(__DIR__ . '/../admin/index.php');
str_contains($index, 'Kaikki kilpailut') || fail('index otsikko');
str_contains($index, 'kilpailuAikavaliLomake') || fail('index lomake');
str_contains($index, 'seura_nimi') || fail('index seura');
ok('index');

$lista = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($lista, 'kilpailuAikavaliLomake') || fail('lista lomake');
ok('kilpailulista');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'active-kilpailu-seura') || fail('nav seura');
str_contains($nav, 'kilpailuSeuraNimi') || fail('nav seura fn');
ok('nav seura');

echo "all tests passed\n";
