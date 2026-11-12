<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/layout.php';

vaadiAjanottaja();

require_once __DIR__ . '/../db.php';

$kilpailu_id = $_GET['kilpailu_id'] ?? $_POST['kilpailu_id'] ?? valittuKilpailuId();
$tila = (string) ($_GET['tila'] ?? 'ruudukko');
if ($tila === 'kouru') {
    $q = $kilpailu_id ? ('?kilpailu_id=' . (int) $kilpailu_id . '&tila=numerot') : '?tila=numerot';
    header('Location: mobiili_ajanotto.php' . $q, true, 302);
    exit;
}
if (!in_array($tila, ['ruudukko', 'nappi', 'numerot'], true)) {
    $tila = 'ruudukko';
}
$is_nappi = $tila === 'nappi';
$is_numerot = $tila === 'numerot';
$is_jono = $is_nappi || $is_numerot;

if (!$kilpailu_id) {
    header('Location: ajanotto_valinta.php');
    exit;
}

$kilpailu = haeKilpailu($pdo, (int) $kilpailu_id);

if (!$kilpailu) {
    die('Kilpailua ei löytynyt');
}

require_once __DIR__ . '/../includes/kisa_liput.php';
kisaLiputVarmista($pdo);
$jaettuNumerot = $is_numerot && voiSyottaaJaetutNumerot($kilpailu);
if (!voiAjanottaaKilpailua($kilpailu) && !$jaettuNumerot) {
    http_response_code(403);
    die('Ei oikeutta ajanottoon tässä kilpailussa');
}

asetaValittuKilpailu((int) $kilpailu_id);
if (!$jaettuNumerot) {
    vaadiEiPirilaSynkka($kilpailu);
}

$serverTimeMs = (int) round(microtime(true) * 1000);

