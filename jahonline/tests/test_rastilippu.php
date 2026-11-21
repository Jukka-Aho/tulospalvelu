<?php

require_once __DIR__ . '/../includes/rastilippu.php';
require_once __DIR__ . '/../includes/kisa_luonne.php';
require_once __DIR__ . '/../includes/radat.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/palvelu_valitsin.php';

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function ok($msg)
{
    echo "ok: $msg\n";
}

$t = rastilippuNormalisoiTapahtuma([
    'id' => 123,
    'uuid' => 'abc-def',
    'name' => 'Kuntorastit Seinäjoki',
    'date' => '15.4.2026',
    'venue' => 'Jouppi',
    'eventSeriesId' => 24076,
    'status' => 'Published',
]);
$t['id'] === 123 || fail('id');
$t['uuid'] === 'abc-def' || fail('uuid');
$t['nimi'] === 'Kuntorastit Seinäjoki' || fail('nimi');
$t['paiva'] === '2026-04-15' || fail('paiva ' . json_encode($t['paiva']));
$t['paikka'] === 'Jouppi' || fail('paikka');
$t['series_id'] === 24076 || fail('series');
ok('normalisoi tapahtuma');

$lista = rastilippuPoimiLista(['events' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]]);
count($lista) === 2 || fail('poimi events');
rastilippuKurssiNimiAvain('A-rata') === rastilippuKurssiNimiAvain('A') || fail('rata nimi avain');
rastilippuKurssiNimiAvain('Rata A') === rastilippuKurssiNimiAvain('A-rata') || fail('rata A vs A-rata');
rastilippuKurssiNimiAvain('Rata B') === rastilippuKurssiNimiAvain('B') || fail('rata B vs B');
$sov = rastilippuSovitaKurssit(
    [['id' => 'local-a', 'name' => 'A-rata'], ['id' => 'local-b', 'name' => 'B']],
    [['id' => 'remote-a', 'name' => 'A'], ['id' => 'remote-b', 'name' => 'B']]
);
($sov[0]['id'] ?? '') === 'remote-a' || fail('sovita A');
($sov[1]['id'] ?? '') === 'remote-b' || fail('sovita B');
$sovR = rastilippuSovitaKurssit(
    [['id' => 'local-a', 'name' => 'A-rata'], ['id' => 'local-b', 'name' => 'B']],
    [['id' => 'rl-a', 'name' => 'Rata A'], ['id' => 'rl-b', 'name' => 'Rata B']]
);
($sovR[0]['id'] ?? '') === 'rl-a' || fail('sovita Rata A');
($sovR[1]['id'] ?? '') === 'rl-b' || fail('sovita Rata B');
$sovPaino = rastilippuSovitaKurssit(
    [
        ['id' => 'ghost-b', 'name' => 'B', 'length' => 0, 'controls' => []],
        ['id' => 'real-b', 'name' => 'B-rata', 'length' => 2000, 'controls' => ['63', '64']],
    ],
    [['id' => 'remote-b', 'name' => 'B']]
);
($sovPaino[1]['id'] ?? '') === 'remote-b' || fail('paino B-rata ' . ($sovPaino[1]['id'] ?? ''));
($sovPaino[0]['id'] ?? '') === 'remote-b' || fail('paino haamu B jakaa idn');
$paiv = rastilippuValitsePaivitettavatKurssit(
    [
        ['id' => 'ghost-b', 'name' => 'B', 'length' => 0, 'controls' => [], 'order' => 1],
        ['id' => 'remote-b', 'name' => 'B-rata', 'length' => 2000, 'controls' => ['63', '64'], 'order' => 2],
        ['id' => 'uusi-c', 'name' => 'C', 'length' => 1000, 'controls' => ['1'], 'order' => 3],
    ],
    ['remote-b' => true]
);
count($paiv) === 2 || fail('paivita lkm ' . count($paiv));
($paiv[0]['id'] ?? '') === 'remote-b' || fail('paivita id');
($paiv[0]['name'] ?? '') === 'B-rata' || fail('paivita nimi');
($paiv[1]['id'] ?? '') === 'uusi-c' || fail('paivita uusi');
$omaId = rastilippuKurssiId(30, 103);
$suod = rastilippuSuodataOmatKurssit(
    [
        ['id' => $omaId, 'name' => 'A-rata'],
        ['id' => 'b8d1754f-a9d5-4eb6-ab65-455ee26ca260', 'name' => 'Rata A'],
    ],
    30,
    [['sarja_id' => 103, 'id' => $omaId, 'name' => 'A-rata']]
);
count($suod) === 2 || fail('suodata lkm');
rastilippuOnUuid($omaId) || fail('oma uuid');
$vanhaHaamu = rastilippuKurssiId(30, 104);
rastilippuUuidVersio($vanhaHaamu) === 5 || fail('generoitu v5');
rastilippuOnVirallinenKurssiId($vanhaHaamu) && fail('v5 ei virallinen');
rastilippuOnVirallinenKurssiId('b8d1754f-a9d5-4eb6-ab65-455ee26ca260') || fail('v4 virallinen');
$suodHaamu = rastilippuSuodataOmatKurssit(
    [['id' => $vanhaHaamu, 'name' => 'B-rata']],
    30,
    [['sarja_id' => 127, 'id' => rastilippuKurssiId(30, 127), 'name' => 'B-rata']]
);
count($suodHaamu) === 1 || fail('siirtynyt v5 rata kelpaa tuloksille');
rastilippuTapahtumaOnOmatoimi(['orienteering' => ['OT', 'S']]) || fail('omatoimi OT');
rastilippuTapahtumaOnOmatoimi(['disciplines' => 'OMATOIMIRASTIT, SUUNNISTUS']) || fail('omatoimi disc');
rastilippuTapahtumaOnOmatoimi(['orienteering' => ['S'], 'disciplines' => 'SUUNNISTUS']) && fail('suunnistus-kunto ei ole omatoimi');
rastilippuAikaRastilippuun('2026-11-13 00:02:16') === '13.11.2026 00:02:16' || fail('aika lite');
$n = rastilippuNormalisoiTapahtuma([
    'id' => 1, 'uuid' => 'x', 'name' => 'Eskoo', 'date' => '22.11.2026',
    'orienteering' => ['S'], 'disciplines' => 'SUUNNISTUS',
]);
empty($n['omatoimi']) || fail('eskoo ei omatoimi');
$n2 = rastilippuNormalisoiTapahtuma([
    'id' => 2, 'uuid' => 'y', 'name' => 'Jänis', 'date' => '09.04.2026',
    'orienteering' => ['OT', 'S'], 'disciplines' => 'OMATOIMIRASTIT, SUUNNISTUS',
]);
!empty($n2['omatoimi']) || fail('janis omatoimi');
ok('sovita kurssit nimella');
ok('poimi lista');

$materiaali = 'testi-avain-ei-tuotantoon';
$jwt = 'aaa.bbb.ccc';
$salattu = rastilippuSalaa($jwt, $materiaali);
str_starts_with($salattu, 'v1:') || fail('salaa prefix');
$salattu !== $jwt || fail('not plaintext');
rastilippuPura($salattu, $materiaali) === $jwt || fail('pura');
ok('salaus');

rastilippuJwtUuid('x.eyJ0b2tlbi11dWlkIjoiNzdhNjg3ODktMWIzNC00NjkwLWJjYjYtNjBkNzQ4ZTgzNGYwIn0.y')
    === '77a68789-1b34-4690-bcb6-60d748e834f0' || fail('jwt uuid');
ok('jwt uuid');

kisaOnIltarasti(['luonne' => 'iltarasti']) || fail('on iltarasti');
kisaOnIltarasti(['luonne' => 'suunnistus']) || fail('suunnistus on paikallinen');
kisaOnIltarasti(['luonne' => 'suunnistus', 'pirila_synkka' => 1, 'kisa_tyyppi' => 'pirila']) && fail('pirila ei iltarasti');
kisaOnIltarasti(['luonne' => 'juoksu']) && fail('juoksu ei iltarasti');
kisaLuonneLabel('iltarasti') === 'Iltarastit' || fail('label');
kisaNaytaValikko(['luonne' => 'iltarasti'], 'emit') || fail('emit valikko');
kisaNaytaValikko(['luonne' => 'iltarasti'], 'ratatiedot') || fail('radat valikko');
kisaNaytaValikko(['luonne' => 'iltarasti'], 'ajanotto') && fail('ei ajanottoa');
ok('luonne iltarasti');

iltarastiKeskhylLabel('E') === 'Esitys' || fail('esitys label');
iltarastiKeskhylLabel('T') === 'Hyväksytty' || fail('hyv label');
iltarastiTulosTila(['keskhyl' => '-']) === 'Maastossa' || fail('tila maastossa');
iltarastiTulosTila(['keskhyl' => 'T']) === 'Hyväksytty' || fail('tila hyv');
iltarastiStatusKeskhyl('E') === 'OK' || fail('esitys status sarakkeeseen OK');
iltarastiStatusKeskhyl('H') === 'DSQ' || fail('hyl status');
iltarastiStatusKeskhyl('K') === 'DNF' || fail('kes status');
$hki = new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki'));
iltarastiTanaan() === $hki->format('Y-m-d') || fail('tanaan helsinki');
iltarastiTilaPaivasta($hki->modify('+1 day')->format('Y-m-d')) === 'tulossa' || fail('tuleva tila');
iltarastiTilaPaivasta($hki->format('Y-m-d')) === 'kaynnissa' || fail('tanaan tila');
iltarastiTilaPaivasta('2000-01-01') === 'valmis' || fail('mennyt tila');
$tilaSrc = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($tilaSrc, 'function iltarastiTanaan') || fail('iltarastiTanaan');
str_contains($tilaSrc, 'iltarastiTanaan()') || fail('tila kayttaa tanaan');
iltarastiTulosTila(['keskhyl' => 'E']) === 'Esitys' || fail('tulos esitys');
iltarastiTulosTila(['keskhyl' => 'T']) === 'Hyväksytty' || fail('tulos hyv');
iltarastiTulosTila(['keskhyl' => 'H']) === 'Hylätty' || fail('tulos hyl');
iltarastiTulosTila(['keskhyl' => 'K']) === 'Keskeyttänyt' || fail('tulos kes');
iltarastiPaatosSallittu('T') || fail('paatos T');
iltarastiPaatosSallittu('E') && fail('paatos E ei');
$paatosSrc = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($paatosSrc, "'aika_txt'") || fail('paatos aika_txt');
ok('esitys ja paatos');

