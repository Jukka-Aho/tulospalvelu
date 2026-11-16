<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tili.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../includes/kisa_luonne.php';
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
if (!$voiSuunnistus && !$voiKilpailut && !(function_exists('onAdmin') && onAdmin())) {
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
if ($voiKilpailut && function_exists('haeKayttajanKilpailut')) {
    foreach (haeKayttajanKilpailut($pdo, 'k.paiva DESC, k.id DESC') as $k) {
        if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($k)) {
            continue;
        }
        if (($k['tila'] ?? '') === 'arkisto') {
            continue;
        }
        $kilpailut[] = $k;
    }
}

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
        if ($toiminto === 'luo_paikallinen') {
            if (!$voiSuunnistus) {
                throw new RuntimeException('Ei oikeutta suunnistustapahtumiin.');
            }
            if ($seuraId < 1) {
                throw new RuntimeException('Seura puuttuu.');
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
                (int) (paakayttajaId() ?? kayttajaId() ?? 0),
                $tyyppi
            );
            asetaValittuKilpailu((int) $kisa['id']);
            header('Location: ' . iltarastiTulospalveluUrl((int) $kisa['id']));
            exit;
        }
        if ($toiminto === 'luo_juoksu') {
            if (!$voiKilpailut) {
                throw new RuntimeException('Ei oikeutta juoksukilpailuihin.');
            }
            if ($seuraId < 1) {
                throw new RuntimeException('Seura puuttuu.');
            }
            $nimi = trim((string) ($_POST['nimi'] ?? ''));
            if ($nimi === '') {
                throw new RuntimeException('Anna kilpailun nimi.');
            }
            $pvm = function_exists('iltarastiPvmKannasta')
                ? iltarastiPvmKannasta((string) ($_POST['paiva'] ?? ''))
                : trim((string) ($_POST['paiva'] ?? ''));
            if ($pvm === null || $pvm === '') {
                throw new RuntimeException('Anna kilpailun päivämäärä.');
            }
            $paikka = trim((string) ($_POST['paikka'] ?? ''));
            $token = function_exists('generoiApiToken') ? generoiApiToken() : bin2hex(random_bytes(16));
            $omistaja = (int) (paakayttajaId() ?? kayttajaId() ?? 0);
            $pdo->prepare('
                INSERT INTO kilpailut
                    (nimi, paiva, paikka, tila, kisa_tyyppi, api_token, pirila_synkka, paakayttaja_id, seura_id,
                     ilmoittautuminen_auki, testikisa, jaettu_jono, luonne, tyyppi)
                VALUES (?, ?, ?, ?, \'normaali\', ?, 0, ?, ?, 0, 0, 0, \'juoksu\', \'henkilokohtainen\')
            ')->execute([
                mb_substr($nimi, 0, 255),
                $pvm,
                $paikka !== '' ? mb_substr($paikka, 0, 255) : null,
                function_exists('iltarastiTilaPaivasta') ? iltarastiTilaPaivasta($pvm) : 'tulossa',
                $token,
                $omistaja > 0 ? $omistaja : null,
                $seuraId,
            ]);
            $id = (int) $pdo->lastInsertId();
            asetaValittuKilpailu($id);
            header('Location: /admin/index.php');
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
        if ($voiSuunnistus && $seuraId > 0) {
            $suunnistukset = iltarastiHaeSeuran($pdo, $seuraId);
        }
    }
}

pageStart('Omat tapahtumat', [
    'admin' => true,
    'active' => 'omat_tapahtumat',
    'pdo' => $pdo,
    'palvelu' => $voiSuunnistus && !$voiKilpailut ? 'iltarastit' : 'tulokset',
    'extraHead' => '<style>
.omat-tyyppi{display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin:.35rem 0 .6rem}
.omat-tyyppi label{display:flex;align-items:center;gap:.4rem;font-weight:600}
.omat-lista{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.55rem}
.omat-lista a,.omat-lista button.omat-rivi{display:flex;flex-wrap:wrap;align-items:baseline;gap:.35rem .7rem;width:100%;text-align:left;padding:.7rem .85rem;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text);font:inherit;cursor:pointer;text-decoration:none}
.omat-lista a:hover,.omat-lista button.omat-rivi:hover{border-color:var(--primary);color:var(--primary)}
.omat-lista .muted{margin:0}
</style>',
]);
?>

<h1>Omat tapahtumat</h1>
<p class="muted">
    Suunnistustapahtumat ja juoksukilpailut oman seuran oikeuksien mukaan.
    Rastilippu-liitäntä valitaan henkilökohtaisen suunnistustapahtuman asetuksista.
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