$stmt = $pdo->prepare('
    SELECT
        kilpailijat.numero,
        kilpailijat.nimi,
        kilpailijat.etunimi,
        kilpailijat.sukunimi,
        kilpailijat.status,
        sarjat.nimi AS sarja,
        sarjat.jarjestys,
        CASE WHEN ajat.id IS NOT NULL THEN 1 ELSE 0 END AS maalissa
    FROM kilpailijat
    JOIN sarjat ON kilpailijat.sarja_id = sarjat.id
    LEFT JOIN ajat ON kilpailijat.id = ajat.kilpailija_id
    WHERE kilpailijat.kilpailu_id = ?
    ORDER BY kilpailijat.numero
');
$stmt->execute([(int) $kilpailu_id]);
$kilpailijat = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($kilpailijat as &$k) {
    $k['nimi'] = kilpailijaNimiRivista($k);
}
unset($k);
// Ajanotto vain niille joilla on kilpailunumero
$kilpailijat = array_values(array_filter(
    $kilpailijat,
    static fn($k) => $k['numero'] !== null && (int) $k['numero'] > 0
));

$maalissa_lkm = count(array_filter($kilpailijat, fn($k) => (int) $k['maalissa'] === 1));
$yhteensa = count($kilpailijat);

$sarjatSuodatin = [];
foreach ($kilpailijat as $k) {
    $sn = (string) ($k['sarja'] ?? '');
    if ($sn !== '' && !isset($sarjatSuodatin[$sn])) {
        $sarjatSuodatin[$sn] = true;
    }
}
$sarjatSuodatin = array_keys($sarjatSuodatin);

$page_otsikot = [
    'nappi' => 'Maalinappi',
    'numerot' => 'Numerot',
    'ruudukko' => 'Mobiiliajanotto',
];
$page_title = $page_otsikot[$tila] ?? 'Mobiiliajanotto';
$page_class = 'mobiili-page' . ($is_jono ? ' jono-page' : '') . ($is_nappi ? ' nappi-page' : '') . ($is_numerot ? ' numerot-page' : '');

pageStart($page_title, [
    'class' => $page_class . ' mobiili-fullscreen',
    'base' => '../',
    'palvelu' => 'tulokset',
    'extraHead' => '<link rel="manifest" href="manifest.json?v=20260718fs">'
        . '<link rel="stylesheet" href="../assets/css/maali_jono.css?v=20261112jono">'
        . '<meta name="theme-color" content="#008000">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">',
]);

$poistuUrl = 'ajanotto_valinta.php';
$tilaBase = 'mobiili_ajanotto.php?kilpailu_id=' . (int) $kilpailu_id;
?>

<button type="button" class="mobiili-exit" id="btnPoistu" aria-label="Poistu ajanotosta" title="Poistu">×</button>

<div class="header-bar mobiili-header">
    <div class="mobiili-header-row">
        <strong><?= htmlspecialchars($kilpailu['nimi']) ?></strong>
        <?php demoKisaIlmoitus($kilpailu); ?>
        <span class="muted" id="laskuri"><?php
            if ($is_jono) {
                echo '0 aikaleimaa · 0 nroa';
            } else {
                echo $maalissa_lkm . '/' . $yhteensa;
            }
        ?></span>
    </div>
    <nav class="mobiili-tila-vaihto" aria-label="Ajanoton tila">
        <?php if (empty($jaettuNumerot)): ?>
        <a href="<?= htmlspecialchars($tilaBase . '&tila=ruudukko') ?>"
           class="<?= $tila === 'ruudukko' ? 'tila-active' : '' ?>">Ruudukko</a>
        <a href="<?= htmlspecialchars($tilaBase . '&tila=nappi') ?>"
           class="<?= $tila === 'nappi' ? 'tila-active' : '' ?>">Nappi</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($tilaBase . '&tila=numerot') ?>"
           class="<?= $tila === 'numerot' ? 'tila-active' : '' ?>">Numerot</a>
    </nav>
    <p class="mobiili-header-meta muted">
        <span id="offline-jono" class="offline-jono" hidden></span>
        <span id="kello-varoitus" class="offline-jono is-pending" hidden></span>
        <span id="wake-lock-status" class="wake-lock-status" hidden></span>
        <button type="button" class="mobiili-laite-nimi-btn" id="btnLaiteNimi" hidden>Nimeä laite</button>
    </p>
</div>

<?php if (!$is_jono && empty($kilpailijat)): ?>
    <p class="tyhja">Ei kilpailijoita. Lisää kilpailijoita admin-paneelissa.</p>
<?php elseif ($is_nappi): ?>
    <div class="nappi-paneeli">
        <p class="kouru-ohje">Paina MAALI aina kun kilpailija ylittää viivan. Numerot syötetään toisella laitteella.</p>
        <button type="button" class="maali-nappi-btn" id="btnMaaliNappi">MAALI</button>
        <div class="jono-yhteenveto" id="jonoYhteenveto"></div>
        <div class="kouru-viimeiset" id="nappiViimeiset"></div>
    </div>
<?php elseif ($is_numerot): ?>
    <div class="kouru-paneeli numerot-paneeli">
        <p class="kouru-ohje"><?php if (!empty($jaettuNumerot)): ?>Ajat tulevat Pirilästä yhteiseen listaan. Syötä numerot samassa järjestyksessä.<?php else: ?>Syötä numerot maaliintulojärjestyksessä. Järjestystä voi korjata nuolilla.<?php endif; ?></p>
        <input
            id="jonoInput"
            class="kouru-input"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            autocomplete="off"
            autofocus
            placeholder="nro"
            aria-label="Kilpailijan numero"
        >
        <div class="kouru-napit">
            <button type="button" class="kouru-btn kouru-btn-clear" id="btnJonoClear">Tyhjennä</button>
            <button type="button" class="kouru-btn kouru-btn-save" id="btnJonoSave">Lisää</button>
        </div>
        <div class="jono-yhteenveto" id="jonoYhteenveto"></div>
        <div class="jono-lista-wrap" id="jonoLista"></div>
    </div>
<?php else: ?>
    <?php if (count($sarjatSuodatin) > 1 || $yhteensa > 40): ?>
        <div class="ruudukko-suodattimet" id="ruudukkoSuodattimet">
            <input type="search" id="ruudukkoHaku" placeholder="Hae nro / nimi" inputmode="search" autocomplete="off">
            <select id="ruudukkoSarja" aria-label="Sarja">
                <option value="">Kaikki sarjat</option>
                <?php foreach ($sarjatSuodatin as $sn): ?>
                    <option value="<?= htmlspecialchars($sn) ?>"><?= htmlspecialchars($sn) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="numeroruudukko" id="ruudukko"></div>
<?php endif; ?>

<div id="viesti" class="mobiili-viesti"><?php
    if ($is_nappi) {
        echo 'Paina MAALI';
    } elseif ($is_numerot) {
        echo 'Syötä numero';
    } else {
        echo 'Valitse numero';
    }
?></div>

<input type="hidden" id="kilpailu_id" value="<?= (int) $kilpailu_id ?>">
<input type="hidden" id="server_time_ms" value="<?= $serverTimeMs ?>">
<input type="hidden" id="tila" value="<?= htmlspecialchars($tila) ?>">
<input type="hidden" id="poistu_url" value="<?= htmlspecialchars($poistuUrl) ?>">

<script src="../assets/js/wake_lock.js?v=20260827d"></script>
<script src="offline.js?v=20260827c"></script>
<script src="../assets/js/ajanotto_yhteinen.js?v=20260827b"></script>
<script>
const kilpailijat = <?= json_encode($kilpailijat, JSON_UNESCAPED_UNICODE) ?>;
const IS_JONO = <?= $is_jono ? 'true' : 'false' ?>;
const TILA = <?= json_encode($tila, JSON_UNESCAPED_UNICODE) ?>;
const POISTU_URL = document.getElementById('poistu_url').value || 'ajanotto_valinta.php';
const SERVER_TIME_MS = parseInt(document.getElementById('server_time_ms').value, 10) || Date.now();
const PAGE_LOAD_MS = Date.now();
/** Positiivinen = laite edellä palvelinta */
const CLOCK_OFFSET_MS = PAGE_LOAD_MS - SERVER_TIME_MS;
const CLOCK_SKEW_MS = Math.abs(CLOCK_OFFSET_MS);

const ruudukko = document.getElementById('ruudukko');
const viestiEl = document.getElementById('viesti');
const laskuriEl = document.getElementById('laskuri');
const offlineJonoEl = document.getElementById('offline-jono');
const kelloEl = document.getElementById('kello-varoitus');
const ruudukkoHaku = document.getElementById('ruudukkoHaku');
const ruudukkoSarja = document.getElementById('ruudukkoSarja');

let saving = false;
let ruudukkoHakuTeksti = '';
let ruudukkoSarjaValinta = '';

function ajanottoApiToken() {
    const el = document.getElementById('api_token');
    return el ? el.value : '';
}

function soita(tyyppi) {
    if (typeof ajanottoSoita === 'function') {
        ajanottoSoita(tyyppi);
        return;
    }
    if (tyyppi === 'konflikti' && typeof ajanottoSoitaKonflikti === 'function') {
        ajanottoSoitaKonflikti();
    }
}

function soitaKonflikti() {
    soita('konflikti');
}

function laiteId() {
    try {
        let id = localStorage.getItem('jah_laite');
        if (!id) {
            id = 'tab-' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem('jah_laite', id);
        }
        return id;
    } catch (e) {
        return 'unknown';
    }
}

function laiteNimi() {
    try {
        return localStorage.getItem('jah_laite_nimi') || '';
    } catch (e) {
        return '';
    }
}

function asetaLaiteNimi(nimi) {
    try {
        localStorage.setItem('jah_laite_nimi', String(nimi || '').trim().slice(0, 80));
    } catch (e) { /* ignore */ }
    paivitaLaiteNimiNappi();
}

function paivitaLaiteNimiNappi() {
    const btn = document.getElementById('btnLaiteNimi');
    if (!btn) return;
    btn.hidden = false;
    const n = laiteNimi();
    btn.textContent = n ? ('Laite: ' + n) : 'Nimeä laite';
}

paivitaLaiteNimiNappi();
(function () {
    const btn = document.getElementById('btnLaiteNimi');
    if (!btn) return;
    btn.addEventListener('click', function () {
        const nyk = laiteNimi() || (TILA === 'nappi' ? 'Maalinappi' : (TILA === 'numerot' ? 'Numerot' : 'Ruudukko'));
        const neu = window.prompt('Laitteen nimi (näkyy hallinnassa)', nyk);
        if (neu === null) return;
        asetaLaiteNimi(neu);
        lahetaLaiteHeartbeat(0);
    });
    if (!laiteNimi()) {
        // Ehdotus ilman pakkoa
        asetaLaiteNimi(TILA === 'nappi' ? 'Maalinappi' : (TILA === 'numerot' ? 'Numerot' : 'Ruudukko'));
    }
})();

if (CLOCK_SKEW_MS > 15000 && kelloEl) {
    kelloEl.hidden = false;
    kelloEl.textContent = 'Kelloa korjataan palvelimeen (~'
        + Math.round(CLOCK_SKEW_MS / 1000) + ' s) — leimat käyttävät synkattua aikaa';
} else if (CLOCK_SKEW_MS > 2000 && kelloEl) {
    kelloEl.hidden = false;
    kelloEl.textContent = 'Kello synkattu palvelimeen';
    kelloEl.classList.remove('is-pending');
}

function paivitaOfflineJono(lkm) {
    if (!offlineJonoEl) return;
    const n = parseInt(lkm, 10) || 0;
    if (n > 0) {
        offlineJonoEl.hidden = false;
        offlineJonoEl.textContent = n + ' odottaa lähettämistä';
        offlineJonoEl.classList.add('is-pending');
    } else {
        offlineJonoEl.hidden = true;
        offlineJonoEl.textContent = '';
        offlineJonoEl.classList.remove('is-pending');
    }
    lahetaLaiteHeartbeat(n);
}

if (typeof onOfflineQueueChange === 'function') {
    onOfflineQueueChange(paivitaOfflineJono);
}

if (offlineJonoEl) {
    offlineJonoEl.style.cursor = 'pointer';
    offlineJonoEl.title = 'Napauta lähettääksesi offline-jonon';
    offlineJonoEl.addEventListener('click', function () {
        if (typeof yritaOfflineUudelleen === 'function') {
            naytaViesti('Lähetetään offline-jonoa...', '');
            yritaOfflineUudelleen().then(function (r) {
                const left = r && r.left != null ? r.left : 0;
                naytaViesti(
                    left > 0 ? ('Jonossa vielä ' + left) : 'Offline-jono tyhjä',
                    left > 0 ? 'varoitus' : 'ok'
                );
            });
        }
    });
}

function lahetaLaiteHeartbeat(jonoLkm) {
    const kilpailu_id = document.getElementById('kilpailu_id').value;
    const api_token = ajanottoApiToken();
    const body = {
        kilpailu_id: kilpailu_id,
        laite: laiteId() + '-' + TILA,
        nimi: laiteNimi() || undefined,
        tila: TILA,
        jono_lkm: typeof jonoLkm === 'number' ? jonoLkm : 0,
        online: navigator.onLine ? 1 : 0
    };
    if (api_token) body.api_token = api_token;
    try {
        fetch('../api/api_ajanotto_laite.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            keepalive: true
        }).catch(function () {});
    } catch (e) { /* ignore */ }
}

