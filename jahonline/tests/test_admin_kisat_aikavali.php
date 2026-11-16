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

kilpailuSuodatinSeuraId([]) === 0 || fail('seura oletus');
kilpailuSuodatinSeuraId(['seura_id' => '12']) === 12 || fail('seura id');
kilpailuSuodatinSeuraId(['seura_id' => '-1']) === -1 || fail('ilman seuraa');
kilpailuSuodatinSeuraId(['seura_id' => 'abc']) === 0 || fail('huono seura');
ok('seura parse');

$seuraGet = kilpailuParsiaikavali(['seura_id' => '7']);
$seuraGet['seura_id'] === 7 || fail('parse seura_id');
$seuraGet['kaikki'] === false || fail('seura ei poista päivää');
ok('parse seura + oletuspäivät');

[$seuraSql, $seuraP] = kilpailuAikavaliSql('k', ['kaikki' => true, 'seura_id' => 7]);
$seuraSql === 'k.seura_id = ?' || fail('seura sql kaikki-päivillä');
$seuraP === [7] || fail('seura param');
ok('seura sql säilyy kun kaikki päivät');

[$nullSql] = kilpailuAikavaliSql('k', ['kaikki' => true, 'seura_id' => -1]);
$nullSql === 'k.seura_id IS NULL' || fail('ilman seuraa sql');
ok('ilman seuraa sql');

$href = kilpailuAikavaliKaikkiHref('index.php', ['seura_id' => 9, 'kaikki' => false]);
str_contains($href, 'kaikki=1') || fail('href kaikki');
str_contains($href, 'seura_id=9') || fail('href seura');
ok('kaikki-href säilyttää seuran');

$acl = file_get_contents(__DIR__ . '/../includes/acl.php');
str_contains($acl, 'function kilpailuSeuraNimi') || fail('seura nimi fn');
str_contains($acl, '?array $aikavali = null') || fail('haeKayttajanKilpailut aikavali');
ok('acl');

$index = file_get_contents(__DIR__ . '/../admin/index.php');
str_contains($index, 'Kaikki kilpailut') || fail('index otsikko');
str_contains($index, 'kilpailuAikavaliLomake') || fail('index lomake');
str_contains($index, "kilpailuAikavaliLomake('index.php', \$kisaAikavali, \$pdo)") || fail('index seura pdo');
str_contains($index, 'seura_nimi') || fail('index seura');
ok('index');

$lista = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($lista, 'kilpailuAikavaliLomake') || fail('lista lomake');
str_contains($lista, "kilpailuAikavaliLomake('kilpailut.php', \$kisaAikavali, \$pdo)") || fail('lista seura pdo');
ok('kilpailulista');

$lomakeSrc = file_get_contents(__DIR__ . '/../includes/kilpailu_aikavali.php');
str_contains($lomakeSrc, 'name="seura_id"') || fail('lomake seura');
str_contains($lomakeSrc, 'Kaikki seurat') || fail('lomake kaikki seurat');
ok('lomake seura');

preg_match_all('/function ([a-zA-Z_][a-zA-Z0-9_]*)/', $lomakeSrc, $fnAikavali);
preg_match_all('/function ([a-zA-Z_][a-zA-Z0-9_]*)/', $lista, $fnLista);
$hit = array_intersect(
    array_map('strtolower', $fnAikavali[1]),
    array_map('strtolower', $fnLista[1])
);
$hit === [] || fail('funktionimitörmäys: ' . implode(',', $hit));
ok('ei törmäystä kilpailut.php:n kanssa');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'active-kilpailu-seura') || fail('nav seura');
str_contains($nav, 'kilpailuSeuraNimi') || fail('nav seura fn');
ok('nav seura');

echo "all tests passed\n";