<?php if ($voiSuunnistus): ?>
<div class="public-card" style="margin-bottom:1.25rem">
    <h2>Suunnistustapahtumat</h2>
    <?php if ($suunnistukset === []): ?>
        <p class="muted">Ei suunnistustapahtumia vielä.</p>
    <?php else: ?>
        <ul class="omat-lista">
            <?php foreach ($suunnistukset as $k): ?>
                <?php
                $onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($k);
                $pvmTxt = !empty($k['paiva']) && function_exists('formatPaiva') ? formatPaiva($k['paiva']) : '';
                ?>
                <li>
                    <form method="post">
                        <?= csrfKentta() ?>
                        <input type="hidden" name="toiminto" value="valitse_tapahtuma">
                        <input type="hidden" name="kilpailu_id" value="<?= (int) $k['id'] ?>">
                        <button type="submit" class="omat-rivi">
                            <strong><?= htmlspecialchars((string) $k['nimi']) ?></strong>
                            <?php if ($pvmTxt !== ''): ?><span class="muted"><?= htmlspecialchars($pvmTxt) ?></span><?php endif; ?>
                            <span class="muted"><?= $onViesti ? 'Viesti' : 'Henkilökohtainen' ?></span>
                            <span class="muted"><?= htmlspecialchars(kilpailuTilaLabel($k['tila'] ?? '')) ?></span>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($seuraId > 0): ?>
        <h3 style="margin-top:1.2rem">Luo suunnistustapahtuma</h3>
        <p class="muted">Valitse henkilökohtainen tai viesti. Rastilippu-liitäntä on toistaiseksi vain henkilökohtaisissa, ja se kytketään tapahtuman asetuksista.</p>
        <form method="post" class="form-grid">
            <?= csrfKentta() ?>
            <input type="hidden" name="toiminto" value="luo_paikallinen">
            <label>Nimi <input name="nimi" required maxlength="255" placeholder="Suunnistustapahtuma"></label>
            <label>Päivä <input type="date" name="paiva" required value="<?= htmlspecialchars(iltarastiTanaan()) ?>"></label>
            <label>Paikka <input name="paikka" maxlength="255"></label>
            <fieldset class="omat-tyyppi">
                <legend>Tapahtuman malli</legend>
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
            <?php if (!$voiViesti): ?>
                <p class="muted">Viestikilpailu on lisäominaisuus käyttäjätunnuksella. Pääkäyttäjä kytkee sen käyttäjähallinnassa.</p>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit">Luo tapahtuma</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($voiKilpailut): ?>
<div class="public-card" style="margin-bottom:1.25rem">
    <h2>Juoksukilpailut</h2>
    <?php if ($kilpailut === []): ?>
        <p class="muted">Ei juoksukilpailuja vielä.</p>
    <?php else: ?>
        <ul class="omat-lista">
            <?php foreach ($kilpailut as $k): ?>
                <?php
                $pvmTxt = !empty($k['paiva']) && function_exists('formatPaiva') ? formatPaiva($k['paiva']) : '';
                $luonne = function_exists('kisaLuonneLabel') ? kisaLuonneLabel($k['luonne'] ?? null) : '';
                ?>
                <li>
                    <form method="post">
                        <?= csrfKentta() ?>
                        <input type="hidden" name="toiminto" value="valitse_kilpailu">
                        <input type="hidden" name="kilpailu_id" value="<?= (int) $k['id'] ?>">
                        <button type="submit" class="omat-rivi">
                            <strong><?= htmlspecialchars((string) $k['nimi']) ?></strong>
                            <?php if ($pvmTxt !== ''): ?><span class="muted"><?= htmlspecialchars($pvmTxt) ?></span><?php endif; ?>
                            <?php if ($luonne !== ''): ?><span class="muted"><?= htmlspecialchars($luonne) ?></span><?php endif; ?>
                            <span class="muted"><?= htmlspecialchars(kilpailuTilaLabel($k['tila'] ?? '')) ?></span>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($seuraId > 0): ?>
        <h3 style="margin-top:1.2rem">Luo juoksukilpailu</h3>
        <form method="post" class="form-grid">
            <?= csrfKentta() ?>
            <input type="hidden" name="toiminto" value="luo_juoksu">
            <label>Nimi <input name="nimi" required maxlength="255" placeholder="Juoksukilpailu"></label>
            <label>Päivä <input type="date" name="paiva" required value="<?= htmlspecialchars(iltarastiTanaan()) ?>"></label>
            <label>Paikka <input name="paikka" maxlength="255"></label>
            <div class="form-actions">
                <button type="submit">Luo kilpailu</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($voiSuunnistus && $voiAvaimen): ?>
    <?php if (!$liitanta): ?>
        <div class="public-card" style="margin-bottom:1.25rem">
            <h2>Rastilippu-avain</h2>
            <p class="muted">
                IRMA/Rastilippu-liitäntä on valinnainen lisäominaisuus henkilökohtaisissa tapahtumissa
                (<?= htmlspecialchars(function_exists('tiliEuroTeksti') ? tiliEuroTeksti(tiliRastilippuHintaEuro()) : '5,00 €') ?>/tapahtuma,
                laskutus kuukausittain). Ota se käyttöön oman seuran tiedoissa.
            </p>
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

<?php pageEnd(); ?>