function escapeHtml(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}

function naytaViesti(teksti, tyyppi) {
    if (!viestiEl) return;
    viestiEl.textContent = teksti;
    viestiEl.className = 'mobiili-viesti' + (tyyppi ? ' ' + tyyppi : '');
}

function paivitaLaskuri() {
    if (!laskuriEl || IS_JONO) return;
    const lista = suodatetutKilpailijat();
    const maalissa = lista.filter(k => parseInt(k.maalissa, 10) === 1).length;
    laskuriEl.textContent = maalissa + '/' + lista.length
        + (lista.length !== kilpailijat.length ? (' (' + kilpailijat.length + ')') : '');
}

function suodatetutKilpailijat() {
    const q = (ruudukkoHakuTeksti || '').trim().toLowerCase();
    const sarja = ruudukkoSarjaValinta || '';
    return kilpailijat.filter(function (k) {
        if (sarja && String(k.sarja) !== sarja) return false;
        if (!q) return true;
        return String(k.numero).includes(q)
            || String(k.nimi || '').toLowerCase().includes(q);
    });
}

function paivitaRuudukko() {
    if (!ruudukko) return;
    ruudukko.innerHTML = '';
    const lista = suodatetutKilpailijat();
    if (!lista.length) {
        ruudukko.innerHTML = '<p class="muted">Ei osumia suodattimella.</p>';
        return;
    }
    lista.forEach(k => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'numero-nappi';
        btn.dataset.numero = k.numero;
        const onMaalissa = parseInt(k.maalissa, 10) === 1;

        if (onMaalissa) {
            btn.classList.add('maalissa');
            btn.title = 'Jo maalissa — napauta korvataksesi ajan';
        } else if (k.status !== 'OK') {
            btn.classList.add('estetty');
            btn.disabled = true;
            btn.title = 'Status: ' + k.status;
        }

        btn.innerHTML =
            '<span class="num">' + k.numero + '</span>' +
            '<span class="nimi">' + escapeHtml(k.nimi) + '</span>';

        if (!btn.disabled) {
            btn.addEventListener('click', () => merkitseMaali(k.numero, btn, onMaalissa));
        }
        ruudukko.appendChild(btn);
    });
}