$koodit = radatKooditTekstista('31, 42 55;100');
count($koodit) === 4 || fail('koodit count ' . json_encode($koodit));
$koodit[0]['koodi'] === '31' && $koodit[3]['koodi'] === '100' || fail('koodit arvot');
ok('koodit tekstista');

$sivu = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($sivu, 'Rastilippu-avain') || fail('hub avain');
str_contains($sivu, 'luo_paikallinen') || fail('hub paikallinen');
str_contains($sivu, 'Henkilökohtainen') || fail('hub henkilo');
str_contains($sivu, 'value="viesti"') || fail('hub viesti');
str_contains($sivu, "\$sallitutMallit['suunnistus']") || fail('hub suunnistus');
str_contains($sivu, "\$sallitutMallit['juoksu']") || fail('hub juoksu');
str_contains($sivu, "\$sallitutMallit['pirila']") || fail('hub pirila');
str_contains($sivu, 'luo_tapahtuma') || fail('hub luo tapahtuma');
str_contains($sivu, 'Luo rastilipputapahtuma') && fail('hub ei luo rastilipusta');
str_contains($sivu, 'Yhdistä Rastilippu-tapahtumaan') && fail('hub ei yhdista-otsikkoa');
str_contains($sivu, 'Avaa tapahtuma') && fail('hub ei tupla-avaa');
str_contains($sivu, "GET['virhe'] !== '1'") || fail('hub ei näytä virhe=1');
str_contains($sivu, 'Liitä JWT-avain tai poista nykyinen avain.') || fail('hub tyhjä jwt ei pyyhi');
str_contains($sivu, "includes/acl.php") || fail('hub lataa acl (voiHallitaKilpailua)');
str_contains($sivu, 'name="alku_at"') && fail('hub ei alku_at');
str_contains($sivu, 'name="loppu_at"') && fail('hub ei loppu_at');
str_contains($sivu, 'datetime-local') && fail('hub ei datetime');
str_contains($sivu, 'iltarastiAsetuksetUrl') || fail('hub avaa asetuksiin');
str_contains($sivu, 'iltarastiTanaan()') || fail('hub paiva helsinki');
str_contains($sivu, 'type="date" name="paiva" required') || fail('hub paiva required');
str_contains($sivu, 'luo_seura') || fail('hub seuran nimi');
str_contains($sivu, 'Anna seuran nimi ennen') || fail('hub seuranimi vaaditaan');
str_contains($sivu, 'seurakohtainen') || fail('hub avain seurakohtainen');
str_contains($sivu, 'name="rastilippu_event_id"') && fail('hub rastilippu asetuksissa');
ok('iltarastit hub');

iltarastiKayttajaTasoLabel('jasen') === 'Iltarastikäyttäjä' || fail('taso kayttaja label');
iltarastiKayttajaTasoLabel('admin') === 'Pääkäyttäjä' || fail('taso paa label');
iltarastiKayttajaTasoLabel('omistaja') === 'Pääkäyttäjä' || fail('taso omistaja label');
ok('kayttajatasot');

iltarastiDtLomakkeeseen('2026-10-23 16:00:00') === '2026-10-23T16:00' || fail('dt lomake');
iltarastiDtKannasta('2026-10-23T16:00') === '2026-10-23 16:00:00' || fail('dt kanta');
iltarastiOletusAlku('2026-10-23') === '2026-10-23T16:00' || fail('oletus alku');
iltarastiOletusLoppu('2026-10-23') === '2026-10-23T21:00' || fail('oletus loppu');
iltarastiPvmKannasta('2026-11-06') === '2026-11-06' || fail('pvm kanta');
iltarastiPvmKannasta('2026-11-06T16:00') === '2026-11-06' || fail('pvm datetime');
iltarastiPvmKannasta('') === null || fail('pvm tyhja');
iltarastiPvmKannasta('huomenna') === null || fail('pvm virhe');
str_contains(iltarastiAikaVali('2026-10-23 16:00:00', '2026-10-23 21:00:00', '2026-10-23'), '16:00') || fail('aika vali');
ok('alku ja loppu');

$aset = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($aset, 'iltarastiTallennaAsetukset') || fail('asetukset tallenna');
str_contains($aset, 'name="testikisa"') || fail('asetukset demo');
str_contains($aset, 'name="paiva"') || fail('asetukset paiva');
str_contains($aset, 'type="date" name="paiva" required') || fail('asetukset paiva required');
str_contains(file_get_contents(__DIR__ . '/../includes/iltarasti.php'), 'Anna tapahtuman päivämäärä.') || fail('paiva pakollinen tallennuksessa');
str_contains($aset, 'datetime-local') && fail('asetukset ei datetime');
str_contains($aset, 'name="alku_at"') && fail('asetukset ei alku_at');
str_contains($aset, 'name="loppu_at"') && fail('asetukset ei loppu_at');
str_contains($aset, 'name="rastilippu_event_id"') || fail('asetukset rastilippu');
str_contains($aset, 'toistaiseksi vain henkilökohtaisissa') || fail('asetukset viesti ei rastilippua');
str_contains($aset, 'Radat, ajat ja väliajat') || fail('asetukset radat siirtyvat');
str_contains($aset, 'rastilippuTapahtumaOnOmatoimi') || fail('asetukset omatoimi fn');
str_contains($aset, 'iltarastiYhdistaRastilippuun') || fail('asetukset yhdista');
str_contains($aset, 'iltarastiVoiYhdistaa') || fail('asetukset yhdista oikeus');
str_contains($aset, 'paivita_tapahtumat') || fail('asetukset paivita');
str_contains($aset, 'iltarasti-paivita-lista') || fail('asetukset paivita levea');
str_contains($aset, 'Siirry tulospalveluun') || fail('asetukset siirry tulospalveluun');
str_contains($aset, 'moodi=lahto') || fail('asetukset sisaanluku');
str_contains($aset, 'iltarastiKuoriOpts') || fail('asetukset kuori');
str_contains($aset, 'radatMuotoileKm') || fail('asetukset matka km');
str_contains($aset, " . ' m'") && fail('asetukset matka ei metreina');
str_contains($aset, '>Rastit<') || fail('asetukset rastit');
str_contains($aset, 'sammunut') || fail('asetukset sammunut ohje');
str_contains($aset, 'action="radat.php"') || fail('asetukset poista radalle');
str_contains($aset, "GET['virhe']") || fail('asetukset poisto virhe');
$iltaSrc = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($iltaSrc, 'function iltarastiYhdistaRastilippuun') || fail('yhdista fn');
str_contains($iltaSrc, 'function iltarastiHaeRastilippuTapahtuma') || fail('hae rastilippu tapahtuma');
str_contains($iltaSrc, 'function iltarastiOnPaakayttaja') || fail('paa fn');
str_contains($iltaSrc, 'function iltarastiVoiAvaimen') || fail('avain fn');
preg_match('/function iltarastiVoiAvaimen\(\): bool\s*\{\s*return iltarastiOnPaakayttaja\(\);/', $iltaSrc) && fail('avain ei pelkalle sivuston paa');
str_contains($iltaSrc, "iltarastiSeuraRooli(), ['omistaja', 'admin']") || fail('avain vain seuran paa');
str_contains($iltaSrc, 'function iltarastiLuoKayttaja') || fail('luo kayttaja fn');
str_contains($iltaSrc, 'alku_at = NULL, loppu_at = NULL') || fail('asetukset nollaa kellot');
ok('asetukset sivu');

$login = file_get_contents(__DIR__ . '/../auth/login.php');
str_contains($login, 'unohdit.php') || fail('login unohdit');
$unohdit = file_get_contents(__DIR__ . '/../auth/unohdit.php');
str_contains($unohdit, 'salasanaResetPyydä') || fail('unohdit pyynto');
$uusi = file_get_contents(__DIR__ . '/../auth/uusi_salasana.php');
str_contains($uusi, 'salasanaResetKayta') || fail('uusi salasana kayta');
$resetSrc = file_get_contents(__DIR__ . '/../includes/salasana_reset.php');
str_contains($resetSrc, 'token_hash') || fail('reset hash');
str_contains($resetSrc, 'INTERVAL 2 HOUR') || fail('reset vanhenee');
str_contains($resetSrc, 'noreply@jahonline.com') || fail('reset from');
ok('salasanan unohtaminen');

$tul = file_get_contents(__DIR__ . '/../admin/iltarasti_tulokset.php');
str_contains($tul, 'seuranta.php') || fail('tulokset iframe');
str_contains($tul, 'embed=1') || fail('tulokset embed');
ok('tulokset sivu');

$emit = file_get_contents(__DIR__ . '/../public/emit_lukija.php');
str_contains($emit, 'id="emit-paatos"') || fail('paatos ui');
str_contains($emit, 'paatos-ok') || fail('hyv nappi');
str_contains($emit, 'iltarasti') || fail('iltarasti flag');
str_contains($emit, 'id="lainakortti"') || fail('lainakortti');
str_contains($emit, 'id="ei-aikaa"') || fail('ei aikaa');
str_contains($emit, 'id="emit-koodi"') || fail('kilpailukortti');
str_contains($emit, 'id="lukija-yhteys"') || fail('lukija tila');
str_contains($emit, 'Lukija yhdistetty') || fail('lukija yhdistetty teksti');
str_contains($emit, '<svg') && fail('ei lukija kuvaketta');
str_contains($emit, 'Yhdistä lukija') || fail('yhdista lukija');
str_contains($emit, 'iltarastiKuoriOpts') || fail('emit kuori');
str_contains($emit, 'data-no-page-loader') || fail('emit ei latausikonia ajaxissa');
str_contains($emit, 'emit-pari') || fail('emit tiivis seura/rata');
str_contains($emit, 'emit-rukit') || fail('emit rukit samalle riville');
str_contains($emit, 'emit-yhdista--kutsu') || fail('emit yhdista erottuu');
str_contains($emit, 'id="emit-tarkistus"') || fail('leimantarkastus ruutu');
str_contains($emit, 'id="emit-jono"') || fail('offline jono banneri');
str_contains($emit, 'id="emit-arvaus"') || fail('esitys arvaus');
$css = file_get_contents(__DIR__ . '/../assets/css/emit_lukija.css');
str_contains($css, '.emit-yhteys--ikoni') || fail('yhdista oma rivi');
str_contains($css, 'grid-column: 1 / -1') || fail('yhdista koko rivi');
ok('emit paatos ui');

