<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/radat.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../db.php';
if (is_readable(__DIR__ . '/../includes/tili.php')) {
    require_once __DIR__ . '/../includes/tili.php';
}

if (function_exists('tiliVarmistaTaulut')) {
    tiliVarmistaTaulut($pdo);
}
if (function_exists('tiliLataaKayttajaSessioon')) {
    tiliLataaKayttajaSessioon($pdo);
}
vaadiKisahallinta();

$kilpailu = vaadiValittuKilpailu($pdo);
vaadiKilpailunHallinta($kilpailu);
if (!kisaOnIltarasti($kilpailu)) {
    header('Location: index.php');
    exit;
}
iltarastiOhjaaJulkiseen('/public/iltarasti_asetukset.php');
iltarastiVarmista($pdo);
if (is_readable(__DIR__ . '/../includes/viesti_sarjat.php')) {
    require_once __DIR__ . '/../includes/viesti_sarjat.php';
}
$kilpailu_id = (int) $kilpailu['id'];
$seuraId = (int) ($kilpailu['seura_id'] ?? 0);
$voiAvaimen = iltarastiVoiAvaimen();
$voiYhdistaaOikeus = iltarastiVoiYhdistaa();
$liitanta = $seuraId > 0 && function_exists('tiliSeuraSalliiRastilipun') && tiliSeuraSalliiRastilipun($pdo, $seuraId);
$onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
$voiYhdistaa = $voiYhdistaaOikeus && $liitanta && !$onViesti;
$viesti = trim((string) ($_GET['viesti'] ?? ''));
$virhe = trim((string) ($_GET['virhe'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
        die('Virheellinen pyyntö');
    }
    $toiminto = (string) ($_POST['toiminto'] ?? 'tallenna');
    try {
        if ($toiminto === 'paivita_tapahtumat') {
            if (!$voiYhdistaa) {
                throw new RuntimeException('Ei oikeutta päivittää Rastilippu-listaa.');
            }
            if ($seuraId < 1 || rastilippuHaeAvain($pdo, $seuraId) === null) {
                throw new RuntimeException('Rastilippu-avain puuttuu.');
            }
            rastilippuTapahtumatSeuralle($pdo, $seuraId, true);
            header('Location: iltarasti_asetukset.php?viesti=' . urlencode('Rastilippu-tapahtumat päivitetty.'));
            exit;
        }
        if ($toiminto === 'laheta_rastilippu') {
            if (!function_exists('rastilippuKilpailuYhdistetty') || !rastilippuKilpailuYhdistetty($kilpailu)) {
                throw new RuntimeException('Tapahtumaa ei ole yhdistetty Rastilippuun.');
            }
            $tulos = rastilippuLahetaTulokset($pdo, $kilpailu);
            $ok = !empty($tulos['ok']);
            $q = $ok ? 'viesti' : 'virhe';
            header('Location: iltarasti_asetukset.php?' . $q . '=' . urlencode((string) ($tulos['message'] ?? 'Lähetys valmis.')));
            exit;
        }
        iltarastiTallennaAsetukset(
            $pdo,
            $kilpailu_id,
            (string) ($_POST['nimi'] ?? ''),
            (string) ($_POST['paikka'] ?? ''),
            (string) ($_POST['paiva'] ?? '')
        );
        if ($onViesti && function_exists('viestiSarjatTallenna')) {
            viestiSarjatTallenna(
                $pdo,
                $kilpailu_id,
                $_POST['sarja_id'] ?? [],
                $_POST['sarja_nimi'] ?? [],
                $_POST['sarja_osuuksia'] ?? [],
                $_POST['sarja_poista'] ?? []
            );
        }
        if ($voiYhdistaa && array_key_exists('rastilippu_event_id', $_POST)) {
            $eventId = (int) ($_POST['rastilippu_event_id'] ?? 0);
            $tapahtuma = null;
            if ($eventId > 0) {
                $tapahtuma = iltarastiHaeRastilippuTapahtuma($pdo, $seuraId, $eventId);
                if (!$tapahtuma) {
                    $nyky = (int) ($kilpailu['rastilippu_event_id'] ?? 0);
                    if ($nyky === $eventId) {
                        $tapahtuma = [
                            'id' => $eventId,
                            'uuid' => (string) ($kilpailu['rastilippu_event_uuid'] ?? ''),
                            'series_id' => $kilpailu['rastilippu_series_id'] ?? null,
                        ];
                    } else {
                        throw new RuntimeException('Valitse Rastilippu-tapahtuma listasta.');
                    }
                }
            }
            iltarastiYhdistaRastilippuun($pdo, $kilpailu_id, $seuraId, $tapahtuma);
            if ($eventId > 0 && function_exists('rastilippuPaivitaIlmoittautumiset')) {
                $yhdistetty = haeKilpailu($pdo, $kilpailu_id);
                if (is_array($yhdistetty)) {
                    rastilippuPaivitaIlmoittautumiset($pdo, $yhdistetty, true);
                }
            }
        }
        header('Location: iltarasti_asetukset.php?viesti=' . urlencode('Asetukset tallennettu.'));
        exit;
    } catch (Throwable $e) {
        $virhe = $e->getMessage();
    }
}

if (function_exists('radatKorjaaIltarastiPituusKm')) {
    radatKorjaaIltarastiPituusKm($pdo, $kilpailu_id);
}
$radat = function_exists('radatHaeKilpailuun') ? radatHaeKilpailuun($pdo, $kilpailu_id) : [];
$viestiSarjat = ($onViesti && function_exists('viestiSarjatHae')) ? viestiSarjatHae($pdo, $kilpailu_id) : [];
if (is_readable(__DIR__ . '/../includes/emit_lukija.php')) {
    require_once __DIR__ . '/../includes/emit_lukija.php';
}
$pvmLomake = iltarastiPvmKannasta($kilpailu['paiva'] ?? null)
    ?: iltarastiPvmKannasta($kilpailu['alku_at'] ?? null)
    ?: '';
$avainTallennettu = $seuraId > 0 && rastilippuHaeAvain($pdo, $seuraId) !== null;
$tapahtumat = ($voiYhdistaa && $avainTallennettu) ? (rastilippuHaeListaCache($pdo, $seuraId) ?: []) : [];
$nykyinenEid = (int) ($kilpailu['rastilippu_event_id'] ?? 0);
$kaytossa = [];
if ($seuraId > 0) {
    foreach (iltarastiHaeSeuran($pdo, $seuraId) as $k) {
        $eid = (int) ($k['rastilippu_event_id'] ?? 0);
        if ($eid > 0 && (int) ($k['id'] ?? 0) !== $kilpailu_id) {
            $kaytossa[$eid] = true;
        }
    }
}
$nykyinenListassa = false;
foreach ($tapahtumat as $t) {
    if ((int) ($t['id'] ?? 0) === $nykyinenEid) {
        $nykyinenListassa = true;
        break;
    }
}

pageStart('Asetukset', iltarastiKuoriOpts($pdo, $kilpailu, 'asetukset'));
?>

<h1>Tapahtuman asetukset</h1>
<?php if ($viesti !== ''): ?><p class="notice"><?= htmlspecialchars($viesti) ?></p><?php endif; ?>
<?php if ($virhe !== ''): ?><p class="error"><?= htmlspecialchars($virhe) ?></p><?php endif; ?>

<form method="post" class="form-grid" style="max-width:32rem">
    <?= csrfKentta() ?>
    <input type="hidden" name="toiminto" value="tallenna">
    <label>Nimi <input name="nimi" required maxlength="255" value="<?= htmlspecialchars((string) ($kilpailu['nimi'] ?? '')) ?>"></label>
    <label>Paikka <input name="paikka" maxlength="255" value="<?= htmlspecialchars((string) ($kilpailu['paikka'] ?? '')) ?>"></label>
    <label>Päivä <input type="date" name="paiva" required value="<?= htmlspecialchars($pvmLomake) ?>"></label>
    <?php if (!empty($onViesti)): ?>
        <p class="muted">Rastilippu-liitäntä on toistaiseksi vain henkilökohtaisissa tapahtumissa.</p>
        <h2 style="margin:1.1rem 0 0.4rem">Sarjat</h2>
        <p class="muted">Anna sarjan nimi ja montako osuutta siinä juostaan.</p>
        <div class="table-scroll">
            <table class="data-table" id="viesti-sarjat">
                <thead>
                    <tr>
                        <th>Sarja</th>
                        <th>Osuuksia</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sarjaRivit = $viestiSarjat;
                    $sarjaRivit[] = ['id' => 0, 'nimi' => '', 'osuuksia' => 3];
                    foreach ($sarjaRivit as $sr):
                        $sid = (int) ($sr['id'] ?? 0);
                        $osu = (int) ($sr['osuuksia'] ?? 0);
                        if ($osu < 1) {
                            $osu = 3;
                        }
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="sarja_id[]" value="<?= $sid ?>">
                                <input type="hidden" name="sarja_poista[]" value="0" class="viesti-sarja-poista">
                                <input name="sarja_nimi[]" maxlength="80" placeholder="esim. H21"
                                       value="<?= htmlspecialchars((string) ($sr['nimi'] ?? '')) ?>">
                            </td>
                            <td>
                                <input type="number" name="sarja_osuuksia[]" min="1" max="30" value="<?= $osu ?>" style="width:4.5rem">
                            </td>
                            <td>
                                <?php if ($sid > 0): ?>
                                    <button type="button" class="btn-small btn-secondary viesti-sarja-poista-nappi">Poista</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-actions">
            <button type="button" class="btn-secondary" id="viesti-sarja-lisaa">Lisää sarja</button>
        </p>
        <script>
        (function () {
            var taulu = document.getElementById('viesti-sarjat');
            if (!taulu) return;
            var tbody = taulu.querySelector('tbody');
            var lisaa = document.getElementById('viesti-sarja-lisaa');
            function riviPohja() {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><input type="hidden" name="sarja_id[]" value="0">'
                    + '<input type="hidden" name="sarja_poista[]" value="0" class="viesti-sarja-poista">'
                    + '<input name="sarja_nimi[]" maxlength="80" placeholder="esim. H21"></td>'
                    + '<td><input type="number" name="sarja_osuuksia[]" min="1" max="30" value="3" style="width:4.5rem"></td>'
                    + '<td><button type="button" class="btn-small btn-secondary viesti-sarja-poista-nappi">Poista</button></td>';
                return tr;
            }
            if (lisaa) {
                lisaa.addEventListener('click', function () {
                    tbody.appendChild(riviPohja());
                });
            }
            tbody.addEventListener('click', function (ev) {
                var nappi = ev.target.closest('.viesti-sarja-poista-nappi');
                if (!nappi) return;
                var tr = nappi.closest('tr');
                var id = tr.querySelector('input[name="sarja_id[]"]');
                var poista = tr.querySelector('.viesti-sarja-poista');
                if (id && id.value !== '0' && poista) {
                    if (!confirm('Poistetaanko sarja?')) return;
                    poista.value = '1';
                    tr.style.display = 'none';
                    var nimi = tr.querySelector('input[name="sarja_nimi[]"]');
                    if (nimi && !nimi.value) nimi.value = '-';
                } else {
                    tr.remove();
                }
            });
        })();
        </script>
    <?php elseif ($voiYhdistaa && $avainTallennettu): ?>
        <label for="rastilippu_event_id">Rastilippu-tapahtuma</label>
        <select id="rastilippu_event_id" name="rastilippu_event_id">
            <option value="">Ei yhdistetty</option>
            <?php if ($nykyinenEid > 0 && !$nykyinenListassa): ?>
                <option value="<?= $nykyinenEid ?>" selected>
                    Yhdistetty (#<?= $nykyinenEid ?>)
                </option>
            <?php endif; ?>
            <?php foreach ($tapahtumat as $t): ?>
                <?php
                $eid = (int) ($t['id'] ?? 0);
                if ($eid < 1 || isset($kaytossa[$eid])) {
                    continue;
                }
                $pvm = !empty($t['paiva']) && function_exists('formatPaiva') ? formatPaiva($t['paiva']) : '';
                $omatoimi = function_exists('rastilippuTapahtumaOnOmatoimi') && rastilippuTapahtumaOnOmatoimi($t);
                $lisa = $omatoimi ? ' · omatoimirastit' : '';
                ?>
                <option value="<?= $eid ?>"<?= $nykyinenEid === $eid ? ' selected' : '' ?>>
                    <?= htmlspecialchars(trim($pvm . ' · ' . (string) ($t['nimi'] ?? '') . (!empty($t['paikka']) ? ' · ' . $t['paikka'] : '') . $lisa)) ?>
                </option>
            <?php endforeach; ?>
        </select>
            <p class="muted">Voit yhdistää paikallisen tapahtuman Rastilippuun myöhemmin (<?= htmlspecialchars(function_exists('tiliEuroTeksti') ? tiliEuroTeksti(tiliRastilippuHintaEuro()) : '5,00 €') ?>/yhdistetty tapahtuma, laskutus vuosittain). Nimi ja päivä säilyvät. Radat, ajat ja väliajat päivitetään Rastilippuun automaattisesti taustalla.</p>
        <?php
        $rlTila = function_exists('rastilippuLahetysTilaTeksti') ? rastilippuLahetysTilaTeksti($kilpailu) : '';
        if ($rlTila !== ''):
            ?>
            <p class="muted"><?= htmlspecialchars($rlTila) ?></p>
        <?php endif; ?>
        <?php
        $rlIlmo = trim((string) ($kilpailu['rastilippu_ilmo_viesti'] ?? ''));
        if ($rlIlmo !== ''):
            ?>
            <p class="muted"><?= htmlspecialchars($rlIlmo) ?></p>
        <?php endif; ?>
    <?php elseif ($voiYhdistaaOikeus && $voiAvaimen && !$liitanta): ?>
        <p class="muted">Ota IRMA/Rastilippu-liitäntä käyttöön <a href="oma_seura.php">oman seuran tiedoissa</a> (<?= htmlspecialchars(function_exists('tiliEuroTeksti') ? tiliEuroTeksti(tiliRastilippuHintaEuro()) : '5,00 €') ?>/tapahtuma, laskutus vuosittain).</p>
    <?php elseif ($voiYhdistaa && $voiAvaimen): ?>
        <p class="muted">Lisää Rastilippu-avain hubissa, jos haluat yhdistää tämän illan kuntotapahtumaan.</p>
    <?php elseif ($voiYhdistaa): ?>
        <p class="muted">Seuran pääkäyttäjä lisää Rastilippu-avaimen. Sen jälkeen voit yhdistää illan listasta.</p>
    <?php elseif ($nykyinenEid > 0): ?>
        <p class="muted">Yhdistetty Rastilippu-tapahtumaan #<?= $nykyinenEid ?>. Ajat lähtevät automaattisesti taustalla.</p>
        <?php
        $rlTila = function_exists('rastilippuLahetysTilaTeksti') ? rastilippuLahetysTilaTeksti($kilpailu) : '';
        if ($rlTila !== ''):
            ?>
            <p class="muted"><?= htmlspecialchars($rlTila) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <p class="form-actions">
        <a class="btn iltarasti-siirry-tp" href="<?= htmlspecialchars(iltarastiTulospalveluUrl((int) $kilpailu['id']) . '&moodi=lahto') ?>">Siirry tulospalveluun</a>
    </p>
    <div class="form-actions">
        <button type="submit">Tallenna</button>
    </div>
</form>
<?php if ($voiYhdistaa && $avainTallennettu): ?>
    <form method="post" class="form-actions iltarasti-paivita-lista">
        <?= csrfKentta() ?>
        <input type="hidden" name="toiminto" value="paivita_tapahtumat">
        <button type="submit" class="btn-secondary">Päivitä lista Rastilipusta</button>
    </form>
<?php endif; ?>
<?php if (function_exists('rastilippuKilpailuYhdistetty') && rastilippuKilpailuYhdistetty($kilpailu)): ?>
    <form method="post" class="form-actions">
        <?= csrfKentta() ?>
        <input type="hidden" name="toiminto" value="laheta_rastilippu">
        <button type="submit">Lähetä tulokset Rastilippuun</button>
    </form>
<?php endif; ?>

<h2>Radat</h2>
<p class="muted">Ratoja voi muokata ilman nettiä. Leimantarkastus käyttää puhelimessa olevia ratoja.</p>
<?php if ($radat === []): ?>
    <p class="muted">Ei ratoja vielä.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr><th>Rata</th><th>Matka</th><th>Rastit</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($radat as $rata):
                    $koodit = [];
                    foreach ($rata['rastit'] ?? [] as $rr) {
                        $k = trim((string) ($rr['rasti_koodi'] ?? ''));
                        if ($k === '') {
                            continue;
                        }
                        $koodit[] = !empty($rr['sammunut']) ? $k . '*' : $k;
                    }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($rata['tunnus'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(radatMuotoileKm($rata['pituus_m'] ?? null)) ?></td>
                        <td><?= $koodit !== [] ? htmlspecialchars(implode(' ', $koodit)) : '—' ?></td>
                        <td>
                            <a class="btn-small btn-secondary" href="radat.php?muokkaa=<?= (int) $rata['id'] ?>">Muokkaa</a>
                            <form method="post" action="radat.php" class="inline-form" style="display:inline"
                                  onsubmit="return confirm('Poistetaanko rata <?= htmlspecialchars((string) ($rata['tunnus'] ?? ''), ENT_QUOTES) ?>?');">
                                <?= csrfKentta() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $rata['id'] ?>">
                                <button type="submit" class="btn-small btn-secondary">Poista</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">* = sammunut (emitin patteri vähissä, rastia ei vaadita leimoissa).</p>
<?php endif; ?>
<p class="form-actions"><a class="btn-secondary" href="radat.php">Lisää rata</a></p>
<?php
$emitRadat = function_exists('emitLukijaRataLista') ? emitLukijaRataLista($pdo, $kilpailu_id) : [];
foreach ($emitRadat as &$er) {
    foreach ($radat as $rr) {
        if (strcasecmp((string) ($rr['tunnus'] ?? ''), (string) ($er['nimi'] ?? '')) === 0) {
            $er['rataId'] = (int) ($rr['id'] ?? 0);
            $er['pituus_km'] = function_exists('radatPituusKmLomakkeeseen')
                ? radatPituusKmLomakkeeseen($rr['pituus_m'] ?? null)
                : '';
            break;
        }
    }
}
unset($er);
?>
<script>
window.JAH_RADAT = {
    kilpailuId: <?= (int) $kilpailu_id ?>,
    lista: <?= json_encode($emitRadat, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/js/jah-radat-offline.js?v=20261112rata4"></script>
<script>
if (window.jahRataOffline) {
    window.jahRataOffline.kaynnista(window.JAH_RADAT);
}
</script>

<?php pageEnd(); ?>
