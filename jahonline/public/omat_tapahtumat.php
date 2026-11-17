<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tili.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
if (is_readable(__DIR__ . '/../includes/kilpailu_poisto.php')) {
    require_once __DIR__ . '/../includes/kilpailu_poisto.php';
}
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../includes/kisa_luonne.php';
if (is_readable(__DIR__ . '/../includes/kisa_osoite.php')) {
    require_once __DIR__ . '/../includes/kisa_osoite.php';
}
if (is_readable(__DIR__ . '/../includes/palvelu_valitsin.php')) {
    require_once __DIR__ . '/../includes/palvelu_valitsin.php';
}

vaadiKirjautuminen();
tiliVarmistaTaulut($pdo);
tiliLataaKayttajaSessioon($pdo);
kayttajaRoolitVarmista($pdo);
iltarastiVarmista($pdo);
kisaLuonneVarmista($pdo);

$oikeudet = function_exists('jahPalveluOikeudet')
    ? jahPalveluOikeudet($pdo)
    : ['gps' => false, 'tulokset' => false, 'iltarastit' => false];
$voiSuunnistus = !empty($oikeudet['iltarastit']) || (function_exists('iltarastiVoiPalvelun') && iltarastiVoiPalvelun());
$voiKilpailut = !empty($oikeudet['tulokset']) || (function_exists('voiKisahallintaan') && voiKisahallintaan());
$voiPirila = function_exists('tiliKayttajaPirilaPaalla') && tiliKayttajaPirilaPaalla();
if (!$voiSuunnistus && !$voiKilpailut && !$voiPirila && !(function_exists('onAdmin') && onAdmin())) {
    http_response_code(403);
    die('Ei käyttöoikeutta omiin tapahtumiin.');
}

$seuraId = nykyinenSeuraId();
$seura = tiliHaeSeuraKayttajalle($pdo, (int) ($_SESSION['user_id'] ?? 0));
if ($seura) {
    $seuraId = (int) $seura['id'];
}
$voiAvaimen = function_exists('iltarastiVoiAvaimen') && iltarastiVoiAvaimen();
$liitanta = $seura && function_exists('tiliSeuraRastilippuPaalla') && tiliSeuraRastilippuPaalla($seura);
$postiOk = $seura && function_exists('tiliSeuraLaskutuspostiOk') && tiliSeuraLaskutuspostiOk($seura);
$seuraNimiOk = function_exists('iltarastiSeurallaOnNimi') && iltarastiSeurallaOnNimi($seura);
$voiViesti = function_exists('tiliKayttajaViestiPaalla') && tiliKayttajaViestiPaalla();
$viesti = trim((string) ($_GET['viesti'] ?? ''));
$virhe = '';
if (!empty($_GET['virhe']) && (string) $_GET['virhe'] !== '1') {
    $virhe = trim((string) $_GET['virhe']);
}
$naytaAvain = $voiAvaimen && $liitanta && (isset($_GET['avain']) || !$seuraId);
$avainTallennettu = $liitanta && $seuraId > 0 && rastilippuHaeAvain($pdo, $seuraId) !== null;
$avainUuid = ($liitanta && $seuraId > 0) ? rastilippuHaeUuid($pdo, $seuraId) : null;

$suunnistukset = [];
$kilpailut = [];
if ($voiSuunnistus && $seuraId > 0) {
    iltarastiPaivitaSeuranTilat($pdo, $seuraId);
    $suunnistukset = iltarastiHaeSeuran($pdo, $seuraId);
}
if (($voiKilpailut || $voiPirila) && function_exists('haeKayttajanKilpailut')) {
    foreach (haeKayttajanKilpailut($pdo, 'k.paiva DESC, k.id DESC') as $k) {
        if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($k)) {
            continue;
        }
        if (($k['tila'] ?? '') === 'arkisto') {
            continue;
        }
        $onPirila = function_exists('kisaOnPirila') && kisaOnPirila($k);
        if ($onPirila && !$voiPirila && !$voiKilpailut) {
            continue;
        }
        if (!$onPirila && !$voiKilpailut) {
            continue;
        }
        $kilpailut[] = $k;
    }
}

