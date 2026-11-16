<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/kisa_liput.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/emit_lukija.php';

vaadiRooli(['admin', 'paakayttaja', 'ajanottaja', 'lahtopaikka', 'iltarasti']);

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
if (!emitLukijaOikeus($kilpailu)) {
    http_response_code(403);
    die('Ei oikeutta tähän kilpailuun');
}

asetaValittuKilpailu($kilpailu_id);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$automaattiSivu = !empty($emitAutomaattiSivu);
if (!$automaattiSivu && (($_GET['moodi'] ?? '') === 'automaatti')) {
    header('Location: automaatti.php?kilpailu_id=' . (int) $kilpailu_id, true, 302);
    exit;
}

$viestiKisa = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
if ($viestiKisa && empty($emitViestiSivu) && empty($automaattiSivu)) {
    $q = $_GET;
    $q['kilpailu_id'] = $kilpailu_id;
    header('Location: viesti_lukija.php?' . http_build_query($q), true, 302);
    exit;
}
$viestiSivu = $viestiKisa || !empty($emitViestiSivu);

$iltarasti = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu);
if ($iltarasti && function_exists('iltarastiVarmista')) {
    iltarastiVarmista($pdo);
}

$sarjat = [];
$st = $pdo->prepare('SELECT id, nimi FROM sarjat WHERE kilpailu_id = ? ORDER BY jarjestys, nimi, id');
$st->execute([$kilpailu_id]);
while ($s = $st->fetch(PDO::FETCH_ASSOC)) {
    $sarjat[] = ['id' => (int) $s['id'], 'nimi' => (string) $s['nimi']];
}

if ($iltarasti) {
    $poistu = '../admin/iltarastit.php';
} elseif (onPaakayttaja() || onAdmin()) {
    $poistu = '../admin/index.php';
} elseif (onAjanottaja()) {
    $poistu = 'ajanotto_valinta.php';
} else {
    $poistu = 'lahto_valinta.php';
}

$emitSivuOtsikko = $emitSivuOtsikko ?? ($iltarasti
    ? (string) ($kilpailu['nimi'] ?? 'Tulospalvelu')
    : ($viestiSivu ? 'Viesti-lukija' : 'Emit-lukija'));
