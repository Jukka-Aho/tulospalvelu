<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/kisa_liput.php';
require_once __DIR__ . '/../includes/lahtokello.php';
require_once __DIR__ . '/../db.php';

$kilpailu_id = (int) ($_GET['kilpailu_id'] ?? valittuKilpailuId());
if ($kilpailu_id <= 0) {
    header('Location: lahto_valinta.php');
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailu_id);
if (!$kilpailu) {
    http_response_code(404);
    die('Kilpailua ei löytynyt');
}

vaadiKirjautuminen();
if (!voiHallitaKilpailua($kilpailu) && !(function_exists('voiLahtotoimiaKilpailua') && voiLahtotoimiaKilpailua($kilpailu))) {
    http_response_code(403);
    die('Ei oikeutta lähtökelloon tässä kilpailussa');
}

asetaValittuKilpailu($kilpailu_id);
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $body = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raaka = file_get_contents('php://input');
        $body = json_decode($raaka ?: '', true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        if (isset($body['siirra_ms'])) {
            $tila = pirilaKelloSiirra($pdo, $kilpailu_id, (int) $body['siirra_ms']);
        } elseif (!empty($body['nollaa'])) {
            $tila = pirilaKelloNollaa($pdo, $kilpailu_id);
        } elseif (!empty($body['kello'])) {
            $tila = pirilaKelloTallenna($pdo, $kilpailu_id, (string) $body['kello'], 'kasin');
            if ($tila === null) {
                http_response_code(400);
                echo json_encode(['ok' => 0, 'message' => 'Kellonaika ei kelpaa'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            $tila = pirilaKelloTila($pdo, $kilpailu_id);
        }
    } else {
        $tila = pirilaKelloTila($pdo, $kilpailu_id);
    }

    require_once __DIR__ . '/../includes/nimenhuuto_data.php';
    echo json_encode($tila + [
        'kilpailu' => (string) $kilpailu['nimi'],
        'rivit' => nimenhuutoRivit($pdo, $kilpailu_id),
        'lahtopaikat' => kilpailunLahtopaikat($pdo, $kilpailu_id),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$kelloToimet = <<<'HTML'
<button type="button" id="kello-aani" class="kello-ikoni kello-aani" aria-pressed="false" aria-label="Äänet päälle" title="Äänet päälle">🔇</button>
<button type="button" id="kello-asetukset" class="kello-ikoni" aria-haspopup="dialog" aria-controls="kello-asetusikkuna" aria-label="Asetukset" title="Asetukset">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94L14.4 2.5a.5.5 0 0 0-.5-.5h-3.8a.5.5 0 0 0-.5.5l-.38 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96a.5.5 0 0 0-.6.22L2.77 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.89 14.52a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.42.34.64.22l2.39-.96c.5.38 1.03.7 1.62.94l.38 2.54c.05.28.27.48.5.48h3.8c.24 0 .45-.2.5-.48l.38-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.12.5.02.64-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg>
</button>
HTML;

pageStart('Lähtökello', [
    'class' => 'lahtokello-page',
    'base' => '../',
    'lukija' => true,
    'hallintaHref' => '../admin/index.php',
    'lukijaPoistuRuksi' => true,
    'lukijaPoistuVarmistusId' => 'kello-poistu-varmistus',
    'lukijaLogo' => true,
    'lukijaToimet' => $kelloToimet,
    'extraHead' => '<meta name="theme-color" content="#07140f">'
        . '<link rel="stylesheet" href="/assets/css/lahtokello.css?v=20261120ajastin">'
        . '<style>'
        . 'body.lahtokello-page .site-topbar{position:fixed;top:0;left:0;right:0;width:100%;z-index:8;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;background:#07140f;padding:.35rem .5rem;padding-top:max(.35rem,env(safe-area-inset-top,0px));flex-wrap:nowrap;max-width:none}'
        . 'body.lahtokello-page .lukija-logo{position:static;grid-column:2;height:2.6rem;width:auto;max-width:none;transform:none}'
        . 'body.lahtokello-page .site-topbar-actions{grid-column:3;justify-self:end;margin:0;display:flex;gap:.35rem;flex-wrap:nowrap}'
        . 'body.lahtokello-page .site-topbar button{width:2.7rem;height:2.7rem;min-width:2.7rem;min-height:2.7rem;padding:0;max-width:none;border-radius:999px;background:#173628;color:#f4fff8}'
        . 'body.lahtokello-page .site-topbar .huuto-poistu{justify-self:start;background:#b42318;color:#fff}'
        . 'body.lahtokello-page .site-topbar .kello-aani.is-paalla{background:#d6ff4a;color:#1c1408}'
        . 'body.lahtokello-page .site-topbar svg{width:1.4rem;height:1.4rem;max-width:none;max-height:none;display:block;flex-shrink:0}'
        . '.kello-laskuri{position:fixed;left:0;right:0;bottom:0;z-index:4;height:46vh;display:flex;align-items:flex-end;justify-content:center;margin:0;font-size:min(52vw,40vh);color:#d6ff4a;background:transparent;pointer-events:none;padding-bottom:max(2.2rem,calc(4vh + env(safe-area-inset-bottom,0px)))}'
        . '.kello-laskuri[hidden]{display:none!important}'
        . '</style>',
]);
?>

<div class="kello">
    <div id="kello-poistu-varmistus" class="kello-poistu-varmistus" hidden>
        <p>Haluatko varmasti poistua lähtökellosta?</p>
        <div class="kello-poistu-napit">
            <button type="button" id="kello-poistu-peru">Peru</button>
            <button type="button" id="kello-poistu-ok" class="kello-poistu-ok">Poistu</button>
        </div>
    </div>
    <div id="kello-lahdot-naytto" class="kello-naytto-alue">
        <time id="kello-naytto" class="kello-naytto">--:--:--</time>
        <p id="kello-lahtoon" class="kello-lahtoon">Seuraavaan lähtijään —</p>
        <div id="kello-seuraava" class="kello-seuraava"></div>
    </div>
    <div id="kello-ajastin-naytto" class="kello-naytto-alue kello-ajastin-naytto" hidden>
        <p id="kello-ajastin-tila" class="kello-ajastin-tila">Valmis käynnistettäväksi</p>
        <div id="kello-ajastin-kellot" class="kello-ajastin-kellot is-molemmat">
            <div class="kello-ajastin-yksikko" id="kello-ajastin-ylos-wrap">
                <span class="kello-ajastin-nimi">Kulunut</span>
                <time id="kello-ajastin-ylos" class="kello-naytto">0:00</time>
            </div>
            <div class="kello-ajastin-yksikko" id="kello-ajastin-alas-wrap">
                <span class="kello-ajastin-nimi">Seuraavaan</span>
                <time id="kello-ajastin-alas" class="kello-naytto">6:00</time>
            </div>
        </div>
        <div class="kello-ajastin-ohjaus">
            <button type="button" id="kello-ajastin-kaynnista">Käynnistä</button>
            <button type="button" id="kello-ajastin-pysayta" hidden>Pysäytä</button>
            <button type="button" id="kello-ajastin-nollaa">Nollaa</button>
        </div>
    </div>
    <div id="kello-laskuri" class="kello-laskuri" hidden></div>

    <dialog id="kello-asetusikkuna" class="kello-asetusikkuna">
        <div class="kello-asetus-yla">
            <h2>Asetukset</h2>
            <button type="button" id="kello-asetukset-sulje" class="kello-asetukset-sulje jah-ruksi" aria-label="Sulje" title="Sulje"><?= jahRuksiSvg() ?></button>
        </div>
        <p class="kello-kisa"><?= htmlspecialchars((string) $kilpailu['nimi']) ?></p>
        <p id="kello-synkka" class="kello-synkka">Kello seuraa palvelinta.</p>
        <?php if (function_exists('demoKisaIlmoitus')) { demoKisaIlmoitus($kilpailu); } ?>

        <section class="kello-asetus" id="kello-tila-osio">
            <h3>Tila</h3>
            <label class="kello-check"><input type="radio" name="kello-tila" id="kello-tila-lahdot" value="lahdot" checked> Lähtöajat</label>
            <label class="kello-check"><input type="radio" name="kello-tila" id="kello-tila-ajastin" value="ajastin"> Ajastin</label>
            <p class="kello-ohje">Ajastin piippaa 5, 4, 3, 2, 1 ja 0 valitulla välillä, esimerkiksi 6 min välein 6 kertaa.</p>
        </section>

        <section class="kello-asetus" id="kello-ajastin-asetus" hidden>
            <h3>Ajastin</h3>
            <p class="kello-ohje">Ensimmäinen lähtö 5 sekunnin kuluttua käynnistyksestä. Väleissä näkyy kulunut aika edellisestä piipistä ja aika seuraavaan.</p>
            <div class="kello-rivi kello-vali-rivi">
                <label>Väli min
                    <input id="kello-vali-min" inputmode="numeric" autocomplete="off" value="6">
                </label>
                <label>Väli s
                    <input id="kello-vali-sek" inputmode="numeric" autocomplete="off" value="0">
                </label>
                <label>Kertaa
                    <input id="kello-kertaa" inputmode="numeric" autocomplete="off" value="6">
                </label>
            </div>
            <p class="kello-ohje">Kertaa 0 = jatkuva.</p>
            <fieldset class="kello-naytto-valinta">
                <legend>Kellonäyttö</legend>
                <label class="kello-check"><input type="radio" name="kello-ajastin-naytto" value="ylos"> Ylöspäin</label>
                <label class="kello-check"><input type="radio" name="kello-ajastin-naytto" value="alas"> Alaspäin</label>
                <label class="kello-check"><input type="radio" name="kello-ajastin-naytto" value="molemmat" checked> Molemmat</label>
            </fieldset>
        </section>

        <label class="kello-paikka" id="kello-paikka-osio">Lähtöpaikka
            <select id="kello-paikka">
                <option value="">Kaikki</option>
            </select>
        </label>

        <section class="kello-asetus" id="kello-naytto-asetus">
            <h3>Näyttö</h3>
            <label class="kello-check"><input type="checkbox" id="kello-nayta-seuraava" checked> Näytä seuraava lähtijä</label>
            <label class="kello-check"><input type="checkbox" id="kello-nayta-aika" checked> Näytä aika seuraavaan lähtöön</label>
        </section>

        <div class="kello-napit">
            <button type="button" id="kello-kokeile">Kokeile piippi</button>
        </div>

        <section class="kello-asetus">
            <h3>Piippaukset</h3>
            <p class="kello-ohje">Sekunnit ennen lähtöhetkeä. Lyhyt piippi on sama ääni kuin pitkä, vain lyhyempi. 10 sekunnin merkki on kaksi lyhyttä piippiä.</p>
            <p id="kello-kuvio" class="kello-kuvio"></p>
            <p id="kello-ajastin-piippu-ohje" class="kello-ohje" hidden>Ajastin käyttää piippejä 5, 4, 3, 2, 1 (lyhyt) ja 0 (pitkä).</p>
            <div id="kello-piippu-kuviot">
            <div class="kello-rivi">
                <label>Lyhyet
                    <input id="kello-lyhyet" inputmode="numeric" autocomplete="off" value="30 10 5 4 3 2 1">
                </label>
            </div>
            <div class="kello-rivi">
                <label>Pitkät
                    <input id="kello-pitkat" inputmode="numeric" autocomplete="off" value="0">
                </label>
            </div>
            <div class="kello-rivi kello-valinnat">
                <button type="button" data-esiasetus="tavallinen">Tavallinen</button>
                <button type="button" data-esiasetus="harva">Harva</button>
                <button type="button" data-esiasetus="tihea">Tiheä</button>
                <label class="kello-check"><input type="checkbox" id="kello-minuutti" checked> Piippaa myös tyhjällä minuutilla</label>
            </div>
            </div>
            <label class="kello-rivi">Voimakkuus
                <input id="kello-voima" type="range" min="5" max="100" value="70">
            </label>
            <label class="kello-rivi" id="kello-sarja-osio">Sarja
                <select id="kello-sarja"><option value="">Kaikki sarjat</option></select>
            </label>
        </section>

        <section class="kello-asetus" id="kello-synkka-osio">
            <h3>Synkka Pirilään</h3>
            <p class="kello-ohje">Kun HkKisaWin synkkaa, kello ottaa Pirilän tietokoneen ajan. Ennen uutta käännöstä ajan voi asettaa käsin sen mukaan, mitä Pirilän ruudulla näkyy.</p>
            <div class="kello-rivi kello-synkka-napit">
                <button type="button" id="kello-miinus">−1 s</button>
                <button type="button" id="kello-plus">+1 s</button>
                <button type="button" id="kello-nollaa">Palvelimen aika</button>
            </div>
            <form id="kello-aseta" class="kello-rivi kello-aseta">
                <label>Pirilän kello nyt
                    <input id="kello-syotto" inputmode="numeric" placeholder="14:32:05" autocomplete="off">
                </label>
                <button type="submit">Aseta</button>
            </form>
            <p id="kello-virhe" class="kello-virhe"></p>
        </section>
    </dialog>
</div>

<script>
window.JAH_KELLO = {
    kilpailuId: <?= (int) $kilpailu_id ?>,
    url: 'lahtokello.php?kilpailu_id=<?= (int) $kilpailu_id ?>&json=1',
    hallintaUrl: '../admin/index.php'
};
</script>
<script src="../assets/js/wake_lock.js?v=20260718a"></script>
<script src="/assets/js/lahtokello.js?v=20261120ajastin"></script>

<?php pageEnd(); ?>