$js = file_get_contents(__DIR__ . '/../assets/js/emit_lukija.js');
str_contains($js, 'naytaPaatos') || fail('js paatos');
str_contains($js, 'vaihda_rata') || fail('js rata');
str_contains($js, 'pois_maastosta') || fail('js pois');
str_contains($js, 'lukijaIkoni') || fail('js lukija tila');
str_contains($js, 'nLeima >= vanhaN') || fail('js valitsee leimallisen kortin');
str_contains($js, 'lainakortti') || fail('js laina');
str_contains($js, 'paivitaIltarastiNakyma') || fail('js leimantarkastus nakyma');
str_contains($js, 'is-leimantarkistus') || fail('js leimantarkistus luokka');
str_contains($js, 'naytaIltarastiMaali') || fail('js maali nimi ja aika');
str_contains($js, 'iltarastiTilaTiedot') || fail('js maali tila');
str_contains($js, 'cfg.iltarasti || uusi === \'maali\'') || fail('js kasin piiloon');
str_contains($js, 'asetaMoodi(\'maali\', { sailo: true })') || fail('js tunnettu kortti leimantarkastukseen');
str_contains($js, 'if (data.kysy)') || fail('js uusi kortti kysyy');
str_contains($js, 'opts.lomake') || fail('js maaliin voi lisata nimen');
str_contains($js, "tyyppi === 'esitys'") || fail('js esitys aani');
str_contains($js, 'avaaAani') || fail('js aani unlock');
str_contains($js, 'lahetaArvausVaihto') || fail('js arvaus nappi');
str_contains($js, 'jah_emit_jono_') || fail('js offline jono');
str_contains($js, 'tyhjennaJono') || fail('js jono flush');
str_contains($js, 'odottaaLeimat || []') || fail('js leimat kortilta');
str_contains($js, 'naytaLeimat(odottaaLeimat, samaNayttoEmit(sanoma.badge)') || fail('js nayttaa leimat heti');
str_contains($js, 'Kortin leimat') || fail('js kortin leimat otsikko');
str_contains($js, 'function vertaaLeimatRataan') || fail('js vertaa rataan');
str_contains($js, 'sammuneet.push') || fail('js sammunut ei vaadita');
str_contains($js, 'function paikallinenVastaus') || fail('js paikallinen vastaus');
str_contains($js, 'storage.persist') || fail('js persist');
str_contains($js, 'jah-emit-offline') || fail('js idb');
str_contains($emit, 'osanottajat:') || fail('emit osanottajat pack');
str_contains($css, 'max-height: min(55vh, 28rem)') || fail('leimat vieritettavat');
$style = file_get_contents(__DIR__ . '/../assets/css/style.css');
str_contains($style, 'overflow-x: clip') || fail('sivut eivat ylita leveytta');
$swJah = file_get_contents(__DIR__ . '/../sw-jahonline.js');
str_contains($swJah, 'emit_lukija.php') || fail('pwa cache emit');
str_contains($swJah, 'kilpailijat.php') || fail('pwa cache osanottajat');
str_contains($swJah, 'iltarastit.php') || fail('pwa cache hub');
str_contains($swJah, 'omat_tapahtumat.php') || fail('pwa cache omat tapahtumat');
str_contains($swJah, '/public/kilpailijat.php') || fail('pwa cache kioski osanottajat');
str_contains($swJah, 'jahonline-app-v58') || fail('pwa cache v58');
str_contains($swJah, 'jah-radat-offline') || fail('pwa cache radat js');
str_contains($swJah, 'ILTA_PREFETCH') || fail('pwa sw prefetch');
str_contains($swJah, 'ignoreSearch') || fail('pwa sw ignoreSearch');
$pwaJs = file_get_contents(__DIR__ . '/../assets/js/jah-pwa.js');
str_contains($pwaJs, 'jahonline-app-v58') || fail('pwa js cache v58');
str_contains($pwaJs, "scope: '/admin/'") || fail('pwa admin scope');
str_contains($pwaJs, "scope: '/public/'") || fail('pwa public scope');
str_contains($pwaJs, 'iltarasti-kioski') || fail('pwa kioski luokka');
str_contains($pwaJs, 'requestFullscreen') || fail('pwa kioski fullscreen');
str_contains($pwaJs, 'iltarastiOfflineSallittu') || fail('pwa offline vain iltarasti');
str_contains($pwaJs, 'Pysy tämän illan sivuilla') || fail('pwa offline esto');
str_contains($pwaJs, 'ILTA_PREFETCH') || fail('pwa prefetch viesti');
str_contains($pwaJs, 'iltarasti_asetukset.php') || fail('pwa prefetch asetukset');
str_contains($pwaJs, 'kilpailijat.php') || fail('pwa prefetch osanottajat');
str_contains($js, 'jahRataOffline') || fail('emit kayttaa offline ratoja');
str_contains($js, 'lahetaJonossa') || fail('emit ratajono ensin');
$radatJs = file_get_contents(__DIR__ . '/../assets/js/jah-radat-offline.js');
str_contains($radatJs, 'radat-') || fail('radat offline avain');
str_contains($radatJs, 'lahetaJonossa') || fail('radat offline lahetys');
$radatSivu = file_get_contents(__DIR__ . '/../admin/radat.php');
str_contains($radatSivu, 'jah-radat-offline') || fail('radat sivu offline');
$asetSivu = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($asetSivu, 'jah-radat-offline') || fail('asetukset radat offline');
$apiRadat = file_get_contents(__DIR__ . '/../api/api_radat.php');
str_contains($apiRadat, 'radatTallennaLomake') || fail('api radat tallennus');
$indexSrc = file_get_contents(__DIR__ . '/../public/index.php');
str_contains($indexSrc, 'jahNaytaOmatTapahtumat') || fail('etusivu omat tapahtumat');
str_contains($indexSrc, 'home-seura-kisat') && fail('etusivu ei omaa kisalistaa');
$layoutSrc = file_get_contents(__DIR__ . '/../includes/layout.php');
str_contains($layoutSrc, 'Omat tapahtumat') || fail('ylapalkki omat tapahtumat');
$valitsin = file_get_contents(__DIR__ . '/../includes/palvelu_valitsin.php');
str_contains($valitsin, "['href' => '/public/omat_tapahtumat.php'") || fail('valitsin omat tapahtumat');
str_contains($valitsin, "['href' => '/gps/index.php'") || fail('valitsin gps etusivu');
str_contains($valitsin, 'function jahNaytaOmatTapahtumat') || fail('valitsin nayta omat');
str_contains($valitsin, 'jahPalveluHamburgerOsio') || fail('valitsin hamburger palvelut');
$gpsNav = file_get_contents(__DIR__ . '/../gps/includes/gps_nav.php');
str_contains($gpsNav, 'gps-jah-menu-toggle') || fail('gps hamburger');
str_contains($gpsNav, 'jahPalveluHamburgerOsio') || fail('gps hamburger palvelut');
str_contains($js, 'tulkinta[osuma] = nroKartta[i]') || fail('js rastinro vain osumaan');
str_contains($js, 'rastiNro = vert.tulkinta[idx]') || fail('js ei nroa vaaralle');
str_contains($js, 'väärässä paikassa') || fail('js vaara paikassa');
str_contains($js, "'ylimääräinen'") && fail('js ei ylimääräinen-tekstiä');
str_contains($js, 'Puuttuu radalta') || fail('js puuttuvat rastit');
str_contains($js, 'onkoVerkkovirhe') || fail('js ei jonoa palvelinvirheesta');
ok('emit js');

require_once __DIR__ . '/../includes/emit_henkilot.php';
emitHenkilotAvain('Matti', 'Virtanen') === emitHenkilotAvain('matti', 'VIRTANEN') || fail('henkilo avain');
emitHenkilotNimiOk('Matti', 'Virtanen') || fail('nimi ok');
emitHenkilotNimiOk('', 'Virtanen') || fail('sukunimi riittaa');
emitHenkilotNimiOk('', '') && fail('tyhja nimi');
emitHenkilotNimiOk('', 'Emit 12345', 12345) && fail('placeholder nimi');
$yhd = emitHenkilotYhdistaListat(
    [['etunimi' => 'Aada', 'sukunimi' => 'Virtanen', 'seura' => 'RJ']],
    [['etunimi' => 'aada', 'sukunimi' => 'Virtanen', 'seura' => 'HS'], ['etunimi' => 'Liisa', 'sukunimi' => 'Korhonen', 'seura' => 'RJ']]
);
count($yhd) === 2 || fail('yhdista lkm ' . count($yhd));
$yhd[0]['seura'] === 'RJ' || fail('yhdista eka seura');
$yhd[1]['sukunimi'] === 'Korhonen' || fail('yhdista toinen');
$srcH = file_get_contents(__DIR__ . '/../includes/emit_lukija.php');
str_contains($srcH, 'emitHenkilotLiita') || fail('luku liittaa ehdotukset');
str_contains($srcH, 'emitHenkilotTallenna') || fail('luku tallentaa kortin');
$jsH = file_get_contents(__DIR__ . '/../assets/js/emit_lukija.js');
str_contains($jsH, 'naytaEhdotukset') || fail('js ehdotukset');
str_contains($jsH, 'emit-uusi-henkilo') || fail('js uusi henkilo');
str_contains($jsH, 'avaaKilpailija(emit)') || fail('js korttinumero hakee');
$sivuH = file_get_contents(__DIR__ . '/../public/emit_lukija.php');
str_contains($sivuH, 'id="emit-ehdotukset"') || fail('sivu ehdotukset');
str_contains($sivuH, 'Uusi henkilö tälle kortille') || fail('sivu uusi henkilo');
$cssH = file_get_contents(__DIR__ . '/../assets/css/emit_lukija.css');
str_contains($cssH, '.emit-ehdotukset') || fail('css ehdotukset');
ok('emit kortin henkilot');

$cssLukija = file_get_contents(__DIR__ . '/../assets/css/emit_lukija.css');
str_contains($cssLukija, 'is-leimantarkistus #lisaa-kentat') || fail('css leimantarkastus lomake');
str_contains($cssLukija, '.jah-mainos') || fail('css jah mainos');
str_contains($cssLukija, ':not(.jah-mainos)') || fail('css automaatti ei piilota mainosta');
str_contains($cssLukija, 'is-leimantarkistus #lisaa-kentat,') && fail('css ei piilota nimea leimantarkastuksessa');
str_contains($cssLukija, '100dvh - 6.2rem') && fail('css ei jaa tyhjaa leimaruutua');
str_contains($cssLukija, "body.iltarasti-kuori.emit-iltarasti #emit-tarkistus {\n    display: none;") && fail('css ei piilota leimoja');
str_contains($cssLukija, 'background: #fff8ee') || fail('css leimaruutu vaalea');
str_contains($cssLukija, '#yhdista[hidden]') || fail('css yhdista piiloon');
str_contains($cssLukija, '.emit-lukija-tila') || fail('css lukija tila');
str_contains($cssLukija, ".admin-container {\n    display: flex") || fail('css iltarasti container');
str_contains($cssLukija, '.emit-jono') || fail('css offline jono');
str_contains($cssLukija, '.emit-arvaus') || fail('css arvaus');
str_contains($cssLukija, 'word-spacing: 0.08em') || fail('css tulosruudun valit');
ok('emit css nimi leimantarkastuksessa');

