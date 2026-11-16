<?php

require_once __DIR__ . '/../includes/auth.php';
vaadiKirjautuminen();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tili.php';
if (is_readable(__DIR__ . '/../includes/seura_logo.php')) {
    require_once __DIR__ . '/../includes/seura_logo.php';
}

function seuratHallintaOhjaa(int $id = 0, string $viesti = '', string $virhe = ''): void
{
    $q = [];
    if ($id > 0) {
        $q['id'] = $id;
    }
    if ($viesti !== '') {
        $q['viesti'] = $viesti;
    }
    if ($virhe !== '') {
        $q['virhe'] = $virhe;
    }
    header('Location: seurat.php' . ($q !== [] ? '?' . http_build_query($q) : ''));
    exit;
}

$hasLayout = is_readable(__DIR__ . '/../includes/layout.php')
    && is_readable(__DIR__ . '/../includes/helpers.php');
if ($hasLayout) {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/layout.php';
}

tiliVarmistaTaulut($pdo);
tiliLataaKayttajaSessioon($pdo);

if (!onAdmin()) {
    http_response_code(403);
    die('Ei käyttöoikeutta');
}

$viesti = trim((string) ($_GET['viesti'] ?? ''));
$virhe = trim((string) ($_GET['virhe'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (function_exists('tarkistaCsrf') && !tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Virheellinen pyyntö');
        }
        $toiminto = $_POST['toiminto'] ?? '';
        if ($toiminto === 'tallenna_logo') {
            $seuraId = (int) ($_POST['seura_id'] ?? 0);
            seuraLogoTallenna($pdo, $seuraId, $_FILES['logo'] ?? []);
            seuratHallintaOhjaa($seuraId, 'Logo tallennettu.');
        }
        if ($toiminto === 'poista_logo') {
            $seuraId = (int) ($_POST['seura_id'] ?? 0);
            seuraLogoPoista($pdo, $seuraId);
            seuratHallintaOhjaa($seuraId, 'Logo poistettu.');
        }
        if ($toiminto === 'tallenna_palvelut') {
            $seuraId = (int) ($_POST['seura_id'] ?? 0);
            $pakettiId = (int) ($_POST['paketti_id'] ?? 0);
            if ($pakettiId > 0) {
                $chk = $pdo->prepare('SELECT id FROM paketit WHERE id = ? AND aktiivinen = 1');
                $chk->execute([$pakettiId]);
                if (!$chk->fetchColumn()) {
                    throw new RuntimeException('Pakettia ei löytynyt');
                }
                $pdo->prepare('UPDATE seurat SET paketti_id = ? WHERE id = ?')->execute([$pakettiId, $seuraId]);
            }
            tiliTallennaSeuraPalvelut($pdo, $seuraId, [
                'gps' => !empty($_POST['palvelu_gps']),
                'kilpailut' => !empty($_POST['palvelu_kilpailut']),
                'iltarastit' => !empty($_POST['palvelu_iltarastit']),
            ]);
            tiliTallennaRastilippuLiitanta(
                $pdo,
                $seuraId,
                !empty($_POST['rastilippu_liitanta']),
                (string) ($_POST['laskutus_email'] ?? ''),
                false
            );
            tiliTallennaSeuraViesti(
                $pdo,
                $seuraId,
                !empty($_POST['palvelu_iltarastit']) && !empty($_POST['viesti_kilpailu'])
            );
            tiliTallennaSeuraEiLaskutusta(
                $pdo,
                $seuraId,
                !empty($_POST['ei_laskutusta'])
            );
            seuratHallintaOhjaa($seuraId, 'Seuran palvelut tallennettu.');
        }
        if ($toiminto === 'tila') {
            $seuraId = (int) ($_POST['seura_id'] ?? 0);
            $tila = ($_POST['tila'] ?? '') === 'suljettu' ? 'suljettu' : 'aktiivinen';
            $pdo->prepare('UPDATE seurat SET tila = ? WHERE id = ?')->execute([$tila, $seuraId]);
            seuratHallintaOhjaa($seuraId, 'Seuran tila päivitetty.');
        }
        if ($toiminto === 'poista_seura') {
            $tulos = tiliPoistaSeuraJaKayttajat(
                $pdo,
                (int) ($_POST['seura_id'] ?? 0),
                (int) (kayttajaId() ?? 0)
            );
            $viesti = 'Seura «' . $tulos['nimi'] . '» poistettu'
                . ($tulos['kayttajia'] > 0 ? (' ja ' . $tulos['kayttajia'] . ' käyttäjää poistettu.') : '.');
            seuratHallintaOhjaa(0, $viesti);
        }
    } catch (Throwable $e) {
        seuratHallintaOhjaa((int) ($_POST['seura_id'] ?? 0), '', $e->getMessage());
    }
}

$laskutusVuosi = (int) ($_GET['vuosi'] ?? tiliLaskutusVuosi());
$nytVuosi = tiliLaskutusVuosi();
if ($laskutusVuosi < 2020 || $laskutusVuosi > $nytVuosi + 1) {
    $laskutusVuosi = $nytVuosi;
}
$laskut = tiliRastilippuLaskutusKaikki($pdo, $laskutusVuosi);
$laskuSumma = 0.0;
$laskuTapahtumat = 0;
foreach ($laskut as $rivi) {
    $laskuSumma += (float) $rivi['summa_euro'];
    $laskuTapahtumat += (int) $rivi['yhdistettyja'];
}

$paketit = $pdo->query('SELECT * FROM paketit WHERE aktiivinen = 1 ORDER BY jarjestys, id')->fetchAll(PDO::FETCH_ASSOC);
$seurat = $pdo->query("
    SELECT s.*, p.koodi AS paketti_koodi, p.nimi AS paketti_nimi,
           p.max_laitteet, p.max_kesto_tuntia, p.max_tapahtumat_kk,
           (SELECT COUNT(*) FROM seura_jasenet j WHERE j.seura_id = s.id AND j.tila = 'aktiivinen') AS jasen_lkm
    FROM seurat s
    INNER JOIN paketit p ON p.id = s.paketti_id
    ORDER BY s.nimi ASC, s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$valittuId = (int) ($_GET['id'] ?? 0);
$valittuSeura = null;
if ($valittuId > 0) {
    foreach ($seurat as $s) {
        if ((int) $s['id'] === $valittuId) {
            $valittuSeura = $s;
            break;
        }
    }
    if (!$valittuSeura && $virhe === '') {
        $virhe = 'Seuraa ei löytynyt.';
        $valittuId = 0;
    }
}

$seuraTyylit = function_exists('seuraLogoTyylit') ? seuraLogoTyylit() : '';
if ($hasLayout) {
    pageStart('Seurat', ['admin' => true, 'active' => 'seurat', 'pdo' => $pdo, 'extraHead' => $seuraTyylit]);
} else {
    echo '<!DOCTYPE html><html lang="fi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Seurat</title><link rel="stylesheet" href="../assets/css/style.css">' . $seuraTyylit . '</head><body><div class="container">';
    echo '<p><a href="index.php">← Hallinta</a></p>';
}
?>

<h1>Seurojen hallinta</h1>
<?php if ($valittuSeura): ?>
    <p><a href="seurat.php">&larr; Kaikki seurat</a></p>
<?php else: ?>
<p class="muted">
    Valitse seura nähdäksesi sen asetukset. Logo näkyy, jos seura on tallentanut sen.
</p>
<?php endif; ?>

<?php if ($viesti): ?><p class="notice" style="color:#c49a22"><?= htmlspecialchars($viesti) ?></p><?php endif; ?>
<?php if ($virhe): ?><p class="error" style="color:#c62828"><?= htmlspecialchars($virhe) ?></p><?php endif; ?>

<?php if ($valittuSeura): ?>
    <?php
    $s = $valittuSeura;
    $sid = (int) $s['id'];
    $gpsKk = tiliSeuranTapahtumatKuussa($pdo, $sid);
    $kisat = tiliSeuranKilpailuLkm($pdo, $sid);
    $rl = tiliRastilippuLaskutus($pdo, $sid, $laskutusVuosi);
    $maxKk = (int) ($s['max_tapahtumat_kk'] ?? 0);
    $csrf = function_exists('csrfKentta') ? csrfKentta() : '';
    ?>
    <div class="public-card">
        <div class="seura-asetus-paa">
            <?php if (function_exists('seuraLogoHtml')) {
                seuraLogoHtml($s, 'seura-logo--iso');
            } ?>
            <div>
                <h2><?= htmlspecialchars((string) $s['nimi']) ?></h2>
                <p class="muted" style="margin:0.25rem 0 0">
                    <?= htmlspecialchars((string) $s['slug']) ?>
                    · <?= (int) $s['jasen_lkm'] ?> jäsentä
                    · tila <?= htmlspecialchars((string) $s['tila']) ?>
                    · #<?= $sid ?>
                </p>
            </div>
        </div>
        <?php if (function_exists('seuraLogoLomakeHtml')) {
            seuraLogoLomakeHtml($s, $csrf);
        } ?>
            <form method="post">
                <?= $csrf ?>
                <input type="hidden" name="toiminto" value="tallenna_palvelut">
                <input type="hidden" name="seura_id" value="<?= $sid ?>">
                <fieldset style="border:0;padding:0;margin:0 0 1rem">
                    <legend style="font-weight:600;margin-bottom:0.4rem">Palvelut</legend>
                    <p style="margin:0.35rem 0">
                        <label>
                            <input type="checkbox" name="palvelu_gps" value="1"<?= tiliSeuraPalveluPaalla($s, 'gps') ? ' checked' : '' ?>>
                            GPS
                        </label>
                        <span class="muted">
                            · <?= $gpsKk ?> / <?= $maxKk ?> tapahtumaa tässä kuussa
                            · max <?= (int) $s['max_laitteet'] ?> laitetta
                            · max <?= (int) $s['max_kesto_tuntia'] ?> h
                        </span>
                    </p>
                    <p style="margin:0.35rem 0">
                        <label>
                            <input type="checkbox" name="palvelu_kilpailut" value="1"<?= tiliSeuraPalveluPaalla($s, 'kilpailut') ? ' checked' : '' ?>>
                            Kilpailut
                        </label>
                        <span class="muted">· <?= $kisat ?> kilpailua (ei iltarasteja)</span>
                    </p>
                    <p style="margin:0.35rem 0">
                        <label>
                            <input type="checkbox" name="palvelu_iltarastit" value="1"<?= tiliSeuraPalveluPaalla($s, 'iltarastit') ? ' checked' : '' ?>>
                            Iltarastit
                        </label>
                    </p>
                    <p style="margin:0.35rem 0">
                        <label>
                            <input type="checkbox" name="viesti_kilpailu" value="1"<?= tiliSeuraViestiPaalla($s) ? ' checked' : '' ?>>
                            Viestikilpailu
                        </label>
                        <span class="muted">· suunnistustapahtumassa henkilökohtainen tai viesti</span>
                    </p>
                    <p style="margin:0.35rem 0">
                        <label>
                            <input type="checkbox" name="rastilippu_liitanta" value="1"<?= tiliSeuraRastilippuPaalla($s) ? ' checked' : '' ?>>
                            IRMA/Rastilippu-liitäntä
                        </label>
                        <span class="muted">· <?= htmlspecialchars($rl['teksti']) ?></span>
                    </p>
                    <p style="margin:0.35rem 0">
                        <label>
                            <input type="checkbox" name="ei_laskutusta" value="1"<?= tiliSeuraEiLaskutusta($s) ? ' checked' : '' ?>>
                            Ei laskutusta
                        </label>
                        <span class="muted">· Iltarastien Rastilippu-liitännästä ei kerry laskua tälle seuralle</span>
                    </p>
                    <p style="margin:0.35rem 0">
                        <label>Laskutussähköposti
                            <input type="email" name="laskutus_email" value="<?= htmlspecialchars((string) ($s['laskutus_email'] ?? '')) ?>"
                                   style="min-width:16rem;padding:6px" placeholder="laskutus@seura.fi">
                        </label>
                    </p>
                </fieldset>
                <p>
                    <label>GPS-paketti
                        <select name="paketti_id">
                            <?php foreach ($paketit as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === (int) $s['paketti_id'] ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($p['nimi']) ?> (<?= htmlspecialchars($p['koodi']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Tallenna</button>
                </p>
            </form>
            <form method="post" style="margin:0">
                <?= $csrf ?>
                <input type="hidden" name="toiminto" value="tila">
                <input type="hidden" name="seura_id" value="<?= $sid ?>">
                <input type="hidden" name="tila" value="<?= $s['tila'] === 'aktiivinen' ? 'suljettu' : 'aktiivinen' ?>">
                <button type="submit" class="btn-secondary"><?= $s['tila'] === 'aktiivinen' ? 'Sulje seura' : 'Avaa seura' ?></button>
            </form>
            <form method="post" style="margin:0.6rem 0 0" onsubmit="return confirm('Poistetaanko seura <?= htmlspecialchars((string) $s['nimi'], ENT_QUOTES) ?> ja sen käyttäjät pysyvästi? Tätä ei voi perua.');">
                <?= $csrf ?>
                <input type="hidden" name="toiminto" value="poista_seura">
                <input type="hidden" name="seura_id" value="<?= $sid ?>">
                <button type="submit" class="btn-danger">Poista seura ja sen käyttäjät</button>
            </form>
    </div>
<?php else: ?>
<?php if (!$seurat): ?>
    <div class="public-card" style="margin-top:1rem">
        <p class="muted">Ei seuroja vielä. Käyttäjät luovat seuran rekisteröityessä.</p>
    </div>
<?php else: ?>
    <div class="seura-kortti-lista">
        <?php foreach ($seurat as $s): ?>
            <a class="seura-kortti" href="seurat.php?id=<?= (int) $s['id'] ?>">
                <?php if (function_exists('seuraLogoHtml')) {
                    seuraLogoHtml($s);
                } ?>
                <span class="seura-kortti-teksti">
                    <strong class="seura-kortti-nimi"><?= htmlspecialchars((string) $s['nimi']) ?></strong>
                    <span class="seura-kortti-meta muted">
                        <?= (int) $s['jasen_lkm'] ?> jäsentä
                        · <?= htmlspecialchars((string) $s['tila']) ?>
                    </span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$valittuSeura): ?>

<div class="public-card">
    <h2>Rastilippu-laskutus <?= (int) $laskutusVuosi ?></h2>
    <p class="muted">
        <?= htmlspecialchars(tiliEuroTeksti(tiliRastilippuHintaEuro())) ?>/tapahtuma ·
        laskutetaan vuosittain seuran laskutussähköpostiin.
        Yhteensä <?= (int) $laskuTapahtumat ?> tapahtumaa ·
        <?= htmlspecialchars(tiliEuroTeksti($laskuSumma)) ?>.
        Seurat, joille on merkitty ei laskutusta, eivät näy tässä listassa.
    </p>
    <p>
        <?php for ($y = $nytVuosi; $y >= $nytVuosi - 3; $y--): ?>
            <?php if ($y === $laskutusVuosi): ?>
                <strong><?= $y ?></strong>
            <?php else: ?>
                <a href="seurat.php?vuosi=<?= $y ?>"><?= $y ?></a>
            <?php endif; ?>
            <?= $y > $nytVuosi - 3 ? ' · ' : '' ?>
        <?php endfor; ?>
    </p>
    <?php if (!$laskut): ?>
        <p class="muted">Ei liitäntöjä eikä yhdistettyjä tapahtumia tälle vuodelle.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse">
            <tr>
                <th>Seura</th>
                <th>Laskutussähköposti</th>
                <th>Liitäntä</th>
                <th>Tapahtumia</th>
                <th>Summa</th>
            </tr>
            <?php foreach ($laskut as $rivi): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $rivi['nimi']) ?></td>
                    <td>
                        <?php if ($rivi['laskutus_email'] !== ''): ?>
                            <?= htmlspecialchars($rivi['laskutus_email']) ?>
                        <?php else: ?>
                            <span class="error">puuttuu</span>
                        <?php endif; ?>
                    </td>
                    <td><?= !empty($rivi['liitanta']) ? 'päällä' : 'ei' ?></td>
                    <td><?= (int) $rivi['yhdistettyja'] ?></td>
                    <td><?= htmlspecialchars(tiliEuroTeksti((float) $rivi['summa_euro'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<div class="public-card">
    <h2>GPS-paketit</h2>
    <table border="1" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:24px">
        <tr>
            <th>Koodi</th><th>Nimi</th><th>Laitteet</th><th>Kesto</th><th>Tapahtumat/kk</th><th>Kuvaus</th>
        </tr>
        <?php foreach ($paketit as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['koodi']) ?></td>
                <td><?= htmlspecialchars($p['nimi']) ?></td>
                <td><?= (int) $p['max_laitteet'] ?></td>
                <td><?= (int) $p['max_kesto_tuntia'] ?> h</td>
                <td><?= (int) $p['max_tapahtumat_kk'] ?></td>
                <td><?= htmlspecialchars((string) ($p['kuvaus'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php endif; ?>

<?php
if ($hasLayout) {
    pageEnd();
} else {
    echo '</div></body></html>';
}