function merkitsePaikallisesti(numero) {
    const k = kilpailijat.find(x => String(x.numero) === String(numero));
    if (k) k.maalissa = 1;
    const btn = ruudukko && ruudukko.querySelector('[data-numero="' + numero + '"]');
    if (btn) {
        btn.classList.remove('lataa');
        btn.classList.add('maalissa');
        btn.title = 'Jo maalissa — napauta korvataksesi ajan';
        btn.disabled = false;
        btn.onclick = null;
        const clone = btn.cloneNode(true);
        btn.parentNode.replaceChild(clone, btn);
        clone.addEventListener('click', () => merkitseMaali(numero, clone, true));
    }
    paivitaLaskuri();
}

function paikallinenMaaliaika(d) {
    // Käytä palvelimen korjattua aikaa (laitteen kello − offset)
    const korjattu = new Date((d ? d.getTime() : Date.now()) - CLOCK_OFFSET_MS);
    const p = n => String(n).padStart(2, '0');
    return korjattu.getFullYear() + '-' + p(korjattu.getMonth() + 1) + '-' + p(korjattu.getDate())
        + 'T' + p(korjattu.getHours()) + ':' + p(korjattu.getMinutes()) + ':' + p(korjattu.getSeconds());
}

function kelloLyhyt(maaliaika) {
    if (!maaliaika) return '';
    const m = String(maaliaika).match(/(\d{2}:\d{2}:\d{2})/);
    return m ? m[1] : String(maaliaika);
}