$t2 = rastilippuNormalisoiTapahtuma([
    'id' => 9,
    'name' => 'Ilta',
    'venue' => ['name' => 'Jouppi'],
]);
$t2['paikka'] === 'Jouppi' || fail('venue object ' . json_encode($t2['paikka']));
ok('venue object');

$henk = file_get_contents(__DIR__ . '/../includes/iltarasti_henkilot_sivu.php');
str_contains($henk, 'osanottaja-nimet') || fail('henkilot nimilista');
str_contains($henk, 'osanottaja-valilehdet') || fail('henkilot sarjavälilehdet');
str_contains($henk, 'Maastossa') || fail('henkilot maastossa luku');
str_contains($henk, 'Lisää / kirjaa') && fail('henkilot lisays');
str_contains($henk, 'Maastoon ilman aikaa') && fail('henkilot maastoon');
str_contains($henk, 'Pois maastosta') || fail('henkilot pois');
str_contains($henk, 'muokkaa') || fail('henkilot muokkaa');
str_contains($henk, 'tulospalvelussa') || fail('henkilot linkki tulospalveluun');
str_contains($henk, 'tarkista_uudelleen') || fail('henkilot tarkista uudelleen');
str_contains($henk, 'iltarastiTarkistaKilpailijaUudelleen') || fail('henkilot tallennus tarkistaa');
str_contains($henk, 'Näytä leimat') || fail('henkilot nayta leimat');
str_contains($henk, 'name="tulos"') || fail('henkilot tulos muokattava');
str_contains($henk, 'name="aika"') || fail('henkilot aika muokattava');
str_contains($henk, 'osanottaja-aika') || fail('henkilot lista-aika');
str_contains($henk, 'iltarastiTallennaOsanottajaTulos') || fail('henkilot tallentaa tuloksen');
str_contains($henk, 'leimaKorttiNayttoHtml') || fail('henkilot leimat pirila');
str_contains($henk, 'iltarastiTarkistaMaastossaJoillaLeimat') || fail('henkilot heal maastossa+leimat');
ok('henkilot sivu');

$iltSrc = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($iltSrc, 'function iltarastiTarkistaKilpailijaUudelleen') || fail('uudelleentarkistus funktio');
str_contains($iltSrc, 'function iltarastiTarkistaRadanKilpailijat') || fail('radan kilpailijat funktio');
str_contains($iltSrc, 'function iltarastiTarkistaMaastossaJoillaLeimat') || fail('maastossa+leimat heal');
str_contains($iltSrc, 'function iltarastiVarmistaEiMaastossaLeimoilla') || fail('varmista ei maastossa');
str_contains($iltSrc, 'function iltarastiTallennaOsanottajaTulos') || fail('osanottaja tulos funktio');
str_contains($iltSrc, "in_array(\$k, ['T', 'E'], true)") || fail('tyhja aika laskee nollauksesta');
str_contains($iltSrc, 'iltarastiTallennaAikaKannasta') || fail('hyvaksytty laskee ajan');
str_contains($iltSrc, "in_array(\$kesk, ['H', 'K'], true)") || fail('uudelleentarkistus ei muuta H/K');
str_contains($iltSrc, "'iltarasti-tark'") || fail('aika-lahde mahtuu varchar(20)');
str_contains($iltSrc, "'iltarasti-uudelleentarkistus'") && fail('vanha liian pitkä lahde');
ok('iltarasti uudelleentarkistus');

iltarastiOnMaastossa(['keskhyl' => '-']) || fail('maastossa viiva');
iltarastiOnMaastossa(['keskhyl' => '']) || fail('maastossa tyhja');
iltarastiOnMaastossa(['keskhyl' => 'T']) && fail('ei maastossa T');
iltarastiOnMaastossa(['keskhyl' => 'E']) && fail('ei maastossa E');
$ry = iltarastiRyhmitteleSarjoittain(
    [['id' => 1, 'nimi' => 'A'], ['id' => 2, 'nimi' => 'B']],
    [
        ['sarja_id' => 1, 'keskhyl' => '-'],
        ['sarja_id' => 1, 'keskhyl' => 'T'],
        ['sarja_id' => 2, 'keskhyl' => 'E'],
    ]
);
count($ry) === 2 || fail('ryhma lkm');
$ry[0]['maastossa'] === 1 || fail('a maastossa');
$ry[1]['maastossa'] === 0 || fail('b ei maastossa');
count($ry[0]['henkilot']) === 2 || fail('a henkilot');
ok('osanottajat sarjoittain');

$ryh = ryhmitteleLiveTulokset([
    ['sarja' => 'A', 'nayta_ajat' => 1, 'keskhyl' => 'E', 'status' => 'ESITYS', 'maali_aika_sec' => null],
    ['sarja' => 'A', 'nayta_ajat' => 1, 'keskhyl' => 'H', 'status' => 'DSQ', 'maali_aika_sec' => null],
    ['sarja' => 'A', 'nayta_ajat' => 1, 'keskhyl' => 'T', 'status' => 'OK', 'maali_aika_sec' => null],
    ['sarja' => 'A', 'nayta_ajat' => 1, 'keskhyl' => 'T', 'status' => 'OK', 'maali_aika_sec' => 400],
    ['sarja' => 'A', 'nayta_ajat' => 1, 'keskhyl' => '-', 'status' => 'OK', 'maali_aika_sec' => null],
]);
count($ryh['A']['maalissa']) === 1 || fail('tulos aika maalissa');
count($ryh['A']['maastossa']) === 1 || fail('tulos maastossa');
count($ryh['A']['ei_maaleissa']) === 3 || fail('tulos statuslista ' . count($ryh['A']['ei_maaleissa']));
($ryh['A']['ei_maaleissa'][0]['status'] ?? '') === 'ESITYS' || fail('esitys ei ole OK');
ok('tulosryhmittely esitys');

$lkm = laskeMaalissaLkm([
    ['_maalissa' => true, '_status' => false, 'status' => 'OK'],
    ['_maalissa' => false, '_status' => true, 'status' => 'ESITYS', 'keskhyl' => 'E'],
    ['_maalissa' => false, '_status' => true, 'status' => 'DSQ', 'keskhyl' => 'H'],
]);
$lkm === 2 || fail('esitys ei kasvata maalissa-lukua ' . $lkm);
ok('esitys ei maalissa-luku');

$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'jah_iltarasti_kuori') || fail('nav kuori lippu');
str_contains($nav, 'kilpailuTilaLabel') || fail('nav tila suomeksi');
str_contains($nav, 'Tapahtuman asetukset') || fail('nav asetukset');
str_contains($nav, 'Osanottajat') || fail('nav osanottajat');
str_contains($nav, 'Tulospalvelu') || fail('nav tulospalvelu');
str_contains($nav, "active === 'tulokset'") || fail('nav tulokset');
str_contains($nav, "kioskiUrl('tulokset')") || fail('nav tulokset sivu');
str_contains($nav, 'Leimantarkastus') || fail('kisa nav leimantarkastus säilyy');
$kuoriPos = strpos($nav, 'if ($kuori)');
$kuoriPos !== false || fail('nav kuori haara');
$kuoriNav = substr($nav, $kuoriPos, 2800);
str_contains($kuoriNav, "kioskiUrl('tulokset')") || fail('kuori tulokset');
str_contains($kuoriNav, "kioskiUrl('osanottajat')") || fail('kuori kioski osanottajat');
str_contains($kuoriNav, 'iltarasti-kioski-sulje') || fail('kuori sulje');
str_contains($kuoriNav, '>Sulje tapahtuma</a>') || fail('kuori sulje teksti');
str_contains($nav, 'Kirjaudu ulos') || fail('nav kirjaudu ulos');
str_contains($nav, 'Siirry julkiselle etusivulle') || fail('nav julkinen etusivu');
str_contains($nav, 'iltarasti-ulos-osio') || fail('nav ulos erillaan');
str_contains($nav, 'jahPalveluHamburgerOsio') || fail('nav hamburger palvelut');
$kuoriUlosAlku = strpos($nav, "echo '<div class=\"admin-section iltarasti-ulos-osio\">'");
$kuoriUlosAlku !== false || fail('kuori ulos osio');
$kuoriUlosLoppu = strpos($nav, 'return;', $kuoriUlosAlku);
$kuoriUlosLoppu !== false || fail('kuori ulos return');
$kuoriUlos = substr($nav, $kuoriUlosAlku, $kuoriUlosLoppu - $kuoriUlosAlku);
str_contains($kuoriUlos, 'jahPalveluHamburgerOsio') && fail('kuori ei palvelut-osiota');
str_contains($nav, "if (\$kuori) {\n        echo '<div class=\"admin-section\">';\n        echo '<div class=\"section-label\">Asetukset</div>'") && fail('kuori ei asetukset-otsikkoa');
str_contains($kuoriNav, 'iltarasti_valiajat.php') && fail('kuori ei erillistä väliajat-linkkiä');
str_contains($kuoriNav, 'Leimantarkastus') && fail('kuori nav duplikaatti leimantarkastus');
str_contains($kuoriNav, 'iltarasti_lahetys') && fail('kuori ei tv-lahetys-ikkunaa');
ok('iltarasti nav kuori');
$asSrc = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($asSrc, 'laheta_rastilippu') || fail('asetukset rastilippu lahetys');
str_contains($asSrc, 'Lähetä tulokset Rastilippuun') || fail('asetukset rastilippu nappi');
function_exists('rastilippuLahetysTilaLyhyt') || fail('rastilippu tila lyhyt');
ok('rastilippu lahetys nappi');
$kioskiOs = file_get_contents(__DIR__ . '/../public/kilpailijat.php');
str_contains($kioskiOs, "admin/kilpailijat.php") || fail('kioski osanottajat wrapper');
$kioskiAs = file_get_contents(__DIR__ . '/../public/iltarasti_asetukset.php');
str_contains($kioskiAs, 'admin/iltarasti_asetukset.php') || fail('kioski asetukset wrapper');
$kioskiTu = file_get_contents(__DIR__ . '/../public/iltarasti_tulokset.php');
str_contains($kioskiTu, 'admin/iltarasti_tulokset.php') || fail('kioski tulokset wrapper');
str_contains($iltaSrc, 'function iltarastiKioskiUrl') || fail('kioski url fn');
str_contains($iltaSrc, 'iltarasti-kioski') || fail('kuori kioski luokka');
ok('iltarasti kioski osoitteet');

