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

$php = file_get_contents(__DIR__ . '/../public/emit_lukija.php');
$js = file_get_contents(__DIR__ . '/../assets/js/emit_lukija.js');
$emitSrc = file_get_contents(__DIR__ . '/../includes/emit_lukija.php');
$ilta = file_get_contents(__DIR__ . '/../includes/iltarasti.php');
$layout = file_get_contents(__DIR__ . '/../includes/layout.php');
$kehys = file_get_contents(__DIR__ . '/../includes/iltarasti_kuori_kehys.php');
$kehysJs = file_get_contents(__DIR__ . '/../assets/js/iltarasti_kuori_kehys.js');

str_contains($php, 'Luetaanko lähteväksi vai pois maastosta?') || fail('kysy pois maastosta');
str_contains($php, "'Pois maastosta'") || fail('nappi pois maastosta');
str_contains($php, 'emit_lukija.js?v=20261116viesti') || fail('js cache');
str_contains($php, "\$emitCssV = '20261116hamp'") || fail('css napit cache');
str_contains($php, 'emit_proto.js?v=20261114tyhja') || fail('proto cache');
str_contains($php, 'automaatti24') || fail('js versio');
str_contains($emitSrc, '$automaattiLuku && in_array($moodi, [\'lue\', \'maali\'], true)') || fail('php automaatti loytaa valmiin tuloksen');
str_contains($emitSrc, 'jo luettu pois maastosta') || fail('php kysy kaytetty kortti');
str_contains($js, 'viimeVastaus && !viimeVastaus.kysy') || fail('js ei ylikirjoita tulosta tuntemattomalla');
str_contains($js, 'onkoAutomaatti() && samaNayttoEmit(emitVastaus)') || fail('js kysy vain automaatissa estetaan');
str_contains($js, 'body.automaatti = 1') || fail('js lahettaa automaatti');
str_contains($js, 'function emitOnMaastossa') || fail('js maastossa');
str_contains($js, 'function samaNayttoEmit') || fail('js pitaa saman kortin nakyman');
str_contains($js, 'nyt - viimeAika < 8000') || fail('js leimat eivat vilahda');
str_contains($js, 'maaliNakyma') || fail('js leimantarkastus nayttaa leimat');
str_contains($js, 'nollaSecLeimoista(kilpailija.leimat') && fail('nollaSec kaataa lahetyksen');
str_contains($js, 'pois maastosta') || fail('js kysy teksti');
str_contains($js, 'cfg.iltarasti && !kioski') || fail('js piilottaa vanhat leimat');
str_contains($js, "moodiLahetys = 'maastoon'") || fail('js lahtevaksi maastoon');
str_contains($js, "moodiLahetys = ilmanAikaa ? 'pois_maastosta' : 'maali'") || fail('js pois tai maali');
str_contains($js, 'jah-lukija-halutaan') || fail('js lukija pysyy');
str_contains($js, 'haeSallittuPortti') || fail('js getPorts');
str_contains($js, 'function lukijaIrti') || fail('js lukija irti');
str_contains($js, "addEventListener('disconnect'") || fail('js usb disconnect');
str_contains($js, 'USB_SARJA_SUODATTIMET') || fail('js usb suodatin');
str_contains($js, 'portti = await haeSallittuPortti()') || fail('js yhdista ilman listaa');
str_contains($js, 'hiljainen: true') || fail('js hiljainen yhdista');
str_contains($js, 'JahKuori.naytaLukija') || fail('js kortti tuo lukijan');
str_contains($js, 'eiHistoria: onkoAutomaatti()') || fail('js automaatti ei vaihda sivua');
str_contains($js, "kayta === 'maastoon'") || fail('js maastoon sisaanluku');
str_contains($js, "kayta === 'maali'") || fail('js pois leimantarkastus');
str_contains($js, '} finally {') || fail('js kesken finally');
str_contains($js, 'function puhuAutomaatti') || fail('js automaatti puhe');
str_contains($js, 'emit-automaatti-kortti') || fail('js automaatti kortti');
str_contains($js, 'Puuttuvat rastit') || fail('js puuttuvat rastit');
str_contains($js, 'Hyväksytty') || fail('js hyvaksytty');
str_contains($js, 'automaattiPuuttuvatKoodit') || fail('js puuttuvat koodit');
str_contains($js, 'emit-automaatti-koodi-nro') || fail('js puuttuva jarjestysnumero');
str_contains($js, 'automaattiPuuttuvaTeksti') || fail('js puuttuva teksti');
$cssLukija = file_get_contents(__DIR__ . '/../assets/css/emit_lukija.css');
str_contains($cssLukija, 'emit-automaatti-koodi') || fail('css koodi chip');
str_contains($cssLukija, 'emit-automaatti-koodi-nro') || fail('css puuttuva nro');
str_contains($cssLukija, '.emit-automaatti-koodit') && str_contains($cssLukija, 'flex-direction: column') || fail('css puuttuvat allekkain');
str_contains($cssLukija, 'body.emit-automaatti-sivu .theme-toggle') || fail('css piilottaa teeman automaatissa');
str_contains($cssLukija, 'body.iltarasti-kuori.emit-iltarasti:not(.emit-automaatti-sivu) .site-topbar .admin-menu-toggle') || fail('css hamburger vasemmalle');
str_contains($cssLukija, 'body.iltarasti-kuori.emit-iltarasti:not(.emit-automaatti-sivu) .site-topbar-left') || fail('css hamburger ei leikkaudu');
str_contains($cssLukija, 'body.iltarasti-kuori.emit-iltarasti:not(.emit-automaatti-sivu) .site-topbar .theme-toggle') || fail('css teema oikealle');
str_contains($cssLukija, 'body.iltarasti-kuori.emit-iltarasti:not(.emit-automaatti-sivu) .site-topbar .emit-automaatti-puhe') || fail('css aani oikealle');
str_contains($js, 'function siirraPuheYlapalkkiin') || fail('js aani tulospalveluun');
str_contains($cssLukija, 'emit-automaatti-kortti') || fail('css automaatti kortti');
str_contains($cssLukija, '.emit-automaatti.is-ok {') || fail('css hyvaksytty tausta');
str_contains($cssLukija, 'animation: emit-automaatti-pulssi 1.05s') || fail('css syke nopeampi');
str_contains($cssLukija, 'clamp(7.4rem') || fail('css syke suurempi');
str_contains($js, 'function avaaPuhe') || fail('js puhe avaus');
str_contains($js, 'suomiPuheAani') || fail('js suomi aani');
str_contains($js, '__jahPuheLause') || fail('js puhe viite');
str_contains($js, "sanoma.viikko && !cfg.iltarasti") || fail('js ei nayta kortin viikkoa');
str_contains($js, 'Tarkistetaan rata') && fail('js ei jaa tarkistetaan');
str_contains($js, 'Luetaan rastit') || fail('js odottaa rasteja');
str_contains($js, 'keskenLukija') || fail('js odottaa 217 tavun kehysta');
str_contains($js, 'KERAYS_IDLE_MS') || fail('js odottaa usb-palat');
str_contains($js, 'odotettu < 1200') && fail('js ei katkaise kehysta 1,2 s');
str_contains($js, 'Osittainen kehys') || fail('js ei nayta tavuja iltarastilla');
str_contains($js, 'otsikkoLahetetty') || fail('js otsikko heti apiin');
str_contains($js, 'lueOtsikko') || fail('js lue otsikko');
$protoJs = file_get_contents(__DIR__ . '/../assets/js/emit_proto.js');
str_contains($protoJs, 'function keskenLukija') || fail('proto keskenLukija');
str_contains($protoJs, 'function lueKehykset') || fail('proto lueKehykset');
str_contains($protoJs, 'function lueOtsikko') || fail('proto lueOtsikko');
str_contains($protoJs, 'tavut < LUKIJA_PITUUS') || fail('proto ei tulkitse otsikkoa tulokseksi');
str_contains($protoJs, 'if (!code && !aika)') || fail('proto sailyttää nollaleiman');
str_contains($protoJs, 'if (i && !code && !aika)') || fail('proto pysahtyy tyhjaan leimaan');
str_contains($emitSrc, '$koodi === 0 && $kello === 0') || fail('php pysahtyy tyhjaan leimaan');
$api = file_get_contents(__DIR__ . '/../api/api_emit_luku.php');
str_contains($api, 'session_write_close') || fail('emit api vapauttaa session');
$rl = file_get_contents(__DIR__ . '/../includes/rastilippu.php');
str_contains($rl, 'Älä lähetä Rastilippuun tässä pyynnössä') || fail('jonota ei tuki fpm:aa');
str_contains($kehysJs, 'JahKuori') && str_contains($kehysJs, 'puhu: kuoriPuhu') || fail('kehys puhe');
str_contains($js, 'is-lukija-nakyva') || fail('js ei avaa kuorta uudestaan');
str_contains($kehysJs, 'sailo: true') || fail('kehys ei nollaa moodia kortista');
str_contains($kehysJs, 'piilotaSivuKosketukselta') || fail('kehys inert sivu');
$cssKuori = file_get_contents(__DIR__ . '/../assets/css/iltarasti_kuori.css');
str_contains($cssKuori, 'display: none !important') || fail('css sivu ei syo kosketusta');
str_contains($kehys, 'allow="serial *; usb *; fullscreen *; autoplay') || fail('iframe autoplay puheeseen');
str_contains($js, 'function yhdistaKohde') || fail('js ei syo usb-elettä äänellä');
str_contains($js, 'function naytaYhdistetaan') || fail('js yhdista palaute');
str_contains($js, 'usbPolyfillPortti') || fail('js usb varatie tietokoneelle');
str_contains($js, 'function pyydaPorttiHetissa') || fail('js usb heti klikkauksesta');
str_contains($js, "toiminto === 'usb-valmis'") || fail('js kuori usb valmis');
str_contains($js, 'function avaaKuoriUsb') || fail('js avaa kuoren usb-laitteen');
str_contains(file_get_contents(__DIR__ . '/../assets/js/usb_sarja.js'), 'portFromDevice') || fail('usb portFromDevice');
str_contains($kehysJs, 'function kuoriPyydaUsb') || fail('kehys usb tietokoneelle');
str_contains($kehys, 'id="jah-kuori-usb"') || fail('kehys usb nappi');
str_contains($layout, 'Permissions-Policy: serial=*, usb=*') || fail('layout usb policy');
$pwa = file_get_contents(__DIR__ . '/../assets/js/jah-pwa.js');
str_contains($pwa, 'kioskiKokoNayttoJo') || fail('pwa ei pyyda fullscreen joka kosketuksella');
str_contains($pwa, 'kioskiPyynto') || fail('pwa fullscreen lukko');
str_contains($emitSrc, "'Uusi kortti. Luetaanko lähteväksi vai pois maastosta?'") || fail('php kysy viesti');
str_contains($emitSrc, '$iltarastiKisa ? [] : $leimatLue') || fail('php ei laheta vanhoja leimoja');
str_contains($emitSrc, 'iltarastiVarmistaHenkilo') || fail('lisaa varmista');
preg_match('/if \(\$emit <= 0 && function_exists\(\'kisaOnIltarasti\'\)/', $emitSrc)
    && fail('lisaa ei vaadi emit 0');