$lataaOmatListat = static function () use (
    &$suunnistukset,
    &$kilpailut,
    $voiSuunnistus,
    $voiKilpailut,
    $voiPirila,
    $seuraId,
    $pdo
): void {
    if ($voiSuunnistus && $seuraId > 0) {
        $suunnistukset = iltarastiHaeSeuran($pdo, $seuraId);
    }
    if (($voiKilpailut || $voiPirila) && function_exists('haeKayttajanKilpailut')) {
        $kilpailut = [];
        foreach (haeKayttajanKilpailut($pdo, 'k.paiva DESC, k.id DESC') as $k) {
            if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($k)) {
                continue;
            }
            if (($k['tila'] ?? '') === 'arkisto') {
                continue;
            }
            $onPirila = function_exists('kisaOnPirila') && kisaOnPirila($k);
            if ($onPirila && !$voiPirila && !$voiKilpailut) {
                continue;
            }
            if (!$onPirila && !$voiKilpailut) {
                continue;
            }
            $kilpailut[] = $k;
        }
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }
    $toiminto = (string) ($_POST['toiminto'] ?? '');
    try {
        if ($toiminto === 'luo_seura') {
            if (!$voiAvaimen) {
                throw new RuntimeException('Vain seuran pääkäyttäjä voi nimetä seuran.');
            }
            $nimi = trim((string) ($_POST['seura_nimi'] ?? ''));
            if ($nimi === '') {
                throw new RuntimeException('Anna seuran nimi.');
            }
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            if ($seuraId < 1) {
                $luotu = tiliLuoSeuraKayttajalle($pdo, $uid, $nimi, 'ilmainen');
                $seuraId = (int) ($luotu['seura_id'] ?? 0);
                if (kayttajaRooli() === 'iltarasti' && $seuraId > 0) {
                    tiliAsetaKayttajanSeura($pdo, $uid, $seuraId, 'omistaja', 'iltarastit');
                }
            } else {
                $pdo->prepare('UPDATE seurat SET nimi = ? WHERE id = ?')->execute([mb_substr($nimi, 0, 255), $seuraId]);
            }
            tiliLataaKayttajaSessioon($pdo);
            header('Location: omat_tapahtumat.php?viesti=' . urlencode('Seuran nimi tallennettu.'));
            exit;
        }
        if ($toiminto === 'tallenna_avain') {
            if (!$voiAvaimen) {
                throw new RuntimeException('Vain seuran pääkäyttäjä voi tallentaa Rastilippu-avaimen.');
            }
            if (function_exists('tiliVaadiRastilippuLiitanta')) {
                tiliVaadiRastilippuLiitanta($pdo, $seuraId);
            }
            if ($seuraId < 1) {
                throw new RuntimeException('Seura puuttuu. Anna ensin seuran nimi.');
            }
            $jwt = trim((string) ($_POST['rastilippu_token'] ?? ''));
            if ($jwt === '' && !empty($_POST['poista_avain'])) {
                rastilippuTallennaAvain($pdo, $seuraId, '');
                header('Location: omat_tapahtumat.php?viesti=' . urlencode('Rastilippu-avain poistettu.'));
                exit;
            }
            if ($jwt === '') {
                throw new RuntimeException('Liitä JWT-avain tai poista nykyinen avain.');
            }
            rastilippuTallennaAvain($pdo, $seuraId, $jwt, null);
            $testi = rastilippuTestaa($jwt);
            if (!$testi['ok']) {
                header('Location: omat_tapahtumat.php?avain=1&viesti=' . urlencode('Avain tallennettu, mutta testi epäonnistui: ' . (string) $testi['virhe']));
                exit;
            }
            try {
                rastilippuTapahtumatSeuralle($pdo, $seuraId, true);
            } catch (Throwable $e) {
            }
            header('Location: omat_tapahtumat.php?viesti=' . urlencode('Rastilippu-avain tallennettu. Tapahtumia: ' . (int) $testi['lkm'] . '.'));
            exit;
        }
        if ($toiminto === 'luo_tapahtuma' || $toiminto === 'luo_paikallinen' || $toiminto === 'luo_juoksu') {
            if ($seuraId < 1) {
                throw new RuntimeException('Seura puuttuu.');
            }
            $malli = (string) ($_POST['malli'] ?? '');
            if ($toiminto === 'luo_paikallinen' && $malli === '') {
                $malli = 'suunnistus';
            }
            if ($toiminto === 'luo_juoksu' && $malli === '') {
                $malli = 'juoksu';
            }
            if (!in_array($malli, ['suunnistus', 'juoksu', 'pirila'], true)) {
                throw new RuntimeException('Valitse tapahtuman tyyppi.');
            }
            $omistaja = (int) (paakayttajaId() ?? kayttajaId() ?? 0);
            if ($malli === 'suunnistus') {
                if (!$voiSuunnistus) {
                    throw new RuntimeException('Ei oikeutta suunnistustapahtumiin.');
                }
                $tyyppi = (string) ($_POST['tyyppi'] ?? 'henkilokohtainen');
                if ($tyyppi !== 'viesti') {
                    $tyyppi = 'henkilokohtainen';
                }
                if ($tyyppi === 'viesti' && !$voiViesti) {
                    throw new RuntimeException('Viestikilpailu ei ole käytössä tällä tunnuksella.');
                }
                $kisa = iltarastiLuoPaikallinen(
                    $pdo,
                    $seuraId,
                    (string) ($_POST['nimi'] ?? ''),
                    (string) ($_POST['paiva'] ?? ''),
                    (string) ($_POST['paikka'] ?? ''),
                    $omistaja,
                    $tyyppi
                );
                if (function_exists('kisaOsoiteTallenna')) {
                    kisaOsoiteTallenna($pdo, (int) $kisa['id'], (string) ($_POST['osoite'] ?? ''), null);
                }
                asetaValittuKilpailu((int) $kisa['id']);
                header('Location: ' . iltarastiTulospalveluUrl((int) $kisa['id']));
                exit;
            }
            if ($malli === 'juoksu') {
                if (!$voiKilpailut) {
                    throw new RuntimeException('Ei oikeutta juoksukilpailuihin.');
                }
                $kisa = kisaLuoJuoksu(
                    $pdo,
                    $seuraId,
                    (string) ($_POST['nimi'] ?? ''),
                    (string) ($_POST['paiva'] ?? ''),
                    (string) ($_POST['paikka'] ?? ''),
                    $omistaja
                );
                if (function_exists('kisaOsoiteTallenna')) {
                    kisaOsoiteTallenna($pdo, (int) $kisa['id'], (string) ($_POST['osoite'] ?? ''), null);
                }
                asetaValittuKilpailu((int) $kisa['id']);
                header('Location: /admin/index.php');
                exit;
            }
            if (!$voiPirila) {
                throw new RuntimeException('Ei oikeutta Suunnistus-Pirilä-tapahtumiin.');
            }
            $kisa = kisaLuoPirila(
                $pdo,
                $seuraId,
                (string) ($_POST['nimi'] ?? ''),
                (string) ($_POST['paiva'] ?? ''),
                (string) ($_POST['paikka'] ?? ''),
                $omistaja
            );
            if (function_exists('kisaOsoiteTallenna')) {
                kisaOsoiteTallenna($pdo, (int) $kisa['id'], (string) ($_POST['osoite'] ?? ''), null);
            }
            asetaValittuKilpailu((int) $kisa['id']);
            header('Location: /admin/index.php');
            exit;
        }
        if ($toiminto === 'poista_kilpailu') {
            $kid = (int) ($_POST['kilpailu_id'] ?? 0);
            if ($kid < 1 || !function_exists('kilpailuPoistaHallinnasta')) {
                throw new RuntimeException('Kilpailua ei löydy.');
            }
            kilpailuPoistaHallinnasta($pdo, $kid, $_POST);
            header('Location: omat_tapahtumat.php?viesti=' . urlencode('Kilpailu poistettu.'));
            exit;
        }
        if ($toiminto === 'valitse_tapahtuma') {
            $kid = (int) ($_POST['kilpailu_id'] ?? 0);
            $k = $kid > 0 ? haeKilpailu($pdo, $kid) : null;
            if (!$k || !kisaOnIltarasti($k) || !voiHallitaKilpailua($k)) {
                throw new RuntimeException('Tapahtumaa ei löydy.');
            }
            asetaValittuKilpailu($kid);
            header('Location: ' . iltarastiTulospalveluUrl($kid));
            exit;
        }
        if ($toiminto === 'valitse_kilpailu') {
            $kid = (int) ($_POST['kilpailu_id'] ?? 0);
            $k = $kid > 0 && function_exists('haeKilpailuKayttoon')
                ? haeKilpailuKayttoon($pdo, $kid)
                : ($kid > 0 ? haeKilpailuHallintaan($pdo, $kid) : null);
            if (!$k) {
                throw new RuntimeException('Kilpailua ei löydy.');
            }
            asetaValittuKilpailu($kid);
            header('Location: /admin/index.php');
            exit;
        }
    } catch (Throwable $e) {
        $virhe = $e->getMessage();
        $lataaOmatListat();
    }
}