$seur = file_get_contents(__DIR__ . '/../public/seuranta.php');
str_contains($seur, "\$sarjaSana = \$onIltarasti ? 'Rata' : 'Sarja'") || fail('seuranta rata-sana');
str_contains($seur, 'status-esitys') || fail('seuranta esitys-luokka');
str_contains($seur, '$naytaRastiValiajat') || fail('seuranta iltarasti valiajat');
str_contains($seur, '$onPirila || $onIltarasti') || fail('seuranta iltarasti tulokset-välilehti');
str_contains($seur, 'seuranta-iltarasti') || fail('seuranta iltarasti luokka');
str_contains($seur, '$tulostaNumeroSolu') || fail('seuranta piilottaa nron iltarastissa');
preg_match_all('/<span class="lb-numero"/', $seur, $nroSpanit);
($nroSpanit[0] ?? []) === [] || fail('seuranta ei suoraa nro-spania');
ok('seuranta rata ja esitys');

$css = file_get_contents(__DIR__ . '/../assets/css/live_board.css');
str_contains($css, 'body.seuranta-iltarasti .lb-rivi.has-va') || fail('css iltarasti ilman nroa');
ok('seuranta css ilman nroa');

$hakuJs = file_get_contents(__DIR__ . '/../assets/js/tulos_haku.js');
str_contains($hakuJs, "classList.contains('seuranta-iltarasti')") || fail('haku piilottaa nron');
ok('tulos haku ilman nroa');

$vaSivu = file_get_contents(__DIR__ . '/../admin/iltarasti_valiajat.php');
str_contains($vaSivu, 'iltarasti_tulokset.php?nakyma=valiajat') || fail('valiajat ohjaa tuloksiin');
ok('iltarasti valiajat sivu');

$tulSivu = file_get_contents(__DIR__ . '/../admin/iltarasti_tulokset.php');
str_contains($tulSivu, 'nakyma=') || fail('tulokset nakyma iframeen');
ok('iltarasti tulokset sivu');

require_once __DIR__ . '/../includes/tili.php';
tiliSalasanaPituus('Abcdef1!') >= 8 || fail('salasana pituus');
tiliSalasanaLuokat('Abcdef1!') === 4 || fail('salasana 4 luokkaa');
tiliSalasanaLuokat('Abcdefgh') === 2 || fail('salasana vain kirjaimet');
tiliSalasanaLuokat('abcdef1!') === 3 || fail('salasana 3 luokkaa ilman isoa');
try {
    tiliValidoiSalasana('lyhyt1!');
    fail('lyhyt salasana');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), '8 merkkiä') || fail('salasana viesti');
}
try {
    tiliValidoiSalasana('Abcdefgh');
    fail('heikko salasana');
} catch (RuntimeException $e) {
}
try {
    tiliValidoiSalasana('Abcdef1!');
} catch (RuntimeException $e) {
    fail('hyva salasana ' . $e->getMessage());
}
tiliSahkopostiPuuttuu(['email' => '']) || fail('email puuttuu tyhja');
tiliSahkopostiPuuttuu(['email' => 'a@b.fi']) && fail('email on');
ok('salasana ja sahkoposti');

$login = file_get_contents(__DIR__ . '/../auth/login.php');
str_contains($login, 'sahkoposti.php') || fail('login ohjaa sahkopostiin');
is_readable(__DIR__ . '/../auth/sahkoposti.php') || fail('sahkoposti sivu');
$sahko = file_get_contents(__DIR__ . '/../auth/sahkoposti.php');
str_contains($sahko, 'tiliValidoiSahkoposti') || fail('sahkoposti tallentaa');
$authSrc = file_get_contents(__DIR__ . '/../includes/auth.php');
str_contains($authSrc, 'tiliVaadiSahkoposti') || fail('vaadi sahkoposti');
$omaHub = file_get_contents(__DIR__ . '/../admin/oma_seura.php');
str_contains($omaHub, 'name="email" required') || fail('oma seura email required');
str_contains($omaHub, 'minlength="8"') || fail('oma seura salasana 8');
ok('sahkoposti kirjautumisessa');

require_once __DIR__ . '/../includes/emit_lukija_logiikka.php';
emitLukijaNollaLeimoista([
    ['koodi' => 0, 'kello_sec' => 36000],
    ['koodi' => 31, 'kello_sec' => 36500],
]) === 36000 || fail('nolla kello');
emitLukijaNollaLeimoista([
    ['koodi' => 0, 'aika_sec' => 12],
    ['koodi' => 31, 'aika_sec' => 90],
]) === 12 || fail('nolla aika_sec');
$pisteet = emitLukijaLeimaPisteet(
    [['piste' => 1, 'rasti_koodi' => '31'], ['piste' => 2, 'rasti_koodi' => '32']],
    [['koodi' => 0], ['koodi' => 31], ['koodi' => 32]]
);
$pisteet === [0, 1, 2] || fail('nollaleima ei rastipistetta ' . json_encode($pisteet));
ok('nollaleima valiaikoihin');

$emitSrc = file_get_contents(__DIR__ . '/../includes/emit_lukija.php');
str_contains($emitSrc, 'if ($koodi < 0 || $koodi > 255') || fail('puhdista sailyttää nollan');
str_contains($emitSrc, 'emitLukijaKirjoitaValiajatLeimoista($pdo, $rivi, $lahde)') || fail('tarkistus kirjoittaa valiajat');
$js = file_get_contents(__DIR__ . '/../assets/js/emit_lukija.js');
str_contains($js, 'nollaSecLeimoista') || fail('js nolla leimoista');
ok('valiajat leimantarkastuksessa');

function_exists('rastilippuVieaineisto') || fail('vieaineisto fn');
function_exists('rastilippuLahetaTulokset') || fail('laheta fn');
function_exists('rastilippuJonota') || fail('jonota fn');
function_exists('rastilippuSovitaKurssit') || fail('sovita fn');
function_exists('rastilippuPuraJono') || fail('pura jono fn');
$rl = file_get_contents(__DIR__ . '/../includes/rastilippu.php');
str_contains($rl, "'kaytossa' => true") || fail('vienti kaytossa');
str_contains($rl, "'kaytossa' => false") && fail('vienti ei enää stub');
str_contains($rl, "event/' . rawurlencode(\$eventUuid) . '/courses") || fail('post courses');
str_contains($rl, "event/' . rawurlencode((string) \$cid) . '/results") || fail('post results courseId');
str_contains($rl, "event/results/' . rawurlencode(\$resultId)") || fail('delete result');
function_exists('rastilippuPoistaKilpailijanTulos') || fail('poista kilpailijan tulos fn');
function_exists('rastilippuPoistaTulos') || fail('poista tulos fn');
str_contains($rl, 'rastilippuValitsePaivitettavatKurssit') || fail('ei luo uutta rataa 403');
str_contains($rl, "events/' . rawurlencode(\$eventUuid) . '/registrations") || fail('get registrations');
str_contains($rl, 'CREATE TABLE IF NOT EXISTS rastilippu_ilmoittautuneet') || fail('ilmo taulu');
function_exists('rastilippuNormalisoiIlmoittautuminen') || fail('ilmo fn');
function_exists('rastilippuPaivitaIlmoittautumiset') || fail('ilmo paivita fn');
$ilmo = rastilippuNormalisoiIlmoittautuminen([
    'id' => 'rl-1',
    'firstName' => 'Aada',
    'lastName' => 'Virtanen',
    'club' => ['name' => 'Rasti-Jussit'],
    'emitNumber' => '123456',
    'loanCard' => true,
    'paid' => true,
    'registrationTime' => '2026-11-07T10:15:00+02:00',
]);
$ilmo['etunimi'] === 'Aada' || fail('ilmo etu');
$ilmo['sukunimi'] === 'Virtanen' || fail('ilmo suku');
$ilmo['seura'] === 'Rasti-Jussit' || fail('ilmo seura');
$ilmo['emit_koodi'] === 123456 || fail('ilmo emit');
$ilmo['laina'] === 1 || fail('ilmo laina');
$ilmo['maksu_ok'] === true || fail('ilmo maksu');
$ilmo['ulkoinen_id'] === 'rl-1' || fail('ilmo id');
$ilmo2 = rastilippuNormalisoiIlmoittautuminen([
    'person' => ['firstName' => 'Liisa', 'lastName' => 'Korhonen'],
    'emit' => '99',
    'paymentStatus' => 'unpaid',
]);
$ilmo2['etunimi'] === 'Liisa' || fail('ilmo nested etu');
$ilmo2['maksu_ok'] === false || fail('ilmo unpaid');
rastilippuIlmoMaksuTeksti(['maksu_ok' => true]) === 'Maksettu' || fail('maksu teksti');
$srcIlmo = file_get_contents(__DIR__ . '/../includes/emit_henkilot.php');
str_contains($srcIlmo, 'rastilippuIlmoittautuneetEmitilla') || fail('luku kayttaa ilmoittautuneita');
$sivuIlmo = file_get_contents(__DIR__ . '/../public/emit_lukija.php');
str_contains($sivuIlmo, 'nimet:') || fail('sivu nimet cfg');
str_contains($sivuIlmo, 'id="emit-maksu"') || fail('sivu maksu');
$osIlmo = file_get_contents(__DIR__ . '/../includes/iltarasti_henkilot_sivu.php');
str_contains($osIlmo, 'Päivitä Rastilipusta') || fail('osanottajat paivita ilmo');
str_contains($osIlmo, 'Rastilipun ilmoittautuneet') || fail('osanottajat ilmo otsikko');
$jsIlmo = file_get_contents(__DIR__ . '/../assets/js/emit_lukija.js');
str_contains($jsIlmo, 'Rastilipun ilmoittautuneet') || fail('js ilmo otsikko');
str_contains($jsIlmo, 'naytaMaksu') || fail('js maksu');
str_contains($jsIlmo, 'naytaMaksu(data)') || fail('js tayta maksu');
$ohjeIlmo = file_get_contents(__DIR__ . '/../public/ohjeet.php');
str_contains($ohjeIlmo, 'Päivitä Rastilipusta') || fail('ohjeet paivita ilmo');
ok('rastilippu ilmoittautuneet');
str_contains($rl, 'fastcgi_finish_request') || fail('tausta fastcgi');
str_contains($rl, 'register_shutdown_function') || fail('tausta shutdown');
ok('rastilippu vieaineisto');