str_contains($ilta, 'Kirjaa ensin maastoon') && fail('pois voi luoda uuden');
str_contains($ilta, 'bool $ilmanAikaa = false') || fail('maastoon ei aikaa vain rukista');
str_contains($ilta, "\$viesti .= ' (ilman aikaa).'") || fail('ilman aikaa vain kun ruksittu');
str_contains($emitSrc, "\$moodi === 'maastoon'") || fail('php maastoon kutsu');
preg_match('/iltarastiKirjaaMaastoon[\s\S]{0,500}emitLukijaKirjaaNollausLahto/', $emitSrc)
    && fail('maastoon ei nayta nollauksen sijaan lahtoaikaa');
str_contains($js, 'body.ilman_aikaa') || fail('js lahettaa ei aikaa');
str_contains($php, 'jahMainosMerkki') || fail('emit mainos merkki');
str_contains($layout, 'function jahTeemaNappi') || fail('teema nappi funktio');
str_contains($layout, 'jahTeemaNappi();') || fail('iltarasti teema nappi');
str_contains($layout, 'jahTulostaKuoriKehys') || fail('layout kehys');
str_contains($layout, 'iltarasti_kuori_kehys.js') || fail('layout kehys js');
str_contains($kehys, 'jah-kuori-lukija') || fail('kehys lukija iframe');
str_contains($kehysJs, 'JahKuori') || fail('kehys js api');
str_contains($kehysJs, 'is-tausta') || fail('kehys tausta');
str_contains($kehysJs, 'function asetaKuoriTausta') || fail('kehys ei mustaa valia');
str_contains($cssKuori, 'background: #eef2f5') || fail('css kuori vaalea tausta');
str_contains($kehys, 'is-automaatti-kuori') || fail('kehys automaatti tausta');

require_once __DIR__ . '/../includes/emit_lukija_logiikka.php';
emitLukijaValitseToiminto(false, false, 'lahto', [['koodi' => 31]], true) === 'kysy'
    || fail('uusi kortti kysyy');
emitLukijaValitseToiminto(true, true, 'lahto', [['koodi' => 31]], true) === 'maali'
    || fail('tuttu kortti maaliin');
emitLukijaValitseToiminto(true, true, 'lahto', [['koodi' => 31]], true, false) === 'kysy'
    || fail('pois maastosta kysyy tulospalvelussa');
emitLukijaValitseToiminto(true, true, 'lahto', [['koodi' => 31]], true, true) === 'maali'
    || fail('automaatti nayttaa tuloksen');
emitLukijaOnAutomaatti(['automaatti' => 1]) || fail('php automaatti lippu');
!emitLukijaOnAutomaatti(['moodi' => 'lue']) || fail('php tulospalvelu ei automaatti');
ok('iltarasti lukija kysy ja kehys');

echo "all tests passed\n";