async function merkitseMaali(numero, btn, joMaalissa) {
    if (saving) return;
    const kilpailu_id = document.getElementById('kilpailu_id').value;
    const tunnettu = (kilpailijat || []).some(function (k) {
        return String(k.numero) === String(numero);
    });
    if (!tunnettu) {
        soita('varoitus');
        naytaViesti('Numeroa #' + numero + ' ei ole kilpailijalistassa', 'virhe');
        return;
    }

    let korvaa = false;
    if (joMaalissa) {
        soitaKonflikti();
        const ok = window.confirm(
            '#' + numero + ' on jo maalissa.\n\n'
            + 'Haluatko korvata maaliajan uudella ajalla?\n'
            + 'Vanha aika kirjataan lokiin.'
        );
        if (!ok) return;
        korvaa = true;
    }

    if (btn) btn.classList.add('lataa');
    saving = true;
    naytaViesti((korvaa ? 'Korvataan #' : 'Tallennetaan #') + numero + '...', '');

    const data = {
        kilpailu_id: kilpailu_id,
        numero: numero,
        maaliaika: paikallinenMaaliaika(),
        laite: laiteId(),
        offline_api: '../api/api_maali.php'
    };
    const api_token = ajanottoApiToken();
    if (api_token) data.api_token = api_token;
    if (korvaa) data.korvaa = 1;

    try {
        const vastaus = await fetch('../api/api_maali.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const tulos = await vastaus.json();

        if (tulos.status === 'ok') {
            merkitsePaikallisesti(numero);
            soita(tulos.korvattu ? 'varoitus' : 'maali');
            naytaViesti(
                (tulos.korvattu ? 'KORVATTU: ' : 'MAALI: ') + (tulos.kilpailija || ('#' + numero)),
                'ok'
            );
        } else if (vastaus.status === 409 || tulos.code === 'duplicate') {
            soitaKonflikti();
            merkitsePaikallisesti(numero);
            const vanha = tulos.vanha_maaliaika ? (' (nyt ' + kelloLyhyt(tulos.vanha_maaliaika) + ')') : '';
            const korvaaOk = window.confirm(
                (tulos.message || 'Jo maalissa') + vanha + '\n\n'
                + 'Korvaa maaliaika uudella ajalla?'
            );
            if (korvaaOk) {
                saving = false;
                if (btn) btn.classList.remove('lataa');
                return merkitseMaali(numero, btn, true);
            }
            naytaViesti(tulos.message || 'Jo maalissa', 'varoitus');
        } else {
            if (btn) btn.classList.remove('lataa');
            soita('varoitus');
            naytaViesti(tulos.message || 'Virhe', 'virhe');
        }
    } catch (e) {
        if (korvaa) {
            soita('varoitus');
            naytaViesti('Korvaus vaatii verkkoyhteyden', 'virhe');
            if (btn) btn.classList.remove('lataa');
            saving = false;
            return;
        }
        try {
            await tallennaOffline(data);
        } catch (err) {
            soita('konflikti');
            naytaViesti('Offline-tallennus epäonnistui', 'virhe');
            if (btn) btn.classList.remove('lataa');
            saving = false;
            return;
        }
        merkitsePaikallisesti(numero);
        soita('ok');
        naytaViesti('Tallennettu offline: #' + numero, 'ok');
    } finally {
        saving = false;
    }
}

async function synkkaaMaalit() {
    const kilpailu_id = document.getElementById('kilpailu_id').value;
    const api_token = ajanottoApiToken();
    try {
        let tilaUrl = '../api/api_maali_tila.php?kilpailu_id=' + encodeURIComponent(kilpailu_id);
        if (api_token) tilaUrl += '&api_token=' + encodeURIComponent(api_token);
        const r = await fetch(
            tilaUrl,
            { credentials: 'same-origin', cache: 'no-store' }
        );
        const data = await r.json();
        if (data.status !== 'ok' || !Array.isArray(data.maalissa)) return;
        const tulos = typeof ajanottoSynkkaaMaalissa === 'function'
            ? ajanottoSynkkaaMaalissa(kilpailijat, data.maalissa)
            : { changed: false, uudet: [] };
        if (tulos.changed) {
            if (!IS_JONO) {
                paivitaRuudukko();
                paivitaLaskuri();
            }
            if (tulos.uudet && tulos.uudet.length && typeof window.onMaalissaSynkka === 'function') {
                window.onMaalissaSynkka(tulos.uudet, data.maalissa);
            }
        }
        window.__maalissaNumerot = data.maalissa;
    } catch (e) { /* ignore */ }
}

// Yhtenäinen "jo maalissa" kaikissa tiloissa
synkkaaMaalit();
setInterval(synkkaaMaalit, 2000);
document.addEventListener('visibilitychange', function () {
    if (!document.hidden) synkkaaMaalit();
});

if (!IS_JONO) {
    paivitaRuudukko();
    if (ruudukkoHaku) {
        ruudukkoHaku.addEventListener('input', function () {
            ruudukkoHakuTeksti = ruudukkoHaku.value || '';
            paivitaRuudukko();
            paivitaLaskuri();
        });
    }
    if (ruudukkoSarja) {
        ruudukkoSarja.addEventListener('change', function () {
            ruudukkoSarjaValinta = ruudukkoSarja.value || '';
            paivitaRuudukko();
            paivitaLaskuri();
        });
    }
}

lahetaLaiteHeartbeat(0);
setInterval(function () {
    if (typeof haeOfflineJononPituus === 'function') {
        haeOfflineJononPituus().then(lahetaLaiteHeartbeat);
    } else {
        lahetaLaiteHeartbeat(0);
    }
}, 15000);
window.addEventListener('online', function () {
    if (typeof lahetaOdottavat === 'function') lahetaOdottavat();
    lahetaLaiteHeartbeat(0);
});

function onJoKokoNaytto() {
    return !!(document.fullscreenElement
        || document.webkitFullscreenElement
        || window.matchMedia('(display-mode: fullscreen)').matches
        || window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true);
}

function pyydaSelainKokoNaytto() {
    if (onJoKokoNaytto()) return Promise.resolve();
    var el = document.documentElement;
    try {
        if (el.requestFullscreen) {
            return el.requestFullscreen({ navigationUI: 'hide' }).catch(function () {});
        }
        if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        }
    } catch (e) { /* ignore */ }
    return Promise.resolve();
}

async function poistuAjanotosta() {
    if (!window.confirm('Haluatko varmasti poistua ajanotosta?')) {
        return;
    }
    try {
        if (document.fullscreenElement && document.exitFullscreen) {
            await document.exitFullscreen();
        } else if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        }
    } catch (e) { /* ignore */ }
    window.location.href = POISTU_URL;
}

document.getElementById('btnPoistu').addEventListener('click', function (ev) {
    ev.preventDefault();
    ev.stopPropagation();
    poistuAjanotosta();
});

document.addEventListener('pointerdown', function () {
    pyydaSelainKokoNaytto();
}, { capture: true, passive: true });
document.addEventListener('keydown', function () {
    pyydaSelainKokoNaytto();
}, { capture: true });

pyydaSelainKokoNaytto();

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js');
}
</script>
<?php if ($is_jono): ?>
<script src="../assets/js/maali_jono.js?v=20261112jono"></script>
<?php endif; ?>

<?php pageEnd(); ?>