rastilippuTilaKeskhylsta('T') === 0 || fail('tila T');
rastilippuTilaKeskhylsta('E') === 0 || fail('tila E');
rastilippuTilaKeskhylsta('H') === 1 || fail('tila H');
rastilippuTilaKeskhylsta('K') === 2 || fail('tila K');
rastilippuTilaKeskhylsta('-') === null || fail('tila maastossa');
rastilippuTilaKeskhylsta('') === null || fail('tila tyhja');
ok('rastilippu tila');

$id1 = rastilippuKurssiId(12, 34);
$id1 === rastilippuKurssiId(12, 34) || fail('kurssi uuid vakio');
$id1 !== rastilippuKurssiId(12, 35) || fail('kurssi uuid eri sarja');
preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id1) || fail('kurssi uuid v5');
rastilippuTulosId(9) === rastilippuTulosId(9) || fail('tulos uuid vakio');
$a = rastilippuTulosId(626);
$b = rastilippuTulosId(627);
$a !== $b || fail('tulos uuid eri hlö');
rastilippuPuuttuvatTulosIdt([$a, $b], [$a]) === [$b] || fail('puuttuva tulosid');
rastilippuPuuttuvatTulosIdt([$a], [$a, $b]) === [] || fail('ei poista uutta');
ok('rastilippu uuid');

$sov = rastilippuSovitaKurssit(
    [
        ['id' => 'oma-a', 'name' => 'Rata A'],
        ['id' => 'oma-b', 'name' => 'Rata B'],
    ],
    [
        ['id' => 'exist-a', 'name' => 'Rata A'],
        ['id' => 'exist-c', 'name' => 'Rata C'],
    ]
);
$sov[0]['id'] === 'exist-a' || fail('sovita A ' . $sov[0]['id']);
$sov[1]['id'] === 'oma-b' || fail('sovita B ' . $sov[1]['id']);
ok('rastilippu sovita kurssit');

$iltSrc = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
str_contains($iltSrc, 'rastilippuJonota') || fail('iltarasti jonota');
$emitSrc = file_get_contents(__DIR__ . '/../includes/emit_lukija.php');
str_contains($emitSrc, 'rastilippuJonota($pdo, $kilpailu)') || fail('emit jonota');
$henk = file_get_contents(__DIR__ . '/../includes/iltarasti_henkilot_sivu.php');
str_contains($henk, 'rastilippuJonota') || fail('henkilot jonota');
str_contains($henk, 'rastilippuPoistaKilpailijanTulos') || fail('henkilot poista rastilippu');
$aset = file_get_contents(__DIR__ . '/../admin/iltarasti_asetukset.php');
str_contains($aset, 'päivitetään Rastilippuun automaattisesti taustalla') || fail('asetukset lahetys ohje');
str_contains($aset, 'paikallisen tapahtuman Rastilippuun') || fail('asetukset yhdista teksti');
str_contains($aset, '/yhdistetty tapahtuma') || fail('asetukset hinta yhditetty');
is_readable(__DIR__ . '/../cron/rastilippu_lahetys.php') || fail('cron lahetys');
$cron = file_get_contents(__DIR__ . '/../cron/rastilippu_lahetys.php');
str_contains($cron, 'rastilippuPuraJono') || fail('cron pura jono');
$radatSivu = file_get_contents(__DIR__ . '/../admin/radat.php');
str_contains($radatSivu, 'rastilippuJonota') || fail('radat poisto jonota');
ok('rastilippu taustalahetys kytketty');

tiliRastilippuHintaEuro() === 5.0 || fail('rastilippu hinta 5e');
tiliEuroTeksti(5) === '5,00 €' || fail('euro teksti ' . tiliEuroTeksti(5));
tiliLaskutusVuosi('2026-11-06') === 2026 || fail('laskutusvuosi');
tiliLaskutusKuukausi('2026-11-06') === '2026-11' || fail('laskutuskuukausi');
tiliLaskutusKuukausiTeksti('2026-11') === 'marraskuu 2026' || fail('laskutuskuukausi teksti');
tiliSslHintaEuro() === 10.0 || fail('ssl hinta 10e');
tiliSeuraPalveluSarake('gps') === 'palvelu_gps' || fail('sarake gps');
tiliSeuraPalveluSarake('tulokset') === 'palvelu_kilpailut' || fail('sarake kilpailut');
tiliSeuraPalveluSarake('iltarastit') === 'palvelu_iltarastit' || fail('sarake ilta');
tiliSeuraPalveluOtsikko('kilpailut') === 'Juoksu' || fail('otsikko kilpailut');
tiliSeuraPalveluOtsikko('iltarastit') === 'Suunnistus' || fail('otsikko suunnistus');
tiliSeuraPalveluOtsikko('pirila') === 'Suunnistus-Pirilä' || fail('otsikko pirila');
tiliSeuraPalveluSarake('pirila') === 'palvelu_pirila' || fail('sarake pirila');
tiliSeuraPalveluPaalla(['palvelu_gps' => 1, 'palvelu_kilpailut' => 0, 'palvelu_iltarastit' => 1], 'gps') || fail('paalla gps');
tiliSeuraPalveluPaalla(['palvelu_gps' => 1, 'palvelu_kilpailut' => 0, 'palvelu_iltarastit' => 1], 'kilpailut') && fail('kiinni kilpailut');
tiliSeuraPalveluPaalla(['palvelu_gps' => 1, 'palvelu_kilpailut' => 0, 'palvelu_iltarastit' => 1], 'iltarastit') || fail('paalla ilta');
tiliSeuraVainIltarastit(['palvelu_gps' => 0, 'palvelu_kilpailut' => 0, 'palvelu_iltarastit' => 1]) || fail('vain iltarastit');
tiliSeuraVainIltarastit(['palvelu_gps' => 1, 'palvelu_kilpailut' => 0, 'palvelu_iltarastit' => 1]) && fail('ei vain iltarastit gps');
tiliSeuraRastilippuPaalla(['palvelu_iltarastit' => 1, 'rastilippu_liitanta' => 1]) || fail('liitanta paalla');
tiliSeuraRastilippuPaalla(['palvelu_iltarastit' => 1, 'rastilippu_liitanta' => 0]) && fail('liitanta kiinni');
tiliSeuraRastilippuPaalla(['palvelu_iltarastit' => 0, 'rastilippu_liitanta' => 1]) && fail('liitanta ilman iltarasteja');
tiliSeuraViestiPaalla(['palvelu_iltarastit' => 1, 'viesti_kilpailu' => 1]) || fail('viesti paalla');
tiliSeuraViestiPaalla(['palvelu_iltarastit' => 1, 'viesti_kilpailu' => 0]) && fail('viesti kiinni');
tiliSeuraViestiPaalla(['palvelu_iltarastit' => 0, 'viesti_kilpailu' => 1]) && fail('viesti ilman iltarasteja');
ok('seura palvelut');