$sallitutMallit = [];
if ($voiSuunnistus) {
    $sallitutMallit['suunnistus'] = 'Suunnistus';
}
if ($voiKilpailut) {
    $sallitutMallit['juoksu'] = 'Juoksu';
}
if ($voiPirila) {
    $sallitutMallit['pirila'] = 'Suunnistus-Pirilä';
}
$oletusMalli = array_key_first($sallitutMallit) ?: 'suunnistus';

$tapahtumat = [];
foreach ($suunnistukset as $k) {
    $tapahtumat[(int) $k['id']] = $k;
}
foreach ($kilpailut as $k) {
    $tapahtumat[(int) $k['id']] = $k;
}
$tapahtumat = array_values($tapahtumat);
usort($tapahtumat, static function (array $a, array $b): int {
    $pa = (string) ($a['paiva'] ?? '');
    $pb = (string) ($b['paiva'] ?? '');
    if ($pa !== $pb) {
        return $pb <=> $pa;
    }

    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

pageStart('Omat tapahtumat', [
    'admin' => true,
    'active' => 'omat_tapahtumat',
    'pdo' => $pdo,
    'palvelu' => $voiSuunnistus && !$voiKilpailut && !$voiPirila ? 'iltarastit' : 'tulokset',
    'extraHead' => '<style>
.omat-tyyppi{display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin:.35rem 0 .6rem}
.omat-tyyppi label{display:flex;align-items:center;gap:.4rem;font-weight:600}
.omat-lista{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.55rem}
.omat-lista .muted{margin:0}
.omat-kisa{display:flex;flex-wrap:wrap;gap:.75rem 1rem;align-items:center;justify-content:space-between;padding:.75rem .9rem;border:1px solid var(--border);border-radius:10px;background:var(--card)}
.omat-kisa-tiedot{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:baseline;gap:.35rem .7rem}
.omat-kisa-toiminnot{display:flex;flex-direction:column;gap:.35rem;flex:0 0 auto;min-width:5.2rem}
.omat-kisa-toiminnot form{margin:0}
.omat-kisa-toiminnot .omat-avaa,.omat-kisa-toiminnot .omat-poista,.omat-kisa-toiminnot .omat-asetukset{width:100%;min-width:5.2rem;text-align:center;box-sizing:border-box}
.omat-kisa-toiminnot .omat-avaa{background:#15803d;color:#fff}
.omat-kisa-toiminnot .omat-avaa:hover{background:#166534}
.omat-kisa-toiminnot .omat-poista{background:#dc2626;color:#fff}
.omat-kisa-toiminnot .omat-poista:hover{background:#b91c1c;filter:none}
.pirila-kehys{border:1px solid var(--border);border-radius:10px;padding:.75rem 1rem;margin:.5rem 0 1rem}
</style>',
]);
?>

<h1>Omat tapahtumat</h1>
<p class="muted">
    Luo tapahtuma yhdellä lomakkeella. Tyypit näkyvät seuran ja tunnuksen oikeuksien mukaan:
    suunnistus, juoksu ja Suunnistus-Pirilä. Rastilippu-liitäntä valitaan henkilökohtaisen
    suunnistustapahtuman asetuksista. Pirilä-asetukset ovat kisan asetuksissa vain Pirilä-tapahtumalle.
</p>

<?php if ($viesti !== ''): ?>
    <p class="notice"><?= htmlspecialchars($viesti) ?></p>
<?php endif; ?>
<?php if ($virhe !== ''): ?>
    <p class="error"><?= htmlspecialchars($virhe) ?></p>
<?php endif; ?>

<?php if ($voiAvaimen && !$seuraNimiOk): ?>
    <div class="public-card" style="margin-bottom:1.25rem">
        <h2>Seuran nimi</h2>
        <p class="muted">Anna seuran nimi ennen tapahtumien luontia. Rastilippu-avain tallennetaan tälle seuralle.</p>
        <form method="post" class="form-grid" style="max-width:28rem">
            <?= csrfKentta() ?>
            <input type="hidden" name="toiminto" value="luo_seura">
            <label>Seuran nimi <input name="seura_nimi" required maxlength="255" value="<?= htmlspecialchars((string) ($seura['nimi'] ?? '')) ?>"></label>
            <div class="form-actions">
                <button type="submit">Tallenna seuran nimi</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="public-card" style="margin-bottom:1.25rem">
    <h2>Tapahtumat</h2>
    <?php if ($tapahtumat === []): ?>
        <p class="muted">Ei tapahtumia vielä.</p>
    <?php else: ?>
        <ul class="omat-lista">
            <?php foreach ($tapahtumat as $k): ?>
                <?php
                $onIltarasti = function_exists('kisaOnIltarasti') && kisaOnIltarasti($k);
                $onViesti = $onIltarasti && function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($k);
                $pvmTxt = !empty($k['paiva']) && function_exists('formatPaiva') ? formatPaiva($k['paiva']) : '';
                $malliTxt = function_exists('tapahtumaMalliLabel') ? tapahtumaMalliLabel($k) : '';
                ?>
                <li class="omat-kisa">
                    <div class="omat-kisa-tiedot">
                        <strong><?= htmlspecialchars((string) $k['nimi']) ?></strong>
                        <?php if ($pvmTxt !== ''): ?><span class="muted"><?= htmlspecialchars($pvmTxt) ?></span><?php endif; ?>
                        <?php if ($malliTxt !== ''): ?><span class="muted"><?= htmlspecialchars($malliTxt) ?></span><?php endif; ?>
                        <?php if ($onIltarasti): ?>
                            <span class="muted"><?= $onViesti ? 'Viesti' : 'Henkilökohtainen' ?></span>
                        <?php endif; ?>
                        <span class="muted"><?= htmlspecialchars(kilpailuTilaLabel($k['tila'] ?? '')) ?></span>
                    </div>
                    <div class="omat-kisa-toiminnot">
                        <form method="post">
                            <?= csrfKentta() ?>
                            <input type="hidden" name="toiminto" value="<?= $onIltarasti ? 'valitse_tapahtuma' : 'valitse_kilpailu' ?>">
                            <input type="hidden" name="kilpailu_id" value="<?= (int) $k['id'] ?>">
                            <button type="submit" class="btn-small omat-avaa">Avaa</button>
                        </form>
                        <a class="btn-small btn-secondary omat-asetukset" href="/admin/kilpailut.php?muokkaa=<?= (int) $k['id'] ?>">Asetukset</a>
                        <?php if (function_exists('voiHallitaKilpailua') && voiHallitaKilpailua($k) && function_exists('kilpailuPoistoLomakeHtml')): ?>
                            <?= kilpailuPoistoLomakeHtml($k, [
                                'kohde' => $onIltarasti ? 'tapahtuman' : 'kilpailun',
                                'btn_class' => 'btn-danger btn-small omat-poista',
                            ]) ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($seuraId > 0 && $sallitutMallit !== []): ?>
        <h3 style="margin-top:1.2rem">Luo tapahtuma</h3>
        <p class="muted">Valitse tyyppi oikeuksiesi mukaan. Rastilippu-liitäntä on toistaiseksi vain henkilökohtaisessa suunnistuksessa, ja se kytketään tapahtuman asetuksista.</p>
        <form method="post" class="form-grid" id="luo-tapahtuma">
            <?= csrfKentta() ?>
            <input type="hidden" name="toiminto" value="luo_tapahtuma">
            <?php
            $luoNimi = trim((string) ($_POST['nimi'] ?? $_GET['nimi'] ?? ''));
            $luoPaiva = trim((string) ($_POST['paiva'] ?? $_GET['paiva'] ?? ''));
            if ($luoPaiva === '') {
                $luoPaiva = iltarastiTanaan();
            }
            $luoPaikka = trim((string) ($_POST['paikka'] ?? $_GET['paikka'] ?? ''));
            $luoOsoite = trim((string) ($_POST['osoite'] ?? $_GET['osoite'] ?? ''));
            ?>
            <?php if (isset($_GET['irma'])): ?>
                <p class="notice">Tiedot täytetty IRMA-kalenterista. Tarkista tyyppi ja luo tapahtuma.</p>
            <?php endif; ?>
            <label>Nimi <input name="nimi" required maxlength="255" placeholder="Tapahtuman nimi" value="<?= htmlspecialchars($luoNimi) ?>"></label>
            <label>Päivä <input type="date" name="paiva" required value="<?= htmlspecialchars($luoPaiva) ?>"></label>
            <label>Paikka <input name="paikka" maxlength="255" value="<?= htmlspecialchars($luoPaikka) ?>"></label>
            <label>Osoite <input name="osoite" maxlength="255" placeholder="Katuosoite, postitoimipaikka" value="<?= htmlspecialchars($luoOsoite) ?>"></label>
            <p class="muted">Osoite näyttää tapahtuman etusivun kartalla.</p>
            <fieldset class="omat-tyyppi">
                <legend>Tapahtuman tyyppi</legend>
                <?php foreach ($sallitutMallit as $arvo => $label): ?>
                    <label>
                        <input type="radio" name="malli" value="<?= htmlspecialchars($arvo) ?>"<?= $arvo === $oletusMalli ? ' checked' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <fieldset class="omat-tyyppi" id="omat-viesti-malli"<?= $oletusMalli !== 'suunnistus' ? ' hidden' : '' ?>>
                <legend>Suunnistuksen malli</legend>
                <label>
                    <input type="radio" name="tyyppi" value="henkilokohtainen" checked>
                    Henkilökohtainen
                </label>
                <?php if ($voiViesti): ?>
                    <label>
                        <input type="radio" name="tyyppi" value="viesti">
                        Viesti
                    </label>
                <?php endif; ?>
            </fieldset>
            <?php if ($voiSuunnistus && !$voiViesti): ?>
                <p class="muted" id="omat-viesti-ohje"<?= $oletusMalli !== 'suunnistus' ? ' hidden' : '' ?>>Viestikilpailu on lisäominaisuus käyttäjätunnuksella. Pääkäyttäjä kytkee sen käyttäjähallinnassa.</p>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit">Luo tapahtuma</button>
            </div>
        </form>
        <script>
        (function () {
            var form = document.getElementById('luo-tapahtuma');
            if (!form) return;
            var viesti = document.getElementById('omat-viesti-malli');
            var ohje = document.getElementById('omat-viesti-ohje');
            function paivita() {
                var val = form.querySelector('input[name="malli"]:checked');
                var koodi = val ? val.value : '';
                var nayta = koodi === 'suunnistus';
                if (viesti) viesti.hidden = !nayta;
                if (ohje) ohje.hidden = !nayta;
            }
            form.querySelectorAll('input[name="malli"]').forEach(function (el) {
                el.addEventListener('change', paivita);
            });
            paivita();
        })();
        </script>
    <?php endif; ?>
</div>

<?php if ($voiSuunnistus && $voiAvaimen): ?>
    <?php if (!$liitanta): ?>
        <div class="public-card" style="margin-bottom:1.25rem">
            <h2>Rastilippu-avain</h2>
            <p class="muted">
                IRMA/Rastilippu-liitäntä on valinnainen lisäominaisuus henkilökohtaisissa tapahtumissa
                (<?= htmlspecialchars(function_exists('tiliEuroTeksti') ? tiliEuroTeksti(tiliRastilippuHintaEuro()) : '5,00 €') ?>/tapahtuma,
                laskutus kuukausittain). Ota se käyttöön oman seuran tiedoissa.
            </p>
            <?php if (!$postiOk): ?>
                <?php tiliLaskutuspostiHuomioHtml('Lisää se seuran asetuksiin, ennen kuin liitännän voi ottaa käyttöön.'); ?>
            <?php endif; ?>
            <p><a href="/admin/oma_seura.php">Oma seura →</a></p>
        </div>
    <?php elseif (!$avainTallennettu || $naytaAvain || isset($_GET['avain'])): ?>
        <div class="public-card" style="margin-bottom:1.25rem">
            <h2>Rastilippu-avain</h2>
            <p class="muted">
                Seuran<?php if (!empty($seura['nimi'])): ?> <?= htmlspecialchars((string) $seura['nimi']) ?><?php endif; ?>
                IRMA/Rastilippu-JWT. Avain on seurakohtainen ja tallennetaan salattuna.
                Yhdistäminen tehdään henkilökohtaisen tapahtuman asetuksista.
                <?php if ($avainTallennettu): ?>
                    Avain on tallennettu<?php if ($avainUuid): ?> (<?= htmlspecialchars($avainUuid) ?>)<?php endif; ?>.
                <?php endif; ?>
            </p>
            <?php if ($seuraId < 1): ?>
                <p class="notice">Anna ensin seuran nimi, jotta avain voidaan tallentaa seuralle.</p>
            <?php else: ?>
                <form method="post" class="form-grid" style="max-width:40rem">
                    <?= csrfKentta() ?>
                    <input type="hidden" name="toiminto" value="tallenna_avain">
                    <label for="rastilippu_token">JWT-avain</label>
                    <textarea id="rastilippu_token" name="rastilippu_token" rows="4" style="width:100%"
                              placeholder="<?= $avainTallennettu ? 'Tallennettu. Liitä uusi avain vaihtaaksesi.' : 'Liitä seuran Rastilippu-JWT' ?>"></textarea>
                    <div class="form-actions">
                        <button type="submit">Tallenna ja testaa</button>
                        <?php if ($avainTallennettu): ?>
                            <button type="submit" name="poista_avain" value="1" class="btn-secondary"
                                    onclick="return confirm('Poistetaanko Rastilippu-avain?');">Poista avain</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="muted" style="margin-bottom:1rem">
            Seuran<?php if (!empty($seura['nimi'])): ?> <?= htmlspecialchars((string) $seura['nimi']) ?><?php endif; ?>
            Rastilippu-avain on tallennettu<?php if ($avainUuid): ?> (<?= htmlspecialchars($avainUuid) ?>)<?php endif; ?>.
            <a href="omat_tapahtumat.php?avain=1">Vaihda avain</a>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php
if (function_exists('kilpailuPoistoVahvistusScriptti')) {
    kilpailuPoistoVahvistusScriptti();
}
pageEnd();
?>
