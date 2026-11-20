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

$php = file_get_contents(__DIR__ . '/../public/lahtokello.php');
$css = file_get_contents(__DIR__ . '/../assets/css/lahtokello.css');
$js = file_get_contents(__DIR__ . '/../assets/js/lahtokello.js');
$layout = file_get_contents(__DIR__ . '/../includes/layout.php');

str_contains($php, 'id="kello-asetukset"') || fail('asetukset nappi');
str_contains($php, 'id="kello-asetusikkuna"') || fail('asetusikkuna');
str_contains($php, '<dialog') || fail('dialog');
preg_match('/<time[^>]*id="kello-naytto"/', $php) || fail('kello naytto');
str_contains($php, 'kello-kuvio') || fail('kuvio asetuksissa');
strpos($php, 'id="kello-kuvio"') > strpos($php, 'kello-asetusikkuna') || fail('kuvio ikkunassa');
str_contains($php, 'class="kello-ala"') && fail('ei ala nappeja');
str_contains($php, 'Hallintaan') && fail('ei hallintaan teksti');
str_contains($css, 'min-height: 100dvh') || fail('koko naytto');
str_contains($css, 'font-size: min(22vw, 38vh)') || fail('iso kello');
str_contains($js, 'avaaAsetukset') || fail('avaa asetukset');
str_contains($js, 'showModal') || fail('showModal');
str_contains($php, 'lukijaPoistuRuksi') || fail('punainen ruksi');
str_contains($php, 'lukijaToimet') || fail('ikonit ylapalkkiin');
str_contains($php, 'aria-label="Asetukset"') || fail('ratas label');
str_contains($php, 'aria-label="Äänet päälle"') || fail('kaiutin label');
str_contains($php, 'kello-ikoni') || fail('ikoni luokka');
str_contains($css, '.kello-ikoni') || fail('ikoni css');
str_contains($js, "aaniBtn.textContent = aani ? '🔊' : '🔇'") || fail('aani automaatti kuvake');
str_contains($php, '>🔇</button>') || fail('aani mykistetty oletus');
str_contains($php, 'kello-ikoni-pois') && fail('ei vanhaa svg kaiutinta');
str_contains($php, 'id="kello-poistu-varmistus"') || fail('poistu varmistus ruutu');
str_contains($php, 'id="kello-poistu-peru"') || fail('peru nappi');
str_contains($php, 'Haluatko varmasti poistua lähtökellosta?') || fail('poistu varmistus');
str_contains($js, 'window.confirm(') && fail('ei window.confirm overlay');
str_contains($js, 'piilotaLatausLogo') || fail('peru piilottaa splashin');
str_contains($js, 'lukija-poistu') || fail('yla nappi varmistus');
str_contains($css, 'kello-poistu-varmistus') || fail('varmistus css');
str_contains($css, '.huuto-poistu') || fail('ruksi css');
str_contains($php, "'lukijaLogo'") || fail('logo optio');
str_contains($layout, 'lukija-logo') || fail('layout logo');
str_contains($css, '.lukija-logo') || fail('logo css');
str_contains($layout, 'logo.png') || fail('logo kuva');
str_contains($php, 'id="kello-laskuri"') || fail('laskuri');
strpos($php, 'id="kello-laskuri"') > strpos($php, 'id="kello-seuraava"') || fail('laskuri alhaalla');
str_contains($php, 'lahtokello.css?v=20261120ajastin7') || fail('css cache');
str_contains($php, 'lahtokello.js?v=20261120ajastin7') || fail('js cache');
str_contains($css, '92cqh') || fail('ajastin tayttaa tilan');
str_contains($css, 'is-lahella') || fail('vihreät sekunnit');
str_contains($js, 'varmistaAjastinRaf') || fail('raf synkka');
str_contains($js, 'aaniLatenssiMs') || fail('aani latenssi');
str_contains($css, 'container-type: size') || fail('ajastin container');
str_contains($css, 'max-width: 100vw') || fail('ikkuna nayton levyinen');
str_contains($css, 'overflow-x: hidden') || fail('ei vaakavieritysta');
preg_match('/#kello-piippu-kuviot\s*\{[^}]*grid-template-columns/', $css) && fail('lyhyet pitkat ei vierekkain');
str_contains($php, 'id="kello-tila-ajastin"') || fail('ajastin tila');
str_contains($php, 'id="kello-vali-min"') || fail('vali min');
str_contains($php, 'id="kello-vali-sek"') || fail('vali sek');
str_contains($php, 'id="kello-kertaa"') || fail('kertaa');
str_contains($php, 'id="kello-ajastin-ylos"') || fail('ylos kello');
str_contains($php, 'id="kello-ajastin-alas"') || fail('alas kello');
str_contains($php, 'id="kello-ajastin-kaynnista"') || fail('kaynnista');
str_contains($php, 'name="kello-ajastin-naytto"') || fail('naytto valinta');
str_contains($php, 'Ylös') || fail('ylospain');
str_contains($php, 'Alas') || fail('alaspain');
str_contains($php, 'Molemmat') || fail('molemmat');
str_contains($php, 'kello-radio-rivi') || fail('radio rivi');
str_contains($css, 'max-width: 720px') || fail('mobiili asetus');
str_contains($js, 'ajastinKuvio') && fail('ajastin kayttaa samaa kuviota');
str_contains($js, "piilota('kello-piippu-kuviot'") && fail('piiput nakyvat ajastimessa');
str_contains($js, "piilota('kello-minuutti-osio'") || fail('tyhja minuutti vain lahdoissa');
str_contains($js, 's: 5') || fail('piippi 5');
str_contains($js, 'kestoTekstiJaljella') || fail('jaljella ceil');
str_contains($js, 'kayttoAlkuunMs') || fail('alkuun ms');
str_contains($php, 'id="kello-alkuun-sek"') || fail('alkuun kentta');
str_contains($css, 'kello-vali-yksi') || fail('vali samalle riville');
str_contains($js, 'kaynnistaAjastin') || fail('kaynnista fn');
str_contains($js, 'piirraAjastin') || fail('piirra ajastin');
str_contains($php, 'Kulunut') || fail('kulunut');
str_contains($css, 'kello-ajastin-kellot') || fail('ajastin css');
str_contains($css, 'kello-tila-ajastin') || fail('tila css');
str_contains($css, 'min(52vw, 40vh)') || fail('isompi laskuri');
preg_match('/\.kello-laskuri\s*\{[^}]*position:\s*absolute/', $css) && fail('ei overlay laskuri');
preg_match('/\.kello-laskuri\s*\{[^}]*inset:\s*0/', $css) && fail('ei inset overlay');
str_contains($css, 'bottom: 0') || fail('laskuri alareunaan');
str_contains($css, 'position: fixed') || fail('laskuri fixed');
str_contains($php, 'grid-template-columns:1fr auto 1fr') || fail('ylapalkki grid');
str_contains($css, 'max-width: none') || fail('svg ei kutistu');
str_contains($php, 'id="kello-nayta-seuraava"') || fail('nayta seuraava');
str_contains($php, 'id="kello-nayta-aika"') || fail('nayta aika');
str_contains($js, 'Seuraavaan lähtijään') || fail('seuraavaan lahtijaan');
str_contains($js, "'Lähtöön '") && fail('ei vanhaa lahtoon teksti');
str_contains($js, 'minuuttiin <= 10') || fail('laskuri joka minuutti');
str_contains($php, 'id="kello-minuutti" checked') || fail('tyhja minuutti oletus');
str_contains($js, "tallennettu, 'tyhjaMinuutti'") || fail('tyhja minuutti tallennus');
str_contains($js, 'minuuttiEl.checked = true') || fail('tyhja minuutti paalle');
str_contains($js, 'requestFullscreen') || fail('pwa koko naytto');
str_contains($js, 'navigationUI') || fail('piilota chrome valikko');
ok('lahtokello ui');

echo "all tests passed\n";