$seuraSivu = file_get_contents(__DIR__ . '/../admin/seurat.php');
str_contains($seuraSivu, 'Seurojen hallinta') || fail('seurat otsikko');
str_contains($seuraSivu, 'palvelu_gps') || fail('seurat gps');
str_contains($seuraSivu, 'palvelu_kilpailut') || fail('seurat kilpailut');
str_contains($seuraSivu, 'palvelu_iltarastit') || fail('seurat iltarastit');
str_contains($seuraSivu, 'palvelu_pirila') || fail('seurat pirila');
str_contains($seuraSivu, 'name="viesti_kilpailu"') || fail('seurat viesti');
str_contains($seuraSivu, 'tiliTallennaSeuraViesti') || fail('seurat tallenna viesti');
str_contains($seuraSivu, 'tiliRastilippuLaskutus') || fail('seurat rastilippu lasku');
str_contains($seuraSivu, 'tiliKuukausiLaskutusKaikki') || fail('seurat kuukausilasku');
str_contains($seuraSivu, 'laskutus_email') || fail('seurat laskutusposti');
str_contains($seuraSivu, 'Laskutus') || fail('seurat laskutusotsikko');
str_contains($seuraSivu, 'laskutus kuukausittain') || fail('seurat kuukausilaskutus');
str_contains($seuraSivu, 'SSL-tulokset') || fail('seurat ssl lasku');
str_contains($seuraSivu, 'tiliTallennaSeuraPalvelut') || fail('seurat tallenna');
str_contains($seuraSivu, 'name="ei_laskutusta"') || fail('seurat ei laskutusta');
str_contains($seuraSivu, 'Ei laskutusta') || fail('seurat ei laskutusta teksti');
str_contains($seuraSivu, 'tiliTallennaSeuraEiLaskutusta') || fail('seurat tallenna ei laskutusta');
$oma = file_get_contents(__DIR__ . '/../admin/oma_seura.php');
str_contains($oma, 'GPS-paketti') || fail('oma gps paketti');
str_contains($oma, 'tiliSeuraPalveluPaalla($seura, \'gps\')') || fail('oma gps ehdollinen');
str_contains($oma, 'rastilippu_liitanta') || fail('oma liitanta');
str_contains($oma, 'laskutus_email') || fail('oma laskutusposti');
str_contains($oma, 'name="ei_laskutusta"') && fail('oma ei admin-laskutusvalintaa');
str_contains($oma, 'Lisää iltarastikäyttäjä') || fail('oma lisaa iltarasti');
str_contains($oma, 'tiliRastilippuLaskutus') || fail('oma rastilippu');
str_contains($oma, 'vaihda_seura') || fail('oma vaihda seura');
str_contains($oma, 'Lisää seura') || fail('oma lisaa seura');
str_contains($oma, 'muokkaa_jasen') || fail('oma muokkaa jasen');
str_contains($oma, 'lopeta_seura') || fail('oma lopeta seura');
str_contains($oma, 'Poista seurasta') || fail('oma poista seurasta');
str_contains($seuraSivu, 'poista_seura') || fail('seurat poista seura');
str_contains($seuraSivu, 'Poista seura ja sen käyttäjät') || fail('seurat poista nappi');
str_contains($usersSrc = file_get_contents(__DIR__ . '/../admin/users.php'), 'tiliPoistaKayttaja') || fail('users poista jasenet');
$hub = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($hub, 'valinnainen lisäominaisuus') || fail('hub lisäominaisuus');
str_contains($hub, 'tiliSeuraRastilippuPaalla') || fail('hub vaatii liitannan');
$reg = file_get_contents(__DIR__ . '/../auth/register.php');
str_contains($reg, 'iltarastien pääkäyttäjäksi') || fail('register iltarasti');
$tiliSrc = file_get_contents(__DIR__ . '/../includes/tili.php');
str_contains($tiliSrc, "'iltarasti'") || fail('rekisteroi role iltarasti');
str_contains($tiliSrc, 'palvelu_iltarastit = 1') || fail('uusi seura iltarastit');
str_contains($tiliSrc, 'function tiliPoistaSeuraJaKayttajat') || fail('tili poista seura');
str_contains($tiliSrc, 'function tiliVaihdaAktiivinenSeura') || fail('tili vaihda seura');
$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'Seurojen hallinta') || fail('nav seurat');
str_contains($nav, "tiliSessioSeuraPalvelu('gps')") || fail('nav gps ehdollinen');
str_contains($nav, 'Pirilä api-avain') || fail('nav pirila api-avain');
strpos($nav, "linkit['tv']") < strpos($nav, 'public-nav') || fail('nav tv ei julkisessa');
strpos($nav, 'QR-koodi') > strpos($nav, 'lahdot.php') || fail('nav qr valmistelussa');
strpos($nav, 'tulokset_vienti.php') < strpos($nav, 'section-label">Tulokset') || fail('nav vienti kilpailutoiminnassa');
strpos($nav, 'sarjat.php') < strpos($nav, 'lahdot.php') || fail('nav sarjat ennen lahtoja');
strpos($nav, 'lahdot.php') < strpos($nav, 'kilpailijat.php') || fail('nav lahdot ennen kilpailijoita');
str_contains($nav, '/admin/pirila_api_avain.php') || fail('nav pirila api sivu');
str_contains($nav, '>API-avain<') && fail('nav vanha api-avain nimi');
$hallinta = file_get_contents(__DIR__ . '/../admin/index.php');
str_contains($hallinta, 'apiAvainKortti') && fail('hallinta ei nayta avainta ylhäällä');
str_contains($hallinta, 'checklist-valmiit') || fail('hallinta valmiit nuoli');
str_contains($hallinta, '>Valmiit<') || fail('hallinta valmiit otsikko');
$valm = strpos($hallinta, '>Valmistelu<');
$toim = strpos($hallinta, '>Kilpailutoiminnat<');
$julk = strpos($hallinta, '>Julkinen<');
$qr = strpos($hallinta, 'kioski_qr.php');
$jako = strpos($hallinta, 'Numeroiden jako');
$tv = strpos($hallinta, "linkit['tv']");
$vienti = strpos($hallinta, 'tulokset_vienti.php');
$sarjat = strpos($hallinta, 'sarjat.php', $valm);
$lahdot = strpos($hallinta, 'lahdot.php', $valm);
$kilp = strpos($hallinta, 'kilpailijat.php', $valm);
($valm !== false && $toim !== false && $julk !== false) || fail('hallinta ryhmät');
($qr !== false && $qr > $valm && $qr < $toim) || fail('qr valmistelussa');
($jako !== false && $jako > $valm && $jako < $toim) || fail('jako valmistelussa');
$ilmo = strpos($hallinta, 'Ilmo paikan päällä');
($ilmo !== false && $ilmo > $qr && $ilmo < $jako) || fail('ilmo paikan paalla ennen jakoa');
str_contains($hallinta, 'Kioski-ilmo') && fail('hallinta ei kioski-ilmo nimea');
str_contains($nav, 'Ilmo paikan päällä') || fail('nav ilmo paikan paalla');
str_contains($nav, 'Kioski-ilmo') && fail('nav ei kioski-ilmo nimea');
strpos($nav, 'Ilmo paikan päällä') < strpos($nav, 'Numeroiden jako') || fail('nav ilmo ennen jakoa');
($tv !== false && $tv > $toim && $tv < $julk) || fail('tulostaulu kilpailutoiminnassa');
str_contains($hallinta, '>Tulostaulu<') || fail('tulostaulu nappi');
str_contains($hallinta, '>TV<') && fail('ei tv tekstiä hallinnassa');
str_contains($nav, '>Tulostaulu<') || fail('nav tulostaulu');
strpos($nav, 'Numeroiden jako') > strpos($nav, 'section-label--sub">Valmistelu') || fail('nav jako valmistelussa');
($vienti !== false && $vienti > $toim && $vienti < $julk && $vienti > $tv) || fail('vienti kilpailutoiminnassa alimmaisena');
($sarjat !== false && $lahdot !== false && $kilp !== false && $sarjat < $lahdot && $lahdot < $kilp && $kilp < $qr) || fail('valmistelu jarjestys');
substr_count($hallinta, "linkit['tv']") === 1 || fail('tv vain kerran hallinnassa');
str_contains($hallinta, 'hallinta-kisa-rivit') || fail('hallinta omat rivit');
str_contains($hallinta, 'hallinta-kisa-loki-rivi') || fail('loki oma rivi luokka');
$julkRivit = strpos($hallinta, 'hallinta-kisa-rivit', $julk);
($julkRivit !== false && $julkRivit < strpos($hallinta, 'kisapaiva-checklist')) || fail('julkinen omat rivit');
$mobiili = strpos($hallinta, "linkit['mobiili']");
$pari = strpos($hallinta, 'hallinta-maalinappi-pari');
$nappi = strpos($hallinta, "linkit['nappi']");
$numerot = strpos($hallinta, "linkit['numerot']");
$loki = strpos($hallinta, 'ajanotto_loki.php');
str_contains($hallinta, '>Maalinappi + Numerot<') || fail('maalinappi numerot otsikko');
($pari !== false && $pari > $toim && $pari < $julk) || fail('maalinappi numerot kehys');
($mobiili !== false && $mobiili > $toim && $mobiili < $pari) || fail('mobiiliajanotto ennen paria');
($nappi !== false && $numerot !== false && $nappi > $pari && $numerot > $nappi && $numerot < $loki) || fail('pari nappi sitten numerot');
($loki !== false && $loki > $numerot && $loki < $julk) || fail('loki parin jalkeen');
strpos($nav, 'section-label--sub">Ajanotto') !== false || fail('nav ajanotto otsikko');
strpos($nav, 'section-label--sub">Maalinappi + Numerot') !== false || fail('nav maalinappi pari');
preg_match("/linkit\\['nappi'\\][^\\n]*target=/", $hallinta) && fail('maalinappi ei custom tab');
preg_match("/linkit\\['mobiili'\\][^\\n]*target=/", $hallinta) && fail('mobiili ei custom tab');
preg_match("/linkit\\['lahtokello'\\][^\\n]*target=/", $hallinta) && fail('lahtokello ei custom tab');
preg_match("/linkit\\['nimenhuuto'\\][^\\n]*target=/", $hallinta) && fail('nimenhuuto ei custom tab');
preg_match("/linkit\\['numerot'\\][^\\n]*target=/", $hallinta) && fail('numerot ei custom tab');
str_contains($hallinta, "asetaValittuKilpailu(\$getKisa)") || fail('hallinta get kisa');
str_contains($clSrc = file_get_contents(__DIR__ . '/../includes/kisapaiva_checklist.php'), "'samaIkkuna' => true") || fail('checklist maalinappi sama ikkuna');
$nappiSivu = file_get_contents(__DIR__ . '/../public/mobiili_ajanotto.php');
strpos($nappiSivu, 'id="nappiViimeiset"') < strpos($nappiSivu, 'id="btnMaaliNappi"') || fail('maali nappi alhaalla');
strpos($nappiSivu, 'mobiili-header-meta') < strpos($nappiSivu, 'id="jonoYhteenveto"') || fail('yhteenveto ylapalkin ala');
strpos($nappiSivu, 'id="jonoYhteenveto"') < strpos($nappiSivu, 'nappi-paneeli') || fail('yhteenveto ennen paneelia');
str_contains($nappiSivu, 'maalinappi_manifest.php') || fail('maalinappi manifest');
$cssNappi = file_get_contents(__DIR__ . '/../assets/css/maali_jono.css');
str_contains($cssNappi, 'body.nappi-page .maali-nappi-btn') || fail('maali nappi css ala');
str_contains($cssNappi, 'button.maali-nappi-btn:hover') || fail('maali nappi hover vihrea');
str_contains($cssNappi, 'button.maali-nappi-btn.is-flash') || fail('maali nappi valahdys');
$jsNappi = file_get_contents(__DIR__ . '/../assets/js/maali_jono.js');
str_contains($jsNappi, "classList.remove('is-flash')") || fail('maali nappi flash pois');
preg_match('/nappiViimeiset[\s\S]{0,400}leima_id[\s\S]{0,120}\.reverse\(/', $jsNappi) && fail('maali nappi ajat ei uusin ylös');
str_contains($jsNappi, 'nappiViimeiset.scrollTop = nappiViimeiset.scrollHeight') || fail('maali nappi scroll uusimpaan');
str_contains($jsNappi, 'rivitAsc.slice().reverse()') && fail('numerot ajat ei uusin ylös');
str_contains($jsNappi, 'listaEl.scrollTop = listaEl.scrollHeight') || fail('numerot scroll uusimpaan');
str_contains($jsNappi, 'data-act="ylos"') && str_contains($jsNappi, 'aria-label="Siirrä ylös">↑') || fail('numerot nuoli ylos');
str_contains($cssNappi, 'orientation: landscape') || fail('numerot tablet landscape');
str_contains($cssNappi, 'body.numerot-page .numerot-paneeli > .jono-nappis') || fail('numerot keypad oikea');
str_contains($cssNappi, 'minmax(18rem, 24rem)') || fail('numerot keypad isompi');
str_contains($cssNappi, 'width: 12.6rem') && fail('numerot keypad ei kapea');
str_contains($cssNappi, 'mobiili-header-yhteenveto') || fail('yhteenveto ylapalkki css');
str_contains($cssNappi, 'safe-area-inset-right') || fail('poistu sisaan reunasta');
str_contains($jsNappi, 'yhteenvetoTeksti') || fail('yhteenveto teksti js');
is_readable(__DIR__ . '/../public/maalinappi_manifest.php') || fail('maalinappi manifest tiedosto');
$mf = file_get_contents(__DIR__ . '/../public/maalinappi_manifest.php');
str_contains($mf, "'display' => 'fullscreen'") || fail('maalinappi display fullscreen');
$tvHtml = __DIR__ . '/../public/tv_tulokset.html';
if (is_readable($tvHtml)) {
    $tvSrc = file_get_contents($tvHtml);
    str_contains($tvSrc, '/admin/index.php?kilpailu_id=') || fail('tv takaisin hallintaan');
    str_contains($tvSrc, 'history.back') && fail('tv ei history.back');
}
strpos($nav, 'ajanotto_loki.php') < strpos($nav, 'section-label">Tulokset') || fail('nav loki ajanotossa');
str_contains($nav, 'admin-nav-alaryhma-rivi') || fail('nav loki omalla rivilla');
$kisatSivu = file_get_contents(__DIR__ . '/../admin/kilpailut.php');
str_contains($kisatSivu, 'apiAvainKortti') && fail('kilpailut ei nayta avainta ylhäällä');
str_contains($kisatSivu, 'Vanha kilpailukohtainen avain') && fail('kilpailut ei vanhaa avainta');
is_readable(__DIR__ . '/../admin/pirila_api_avain.php') || fail('pirila api-avain sivu');
$clSrc = file_get_contents(__DIR__ . '/../includes/kisapaiva_checklist.php');
str_contains($clSrc, 'API-avain ajanottoon') && fail('checklist ei api-avainta');
str_contains($clSrc, 'function kisapaivaChecklistJaaKohdat') || fail('checklist jako');
require_once __DIR__ . '/../includes/kisapaiva_checklist.php';
$jako = kisapaivaChecklistJaaKohdat([
    'kohdat' => [
        ['ok' => 1, 'otsikko' => 'valmis'],
        ['ok' => 0, 'otsikko' => 'huomio'],
    ],
]);
count($jako['valmiit']) === 1 || fail('jako valmiit');
count($jako['huomio']) === 1 || fail('jako huomio');
$jako['huomio'][0]['otsikko'] === 'huomio' || fail('jako huomio rivi');
ok('seurojen hallinta ui');

