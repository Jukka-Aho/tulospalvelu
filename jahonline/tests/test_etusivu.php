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

require_once __DIR__ . '/../includes/etusivu.php';

$pohja = static function (array $y): array {
    return $y + [
        'julkinen' => 1,
        'testikisa' => 0,
        'ilmoittautuminen_auki' => 0,
        'seura_nimi' => 'HS',
        'paikka' => 'Keskuspuisto',
    ];
};

$kisat = [];
for ($i = 1; $i <= 25; $i++) {
    $kisat[] = $pohja([
        'id' => $i,
        'nimi' => 'Valmis ' . $i,
        'tila' => 'valmis',
        'paiva' => sprintf('2026-01-%02d', $i),
    ]);
}
$kisat[] = $pohja([
    'id' => 100,
    'nimi' => 'Live nyt',
    'tila' => 'kaynnissa',
    'paiva' => '2025-05-01',
]);
$kisat[] = $pohja([
    'id' => 101,
    'nimi' => 'Ilmo auki',
    'tila' => 'ilmoittautuminen',
    'ilmoittautuminen_auki' => 1,
    'paiva' => '2026-12-01',
]);
$kisat[] = $pohja([
    'id' => 102,
    'nimi' => 'Tulossa hiljaa',
    'tila' => 'tulossa',
    'paiva' => '2026-11-20',
]);
$kisat[] = $pohja([
    'id' => 103,
    'nimi' => 'Vanha iltarasti',
    'tila' => 'valmis',
    'paiva' => '2024-06-01',
    'luonne' => 'iltarasti',
]);

$lista = etusivuValitseLista($kisat, 20);
$idt = array_map(static fn($k) => (int) $k['id'], $lista);
in_array(100, $idt, true) || fail('live mukana vaikka vanhempi');
in_array(101, $idt, true) || fail('ilmo auki mukana');
in_array(102, $idt, true) && fail('tulossa ilman ilmoa ei listaan');
in_array(103, $idt, true) && fail('vanha valmis yli 20 ei listaan');
$valmisLkm = 0;
foreach ($lista as $k) {
    if (($k['tila'] ?? '') === 'valmis') {
        $valmisLkm++;
    }
}
$valmisLkm === 20 || fail('valmiita 20 ei ' . $valmisLkm);
$nimet = array_column($lista, 'nimi');
$nimet[0] === 'Ilmo auki' || fail('uusin paiva ensin: ' . $nimet[0]);
ok('valinta');

$sivu = file_get_contents(__DIR__ . '/../public/index.php');
str_contains($sivu, 'etusivuValitseLista') || fail('index kayttaa valintaa');
str_contains($sivu, 'data-ryhma="tapahtumat"') || fail('yksi lista');
str_contains($sivu, "etusivuJaaSarakkeisiin") && fail('ei sarakkeita');
str_contains($sivu, "'avain' => 'iltarastit'") && fail('ei erillista iltarastit-ryhmaa');
str_contains($sivu, 'etusivuKisaTyyppiLabel') || fail('index tyyppi label');
str_contains($sivu, "'Iltarastit'") && fail('ei iltarastit-leimaa etusivulla');
ok('index');

require_once __DIR__ . '/../includes/kisa_luonne.php';
etusivuKisaTyyppiLabel(['luonne' => 'iltarasti', 'pirila_synkka' => 0, 'kisa_tyyppi' => 'normaali']) === 'Suunnistus'
    || fail('iltarasti etusivu suunnistus');
etusivuKisaTyyppiLabel(['luonne' => 'suunnistus', 'pirila_synkka' => 1, 'kisa_tyyppi' => 'pirila']) === 'Suunnistus'
    || fail('pirila etusivu suunnistus');
etusivuKisaTyyppiLabel(['luonne' => 'juoksu', 'pirila_synkka' => 0, 'kisa_tyyppi' => 'normaali']) === 'Juoksu'
    || fail('juoksu etusivu');
kisaOnIltarasti(['luonne' => 'suunnistus', 'pirila_synkka' => 0]) || fail('paikallinen suunnistus on iltarasti-ui');
kisaOnIltarasti(['luonne' => 'suunnistus', 'pirila_synkka' => 1, 'kisa_tyyppi' => 'pirila']) && fail('pirila ei iltarasti-ui');
kisaOnPirila(['luonne' => 'suunnistus', 'pirila_synkka' => 1, 'kisa_tyyppi' => 'pirila']) || fail('pirila tunnistus');
tapahtumaMalliKoodi(['luonne' => 'iltarasti', 'pirila_synkka' => 0]) === 'suunnistus' || fail('malli suunnistus');
tapahtumaMalliLabel(['luonne' => 'juoksu']) === 'Juoksu' || fail('malli juoksu');
tapahtumaMalliLabel(['luonne' => 'suunnistus', 'pirila_synkka' => 1, 'kisa_tyyppi' => 'pirila']) === 'Suunnistus-Pirilä'
    || fail('malli pirila');
ok('tyypit');

echo "all tests passed\n";