$emitOsanottajat = [];
try {
    $hs = $pdo->prepare($viestiSivu
        ? "
        SELECT k.id, k.etunimi, k.sukunimi, k.nimi, k.seura, k.emit_koodi, k.sarja_id, k.keskhyl,
               k.osuus, k.joukkue_id, j.numero AS joukkue_numero, j.seura AS joukkue_seura, j.joukkue,
               s.nimi AS rata
        FROM kilpailijat k
        LEFT JOIN joukkueet j ON j.id = k.joukkue_id
        LEFT JOIN sarjat s ON s.id = COALESCE(j.sarja_id, k.sarja_id)
        WHERE k.kilpailu_id = ?
        ORDER BY j.numero, k.osuus, k.id
        LIMIT 2500
        "
        : "
        SELECT k.id, k.etunimi, k.sukunimi, k.nimi, k.seura, k.emit_koodi, k.sarja_id, k.keskhyl,
               s.nimi AS rata
        FROM kilpailijat k
        LEFT JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.kilpailu_id = ?
        ORDER BY k.id
        LIMIT 800
        ");
    $hs->execute([$kilpailu_id]);
    foreach ($hs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $h) {
        $rivi = [
            'id' => (int) $h['id'],
            'etunimi' => (string) ($h['etunimi'] ?? ''),
            'sukunimi' => (string) ($h['sukunimi'] ?? ''),
            'nimi' => (string) ($h['nimi'] ?? trim(($h['etunimi'] ?? '') . ' ' . ($h['sukunimi'] ?? ''))),
            'seura' => (string) ($h['seura'] ?? ''),
            'emit' => (int) ($h['emit_koodi'] ?? 0),
            'sarja_id' => (int) ($h['sarja_id'] ?? 0),
            'rata' => (string) ($h['rata'] ?? ''),
            'keskhyl' => (string) ($h['keskhyl'] ?? '-'),
        ];
        if ($viestiSivu) {
            $naytto = function_exists('emitLukijaViestiNaytto')
                ? emitLukijaViestiNaytto($h)
                : ['joukkue_nimi' => '', 'otsikko' => '', 'osuus_txt' => ''];
            $rivi['osuus'] = (int) ($h['osuus'] ?? 0);
            $rivi['joukkue_id'] = (int) ($h['joukkue_id'] ?? 0);
            $rivi['joukkue_numero'] = isset($h['joukkue_numero']) && $h['joukkue_numero'] !== null && $h['joukkue_numero'] !== ''
                ? (int) $h['joukkue_numero']
                : null;
            $rivi['joukkue'] = (string) ($h['joukkue'] ?? '');
            $rivi['joukkue_nimi'] = $naytto['joukkue_nimi'];
            $rivi['osuus_txt'] = $naytto['osuus_txt'];
            if ($rivi['seura'] === '' && !empty($h['joukkue_seura'])) {
                $rivi['seura'] = (string) $h['joukkue_seura'];
            }
        }
        $emitOsanottajat[] = $rivi;
    }
} catch (Throwable $e) {
    $emitOsanottajat = [];
}

$emitNimet = [];
$rlIlmoTeksti = '';
if ($iltarasti && function_exists('rastilippuKilpailuYhdistetty') && rastilippuKilpailuYhdistetty($kilpailu)) {
    if (function_exists('rastilippuPaivitaIlmoittautumiset')) {
        try {
            rastilippuPaivitaIlmoittautumiset($pdo, $kilpailu, false);
            $tuore = haeKilpailu($pdo, $kilpailu_id);
            if (is_array($tuore)) {
                $kilpailu = $tuore;
            }
        } catch (Throwable $e) {
        }
    }
    if (function_exists('rastilippuHaeIlmoittautumiset')) {
        foreach (rastilippuHaeIlmoittautumiset($pdo, $kilpailu_id) as $r) {
            $emitNimet[] = function_exists('rastilippuIlmoittautuminenNayttoon')
                ? rastilippuIlmoittautuminenNayttoon($r)
                : $r;
        }
    }
    $rlIlmoTeksti = trim((string) ($kilpailu['rastilippu_ilmo_viesti'] ?? ''));
}

$emitCssV = '20261116hamp';
$extraHead = '<meta name="mobile-web-app-capable" content="yes">'
    . '<meta name="apple-mobile-web-app-capable" content="yes">'
    . '<meta name="theme-color" content="#0b3d2e">'
    . '<link rel="stylesheet" href="../assets/css/emit_lukija.css?v=' . $emitCssV . '">';

if ($iltarasti) {
    $opts = iltarastiKuoriOpts($pdo, $kilpailu, !empty($automaattiSivu) ? 'automaatti' : 'tulospalvelu', 'lahtoluennat-page emit-lukija-page emit-iltarasti' . (!empty($automaattiSivu) ? ' emit-automaatti-sivu' : ''));
    $opts['extraHead'] = $extraHead;
    pageStart($emitSivuOtsikko, $opts);
    jahMainosMerkki();
} else {
    pageStart($emitSivuOtsikko, [
        'class' => 'lahtoluennat-page emit-lukija-page' . ($viestiSivu ? ' emit-viesti-sivu' : ''),
        'base' => '../',
        'lukija' => true,
        'hallintaHref' => $poistu,
        'extraHead' => $extraHead,
    ]);
}

$rataValinnat = static function (array $sarjat): void {
    foreach ($sarjat as $s) {
        echo '<option value="' . (int) $s['id'] . '">' . htmlspecialchars($s['nimi']) . '</option>';
    }
};
?>

<?php if (!$iltarasti): ?>
<div class="timing-header">
    <h1><?= htmlspecialchars($emitSivuOtsikko) ?></h1>
    <p class="muted"><?= htmlspecialchars($kilpailu['nimi']) ?></p>
</div>

<?php if (function_exists('demoKisaIlmoitus')) {
    echo demoKisaIlmoitus($kilpailu);
} ?>
<?php endif; ?>

<div class="emit-yla">
    <div class="emit-moodit" role="tablist">
        <button type="button" class="emit-moodi is-valittu" data-moodi="lahto"><?= !empty($viestiSivu) ? '1. osuus' : 'Sisäänluku' ?></button>
        <button type="button" class="emit-moodi" data-moodi="maali"><?= !empty($viestiSivu) ? 'Vaihto / maali' : 'Leimantarkastus' ?></button>
    </div>
    <div class="emit-yhteys<?= $iltarasti ? ' emit-yhteys--ikoni' : '' ?>">
        <button type="button" id="yhdista" class="emit-yhdista<?= $iltarasti ? ' emit-yhdista--kutsu' : '' ?>"><?= $iltarasti ? 'Yhdistä lukija' : 'Yhdistä USB-lukija' ?></button>
        <button type="button" id="lukija-yhteys" class="emit-lukija-tila" hidden>Lukija yhdistetty</button>
        <?php if (!$iltarasti): ?>
        <label class="emit-baud">Nopeus
            <select id="baud">
                <option value="115200" selected>USB 115200</option>
                <option value="19200">RS485 19200</option>
                <option value="9600">RS232 9600</option>
            </select>
        </label>
        <p id="yhteys-tila" class="lukija-status">Lukijaa ei ole yhdistetty</p>
        <p class="muted">Paina Yhdistä. Ensimmäisellä kerralla selain voi kysyä USB-lukijaa; sen jälkeen yhdistyy suoraan. Kortti saa olla lukijassa.</p>
        <p id="laite-rivi" class="muted"></p>
        <?php else: ?>
        <select id="baud" hidden>
            <option value="115200">USB 115200</option>
            <option value="19200">RS485 19200</option>
            <option value="9600" selected>RS232 9600</option>
        </select>
        <p id="yhteys-tila" class="lukija-status" hidden></p>
        <p id="laite-rivi" hidden></p>
        <?php endif; ?>
    </div>
</div>
<p id="emit-jono" class="emit-jono" hidden></p>
<?php if ($iltarasti && $rlIlmoTeksti !== ''): ?>
<p class="muted emit-ilmo-tila"><?= htmlspecialchars($rlIlmoTeksti) ?></p>
<?php endif; ?>

<?php if (!$iltarasti): ?>
<p id="moodi-ohje" class="muted emit-ohje"><?= !empty($viestiSivu)
    ? 'Lue 1. osuuden kortti lähdössä. Vaihtoon ja maaliin lue kortti Vaihto / maali -tilassa.'
    : 'Lue kortti lähdössä. Uusi kortti kysyy, luetaanko lähteväksi vai maaliin.' ?></p>
<p class="muted emit-ohje"><?= !empty($viestiSivu)
    ? 'Vaihdossa kirjataan osuuden rastit ja aika. Seuraavan osuuden lähtö on tämä vaihto. Viimeinen osuus on joukkueen maali.'
    : 'Lähtöaika tulee nollaleimauksesta. Jo lähtölistalla oleva kortti menee automaattisesti leimantarkastukseen, jos kortilla on rastit.' ?></p>
<?php else: ?>
<p id="moodi-ohje" hidden></p>
<?php endif; ?>

<?php if ($iltarasti): ?>
<div id="emit-tarkistus" class="emit-tarkistus">
<?php endif; ?>
<div id="tulos" class="timing-viesti"<?= $iltarasti ? ' hidden' : '' ?>>
    <div id="emit-yhteenveto"><?= $iltarasti ? '' : 'Odottaa korttia…' ?></div>
    <div id="emit-usb"></div>
    <pre id="emit-loki"<?= $iltarasti ? ' hidden' : '' ?>></pre>
</div>
<div id="leima-lista" class="emit-leimat" hidden></div>
<div id="emit-paatos" class="emit-paatos" hidden>
    <p class="emit-paatos-otsikko"<?= $iltarasti ? ' hidden' : '' ?>>Esitys — rastit eivät täsmää. Valitse päätös tai vaihda rata.</p>
    <div id="emit-arvaus" class="emit-arvaus" hidden>
        <p id="arvaus-teksti" class="emit-arvaus-teksti"></p>
        <button type="button" id="arvaus-vaihda" class="emit-yhdista emit-arvaus-nappi">Vaihda tälle radalle</button>
    </div>
    <label for="paatos-rata">Rata
        <select id="paatos-rata"></select>
    </label>
    <div class="emit-paatos-napit">
        <button type="button" id="paatos-ok" class="emit-moodi">Hyväksytty</button>
        <button type="button" id="paatos-hyl" class="emit-moodi"><?= $iltarasti ? 'Hyl' : 'Hylätty' ?></button>
        <button type="button" id="paatos-kes" class="emit-moodi"><?= $iltarasti ? 'Kesk' : 'Keskeyttänyt' ?></button>
    </div>
</div>
<?php if ($iltarasti): ?>
</div>
<?php endif; ?>

<div id="emit-kysy" class="emit-kysy" hidden>
    <p class="emit-kysy-otsikko"><?= $iltarasti
        ? 'Uusi kortti. Luetaanko lähteväksi vai pois maastosta?'
        : (!empty($viestiSivu)
            ? 'Tuntematon kortti. Luetaanko 1. osuuden lähteväksi vai vaihtoon/maaliin?'
            : 'Uusi kortti. Luetaanko lähteväksi vai maaliin?') ?></p>
    <div class="emit-kysy-napit">
        <button type="button" id="kysy-lahto" class="emit-moodi"><?= !empty($viestiSivu) ? '1. osuus' : 'Lähteväksi' ?></button>
        <button type="button" id="kysy-maali" class="emit-moodi"><?= $iltarasti ? 'Pois maastosta' : (!empty($viestiSivu) ? 'Vaihto / maali' : 'Maaliin') ?></button>
    </div>
</div>
<div id="emit-ehdotukset" class="emit-ehdotukset" hidden>
    <p class="emit-ehdotukset-otsikko">Tämän kortin tallennetut nimet</p>
    <p id="emit-maksu" class="emit-maksu" hidden></p>
    <div id="emit-ehdotukset-lista" class="emit-ehdotukset-lista"></div>
    <button type="button" id="emit-uusi-henkilo" class="emit-moodi emit-uusi-henkilo">Uusi henkilö tälle kortille</button>
</div>

<form id="lisaa-kentat" class="emit-lisaa" data-no-page-loader<?= $iltarasti ? '' : ' hidden' ?>>
    <p id="lisaa-emit" class="emit-luettu"<?= $iltarasti ? ' hidden' : '' ?>></p>
    <p id="lisaa-vanha" class="muted" hidden></p>
    <?php if (!$sarjat): ?>
        <p class="error"><?= $iltarasti ? 'Ratoja ei ole. Lisää rata tapahtuman asetuksissa.' : 'Sarjoja ei ole. Lisää sarja kilpailun hallinnassa.' ?></p>
    <?php else: ?>
        <?php if ($iltarasti): ?>
            <input type="hidden" id="kilpailija-id" value="">
            <div class="emit-nimet">
                <label>Etunimi <input id="etunimi" autocomplete="off"></label>
                <label>Sukunimi <input id="sukunimi" autocomplete="off" required></label>
            </div>
            <div class="emit-pari">
                <label>Seura <input id="seura" autocomplete="off"></label>
                <label for="sarja">Rata
                    <select id="sarja">
                        <?php $rataValinnat($sarjat); ?>
                    </select>
                </label>
            </div>
            <label>Kilpailukortti <input id="emit-koodi" inputmode="numeric" autocomplete="off"></label>
            <div class="emit-rukit">
                <label class="checkbox-label"><input type="checkbox" id="lainakortti"> Lainakortti</label>
                <label class="checkbox-label"><input type="checkbox" id="ei-aikaa"> Ei aikaa</label>
            </div>
            <button type="submit" class="emit-yhdista" id="lisaa-tallenna">Kirjaa</button>
        <?php else: ?>
        <label for="sarja">Sarja
            <select id="sarja">
                <?php $rataValinnat($sarjat); ?>
            </select>
        </label>
        <div class="emit-nimet">
            <label>Etunimi <input id="etunimi" autocomplete="off"></label>
            <label>Sukunimi <input id="sukunimi" autocomplete="off"></label>
            <label>Seura <input id="seura" autocomplete="off"></label>
        </div>
        <button type="submit" class="emit-yhdista" id="lisaa-tallenna">Tallenna</button>
        <?php endif; ?>
    <?php endif; ?>
</form>

<?php if (!$iltarasti): ?>
<form id="kasin-form" class="timing-form emit-kasin" data-no-page-loader autocomplete="off">
    <input type="text" id="kasin-badge" class="timing-input" inputmode="numeric"
        autocomplete="off" placeholder="Tai näppäile emit-numero">
    <button type="submit">Kirjaa</button>
</form>
<p id="maali-huom" class="muted" hidden>Maalileimat tulevat USB-lukijasta. Näppäilty numero ei sisällä rastileimoja.</p>

<details class="emit-asetukset">
    <summary>Lukijan asetukset</summary>
    <p class="muted">Koodi 250 lukee viimeisimmän kilpailun leimat. Koodi 248 on maaliantenni.</p>
    <div class="emit-asetusnapit">
        <button type="button" id="kysy-tila" disabled>Kysy tila</button>
        <button type="button" id="synkkaa-kello" disabled>Synkronoi kello</button>
        <button type="button" id="koodi-250" disabled>Aseta koodi 250</button>
        <button type="button" id="koodi-248" disabled>Aseta koodi 248</button>
    </div>
    <label class="emit-nolla">Kilpailun nollaaika
        <input id="nolla" type="time" step="1">
    </label>
    <p class="muted">Kun nollaaika on asetettu, leimoista lasketaan juoksuajat rastiväliajoihin. Ilman sitä leimat tallentuvat kellonaikoina.</p>
</details>

<h2>Viimeisimmät</h2>
<?php else: ?>
<input id="nolla" type="time" step="1" hidden>
<form id="kasin-form" data-no-page-loader hidden>
    <input type="text" id="kasin-badge" inputmode="numeric" autocomplete="off">
    <button type="submit" hidden>Kirjaa</button>
</form>
<p id="maali-huom" hidden></p>
<?php endif; ?>

<ul id="viimeiset" class="lahto-list"></ul>

<?php if (!$iltarasti): ?>
<p class="muted" hidden>
    <a class="lukija-poistu" href="<?= htmlspecialchars($poistu) ?>">← Takaisin</a>
</p>
<?php endif; ?>

<script>
window.JAH_EMIT = {
    kilpailuId: <?= (int) $kilpailu_id ?>,
    sarjoja: <?= count($sarjat) ?>,
    iltarasti: <?= $iltarasti ? 'true' : 'false' ?>,
    viesti: <?= !empty($viestiSivu) ? 'true' : 'false' ?>,
    automaattiSivu: <?= !empty($automaattiSivu) ? 'true' : 'false' ?>,
    alkuMoodi: <?= json_encode(in_array(($_GET['moodi'] ?? ''), ['lahto', 'maali'], true) ? (string) $_GET['moodi'] : '') ?>,
    radat: <?= json_encode(function_exists('emitLukijaRataLista') ? emitLukijaRataLista($pdo, $kilpailu_id) : [], JSON_UNESCAPED_UNICODE) ?>,
    osanottajat: <?= json_encode($emitOsanottajat, JSON_UNESCAPED_UNICODE) ?>,
    nimet: <?= json_encode($emitNimet, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="../assets/js/wake_lock.js?v=20260718a"></script>
<script type="module" src="../assets/js/usb_sarja_boot.js?v=20261114pcusb3"></script>
<script src="../assets/js/emit_proto.js?v=20261114tyhja"></script>
<script src="/assets/js/jah-radat-offline.js?v=20261107radat"></script>
<script src="../assets/js/si_proto.js?v=20261112si"></script>
<script src="../assets/js/emit_lukija.js?v=20261116viesti"></script>
<script>
(function () {
    if (window.JAH_EMIT_VERSIO === 'automaatti24') {
        return;
    }
    var el = document.getElementById('emit-yhteenveto');
    if (el) {
        el.textContent = 'Puhelimen muistissa on vanha lukijaohjelma. Ladataan sivu uudelleen.';
    }
    try {
        if (sessionStorage.getItem('jah-emit-automaatti24')) {
            return;
        }
        sessionStorage.setItem('jah-emit-automaatti24', '1');
    } catch (e) {
        return;
    }
    var lataa = function () { location.reload(); };
    if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            return Promise.all(regs.map(function (r) { return r.update(); }));
        }).then(lataa, lataa);
        return;
    }
    lataa();
})();
</script>

<?php pageEnd(); ?>