$ohje = file_get_contents(__DIR__ . '/../public/ohjeet.php');
str_contains($ohje, 'jahOhjeetOsiot') || fail('ohjeet osiot');
str_contains($ohje, "if (\$naytaKilpailut)") || fail('ohjeet kilpailut ehto');
str_contains($ohje, "if (\$naytaGps)") || fail('ohjeet gps ehto');
str_contains($ohje, 'id="iltarastit"') || fail('ohjeet iltarastit osio');
str_contains($ohje, 'Kilpailunumeroita ei näytetä') || fail('ohjeet ei nroa');
str_contains($ohje, 'Sammunut') || fail('ohjeet sammunut');
str_contains($ohje, 'Yhdistä lukija') || fail('ohjeet yhdista lukija');
str_contains($ohje, 'Uusi henkilö tälle kortille') || fail('ohjeet uusi henkilo');
str_contains($ohje, 'Lähteväksi') || fail('ohjeet lahtevaksi');
str_contains($ohje, 'väärässä paikassa') || fail('ohjeet vaara paikassa');
str_contains($ohje, '/yhdistetty tapahtuma') || fail('ohjeet hinta yhditetty');
str_contains($ohje, 'yhtenä sarakkeena') || fail('ohjeet sarake');
str_contains($ohje, 'JWT') && fail('ohjeet ei jwt-jargonia');
preg_match('/if \(\$naytaKilpailut\):[\s\S]*Juoksukisa/', $ohje) || fail('juoksukisa kilpailut-osiossa');
preg_match('/^[\s\S]*Juoksukisa[\s\S]*if \(\$naytaKilpailut\):/m', $ohje) && fail('juoksukisa ennen ehtoa');
ok('ohjeet sivu');

$vanha = $_SESSION ?? [];
$_SESSION = [];
$o = jahOhjeetOsiot();
$o['iltarastit'] || fail('vieras iltarastit');
$o['kilpailut'] && fail('vieras ei kilpailuja');
$o['gps'] && fail('vieras ei gps');
$o['tunnus'] && fail('vieras ei tunnusta osiossa');
$o['seura_paa'] && fail('vieras ei paa');
ok('ohjeet vieras vain iltarastit');

$_SESSION = [
    'user' => 'ilta',
    'role' => 'iltarasti',
    'seura_rooli' => 'jasen',
    'seura_palvelut' => 'iltarastit',
    'seura_palvelu_gps' => 0,
    'seura_palvelu_kilpailut' => 0,
    'seura_palvelu_iltarastit' => 1,
];
$o = jahOhjeetOsiot();
$o['iltarastit'] || fail('jasen iltarastit');
$o['kilpailut'] && fail('jasen ei kilpailuja');
$o['gps'] && fail('jasen ei gps');
$o['tunnus'] || fail('jasen tunnus');
$o['seura_paa'] && fail('jasen ei paa');
ok('ohjeet iltarastikasittaja');

$_SESSION['seura_rooli'] = 'omistaja';
$o = jahOhjeetOsiot();
$o['seura_paa'] || fail('omistaja paa');
$o['iltarastit'] || fail('omistaja iltarastit');
$o['kilpailut'] && fail('omistaja ei kilpailuja');
ok('ohjeet paakayttaja vain iltarastit');

$_SESSION = [
    'user' => 'admin',
    'role' => 'admin',
];
$o = jahOhjeetOsiot();
$o['iltarastit'] || fail('admin iltarastit');
$o['kilpailut'] || fail('admin kilpailut');
$o['gps'] || fail('admin gps');
$o['seura_paa'] || fail('admin paa');
ok('ohjeet admin kaikki');

$_SESSION = [
    'user' => 'kisa',
    'role' => 'paakayttaja',
    'seura_rooli' => 'omistaja',
    'seura_palvelut' => 'tulokset',
    'seura_palvelu_gps' => 0,
    'seura_palvelu_kilpailut' => 1,
    'seura_palvelu_iltarastit' => 0,
];
$o = jahOhjeetOsiot();
$o['kilpailut'] || fail('kisa kilpailut');
$o['iltarastit'] && fail('kisa ei iltarasteja');
$o['gps'] && fail('kisa ei gps');
ok('ohjeet vain kilpailut');

$_SESSION = $vanha;

$layout = file_get_contents(__DIR__ . '/../includes/layout.php');
$themeJs = file_get_contents(__DIR__ . '/../assets/js/theme.js');
str_contains($themeJs, 'function paintFrame') || fail('teema iframe suoraan');
str_contains($themeJs, "stored === 'dark' || stored === 'light'") || fail('teema localStorage ensin');
str_contains($layout, 'try{t=localStorage.getItem(k)}catch(e){}') || fail('boot ei ylikirjoita teemaa');
$kisaPhp = file_get_contents(__DIR__ . '/../public/kilpailu.php');
str_contains($kisaPhp, "params.delete('teema')") || fail('kuori ei lukitse teemaa urlista');
str_contains($layout, "(\$opts['palvelu'] ?? '') === 'iltarastit'") || fail('kuori iltarastipalvelulle');
str_contains($layout, 'iltarasti-kuori') || fail('kuori luokka');
str_contains($layout, 'data-jah-kilpailu') || fail('kuori kilpailu id');
str_contains($layout, 'private, max-age=0') || fail('kuori cache header');
str_contains($layout, 'iltarasti-yhteys') || fail('kuori yhteyden tila');
str_contains($pwaJs, 'Ei verkkoa') || fail('pwa yhteys offline');
$style = file_get_contents(__DIR__ . '/../assets/css/style.css');
str_contains($style, 'body.iltarasti-kuori') || fail('kuori css');
str_contains($style, 'body.iltarasti-kuori .theme-toggle') || fail('kuori teema nappi');
str_contains($style, 'min-height: 100dvh') || fail('kuori koko naytto');
preg_match('/\.site-topbar--admin\s*\{[^}]*position:\s*sticky/', $style) && fail('admin topbar ei sticky');
str_contains($style, 'body.admin-body:not(.iltarasti-kuori)') || fail('hallinta ylapalkki vierii');
str_contains($style, '.hallinta-kisa-rivit > .public-links + .public-links') || fail('valmistelu rivivali');
str_contains($style, '.hallinta-maalinappi-pari') || fail('pari kehys css');
str_contains($style, '.hallinta-kisa-ryhma .public-links a') || fail('hallinta nappi css');
str_contains($style, 'Kaikki ruudukkonapit saman levyisiä') || fail('hallinta nappi sama leveys');
preg_match('/\.hallinta-kisa-ryhma \.public-links a\s*\{[^}]*padding:\s*0\.85rem/', $style) || fail('hallinta nappi suurempi');
$kelloCss = file_get_contents(__DIR__ . '/../assets/css/lahtokello.css');
preg_match('/body\.lahtokello-page \.site-topbar\s*\{[^}]*position:\s*fixed/', $kelloCss) || fail('lahtokello topbar fixed');
$hub = file_get_contents(__DIR__ . '/../public/omat_tapahtumat.php');
str_contains($hub, '/sovellus/') && fail('hub ei erillista sovellusta');
$nav = file_get_contents(__DIR__ . '/../includes/admin_nav.php');
str_contains($nav, 'Iltarastit-sovellus') && fail('nav ei erillista sovellusta');
$ohje = file_get_contents(__DIR__ . '/../public/ohjeet.php');
str_contains($ohje, 'id="puhelin"') || fail('ohjeet puhelin');
str_contains($ohje, 'Ilman yhteyttä ei voi siirtyä') || fail('ohjeet offline esto');
str_contains($ohje, 'hamburgerin sivut') || fail('ohjeet pwa prefetch');
str_contains($ohje, 'Ratoja voi muokata offline') || fail('ohjeet radat offline');
str_contains($ohje, '/sovellus/') && fail('ohjeet ei erillista sovellusta');
$login = file_get_contents(__DIR__ . '/../auth/login.php');
str_contains($login, '/sovellus/') && fail('login ei erillista sovellusta');
$logout = file_get_contents(__DIR__ . '/../auth/logout.php');
str_contains($logout, 'Location: /public/index.php') || fail('logout julkiselle etusivulle');
str_contains($logout, 'Location: login.php') && fail('logout ei login-sivulle');
$sovellusIndex = file_get_contents(__DIR__ . '/../sovellus/index.php');
str_contains($sovellusIndex, '/admin/iltarastit.php') || fail('sovellus ohjaa hubiin');
is_readable(__DIR__ . '/../sovellus/app.js') && fail('sovellus app.js poistettu');
is_readable(__DIR__ . '/../api/sovellus.php') && fail('sovellus api poistettu');
ok('iltarastit koko naytto ilman erillista sovellusta');

echo "all tests passed\n";
