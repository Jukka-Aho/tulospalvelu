<?php

require_once __DIR__ . '/../includes/auth.php';
vaadiKirjautuminen();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tili.php';
if (is_readable(__DIR__ . '/../includes/iltarasti.php')) {
    require_once __DIR__ . '/../includes/iltarasti.php';
}

$hasLayout = is_readable(__DIR__ . '/../includes/layout.php')
    && is_readable(__DIR__ . '/../includes/helpers.php');
if ($hasLayout) {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/layout.php';
}

tiliVarmistaTaulut($pdo);
tiliLataaKayttajaSessioon($pdo);

$viesti = '';
$virhe = '';
$userId = nykyinenUserId();
$seura = tiliHaeSeuraKayttajalle($pdo, $userId);
$omatSeurat = tiliHaeSeuratKayttajalle($pdo, $userId);

$voiHallita = $seura && in_array($seura['jasen_rooli'] ?? '', ['omistaja', 'admin'], true);
$gpsPaalla = $seura && tiliSeuraPalveluPaalla($seura, 'gps');
$kisatPaalla = $seura && tiliSeuraPalveluPaalla($seura, 'kilpailut');
$illatPaalla = $seura && tiliSeuraPalveluPaalla($seura, 'iltarastit');
$vainIltarastit = $seura && tiliSeuraVainIltarastit($seura);
$sallitutPalvelut = $seura ? tiliSeuraSallitutJasenPalvelut($seura) : [];
$ominaisuudet = ($seura && $gpsPaalla) ? tiliPakettiOminaisuudet([
    'koodi' => $seura['paketti_koodi'],
    'ominaisuudet' => $seura['ominaisuudet'],
]) : [];

$haeJasen = static function (PDO $pdo, int $seuraId, int $jasenId): ?array {
    if ($seuraId < 1 || $jasenId < 1) {
        return null;
    }
    $st = $pdo->prepare("
        SELECT j.*, u.username, u.email, u.nimi AS user_nimi, u.role AS user_role
        FROM seura_jasenet j
        INNER JOIN users u ON u.id = j.user_id
        WHERE j.id = ? AND j.seura_id = ? AND j.tila = 'aktiivinen'
        LIMIT 1
    ");
    $st->execute([$jasenId, $seuraId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (function_exists('tarkistaCsrf') && !tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Virheellinen pyyntö');
        }
        $toiminto = (string) ($_POST['toiminto'] ?? '');
        if ($toiminto === 'luo_seura') {
            if (!tiliVoiLuodaSeuran($seura)) {
                throw new RuntimeException('Ei oikeutta luoda seuraa.');
            }
            tiliLuoSeuraKayttajalle($pdo, $userId, (string) ($_POST['seura_nimi'] ?? ''), 'ilmainen');
            tiliLataaKayttajaSessioon($pdo);
            $seura = tiliHaeSeuraKayttajalle($pdo, $userId);
            $omatSeurat = tiliHaeSeuratKayttajalle($pdo, $userId);
            $voiHallita = $seura && in_array($seura['jasen_rooli'] ?? '', ['omistaja', 'admin'], true);
            $viesti = 'Seura luotu. Voit lisätä käyttäjiä.';
        }
        if ($toiminto === 'vaihda_seura') {
            tiliVaihdaAktiivinenSeura($pdo, $userId, (int) ($_POST['seura_id'] ?? 0));
            tiliLataaKayttajaSessioon($pdo);
            $seura = tiliHaeSeuraKayttajalle($pdo, $userId);
            $omatSeurat = tiliHaeSeuratKayttajalle($pdo, $userId);
            $voiHallita = $seura && in_array($seura['jasen_rooli'] ?? '', ['omistaja', 'admin'], true);
            $viesti = 'Aktiivinen seura vaihdettu.';
        }
        if ($toiminto === 'lopeta_seura') {
            if (!$seura) {
                throw new RuntimeException('Seura puuttuu.');
            }
            tiliLopetaSeura(
                $pdo,
                (int) $seura['id'],
                $userId,
                (string) ($_POST['lopeta_seura_nimi'] ?? ''),
                !empty($_POST['haluan_lopettaa'])
            );
            tiliLataaKayttajaSessioon($pdo);
            $seura = tiliHaeSeuraKayttajalle($pdo, $userId);
            $omatSeurat = tiliHaeSeuratKayttajalle($pdo, $userId);
            $voiHallita = $seura && in_array($seura['jasen_rooli'] ?? '', ['omistaja', 'admin'], true);
            $viesti = 'Seura on lopetettu JAHOnlinessa.';
        }
        if ($seura && $voiHallita && $toiminto === 'rastilippu_liitanta') {
            if (!$illatPaalla) {
                throw new RuntimeException('Iltarastit-palvelu ei ole käytössä tällä seuralla.');
            }
            tiliTallennaRastilippuLiitanta(
                $pdo,
                (int) $seura['id'],
                !empty($_POST['rastilippu_liitanta']),
                (string) ($_POST['laskutus_email'] ?? '')
            );
            tiliLataaKayttajaSessioon($pdo);
            $seura = tiliHaeSeuraKayttajalle($pdo, $userId);
            $viesti = 'IRMA/Rastilippu-liitäntä tallennettu.';
        }
        if ($seura && $voiHallita && $toiminto === 'kutsu') {
            $uname = trim((string) ($_POST['username'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            $emailRaw = (string) ($_POST['email'] ?? '');
            if ($uname === '') {
                throw new RuntimeException('Anna käyttäjätunnus');
            }
            $seuraId = (int) $seura['id'];
            if ($vainIltarastit || $sallitutPalvelut === ['iltarastit']) {
                $taso = (string) ($_POST['taso'] ?? 'kayttaja');
                if (!in_array($taso, ['kayttaja', 'paakayttaja'], true)) {
                    $taso = 'kayttaja';
                }
                $jasenRooli = $taso === 'paakayttaja' ? 'admin' : 'jasen';
                $exists = tiliHaeKayttajaTunnuksella($pdo, $uname);
                if ($exists) {
                    $uid = (int) $exists['id'];
                    if (tiliSahkopostiPuuttuu($exists) || $emailRaw !== '') {
                        $email = tiliValidoiSahkoposti($pdo, $emailRaw, $uid, $seuraId);
                        $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND (email IS NULL OR email = \'\')')
                            ->execute([$email, $uid]);
                    }
                    tiliAsetaKayttajanSeura($pdo, $uid, $seuraId, $jasenRooli, 'iltarastit');
                } else {
                    if (function_exists('iltarastiLuoKayttaja')) {
                        iltarastiLuoKayttaja(
                            $pdo,
                            $seuraId,
                            $uname,
                            $pass,
                            $emailRaw,
                            $taso,
                            (int) paakayttajaId()
                        );
                    } else {
                        throw new RuntimeException('Iltarastikäyttäjän luonti ei onnistunut.');
                    }
                }
                $viesti = 'Iltarastikäyttäjä lisätty: ' . $uname;
            } else {
                $palvelut = tiliJasenPalvelutSeurasta($seura);
                $iltaOikeus = ($illatPaalla && !empty($_POST['iltarasti_oikeus'])) ? 1 : 0;
                $rooli = (string) ($_POST['rooli'] ?? 'jasen');
                if (!in_array($rooli, ['admin', 'jasen'], true)) {
                    $rooli = 'jasen';
                }
                $exists = tiliHaeKayttajaTunnuksella($pdo, $uname);
                if ($exists) {
                    $uid = (int) $exists['id'];
                    if (tiliSahkopostiPuuttuu($exists) || $emailRaw !== '') {
                        $email = tiliValidoiSahkoposti($pdo, $emailRaw, $uid, $seuraId);
                        $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND (email IS NULL OR email = \'\')')
                            ->execute([$email, $uid]);
                    }
                } else {
                    tiliValidoiSalasana($pass);
                    $email = tiliValidoiSahkoposti($pdo, $emailRaw, null, $seuraId);
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $uusiRooli = ($iltaOikeus && tiliKisaValikotMerkkijonoksi($_POST['kisa_valikot'] ?? []) === '')
                        ? 'iltarasti'
                        : 'ajanottaja';
                    if (function_exists('kayttajaRoolitVarmista')) {
                        kayttajaRoolitVarmista($pdo);
                    }
                    $pdo->prepare('INSERT INTO users (username, password, role, email, paakayttaja_id) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$uname, $hash, $uusiRooli, $email, (int) paakayttajaId()]);
                    $uid = (int) $pdo->lastInsertId();
                }
                $kisaValikot = tiliKisaValikotMerkkijonoksi($_POST['kisa_valikot'] ?? []);
                $pdo->prepare("
                    INSERT INTO seura_jasenet (seura_id, user_id, rooli, palvelut, kisa_valikot, iltarasti_oikeus, tila)
                    VALUES (?, ?, ?, ?, ?, ?, 'aktiivinen')
                    ON DUPLICATE KEY UPDATE
                        rooli = VALUES(rooli),
                        palvelut = VALUES(palvelut),
                        kisa_valikot = VALUES(kisa_valikot),
                        iltarasti_oikeus = VALUES(iltarasti_oikeus),
                        tila = 'aktiivinen'
                ")->execute([
                    $seuraId,
                    $uid,
                    $rooli,
                    $palvelut,
                    $kisaValikot === '' ? null : $kisaValikot,
                    $iltaOikeus,
                ]);
                $pdo->prepare('UPDATE users SET viimeisin_seura_id = ? WHERE id = ? AND (viimeisin_seura_id IS NULL OR viimeisin_seura_id = 0)')
                    ->execute([$seuraId, $uid]);
                $viesti = 'Jäsen lisätty: ' . $uname;
            }
        }
        if ($seura && $voiHallita && $toiminto === 'muokkaa_jasen') {
            $kohde = $haeJasen($pdo, (int) $seura['id'], (int) ($_POST['jasen_id'] ?? 0));
            if (!$kohde) {
                throw new RuntimeException('Jäsentä ei löytynyt.');
            }
            $taso = (string) ($_POST['taso'] ?? '');
            $rooli = (string) ($_POST['rooli'] ?? '');
            if ($taso === 'paakayttaja') {
                $rooli = 'admin';
            } elseif ($taso === 'kayttaja') {
                $rooli = 'jasen';
            }
            $palvelut = ($vainIltarastit || $sallitutPalvelut === ['iltarastit'])
                ? 'iltarastit'
                : tiliJasenPalvelutSeurasta($seura);
            $kisaValikot = array_key_exists('kisa_valikot', $_POST)
                ? tiliKisaValikotMerkkijonoksi($_POST['kisa_valikot'] ?? [])
                : (array_key_exists('kisa_valikot_muokkaus', $_POST)
                    ? tiliKisaValikotMerkkijonoksi([])
                    : null);
            $iltaOikeus = array_key_exists('iltarasti_oikeus_muokkaus', $_POST)
                ? (!empty($_POST['iltarasti_oikeus']) ? 1 : 0)
                : null;
            tiliPaivitaSeuraJasen(
                $pdo,
                $seura,
                $kohde,
                $userId,
                (string) ($_POST['email'] ?? ''),
                $rooli,
                $palvelut,
                $kisaValikot,
                $iltaOikeus
            );
            $viesti = 'Käyttäjä päivitetty.';
        }
        if ($seura && $voiHallita && $toiminto === 'poista_jasen') {
            $kohde = $haeJasen($pdo, (int) $seura['id'], (int) ($_POST['jasen_id'] ?? 0));
            if (!$kohde) {
                throw new RuntimeException('Jäsentä ei löytynyt.');
            }
            tiliPoistaJasenSeurasta($pdo, $seura, $kohde, $userId);
            $viesti = 'Käyttäjä poistettu seurasta.';
        }
    } catch (Throwable $e) {
        $virhe = $e->getMessage();
    }
    $gpsPaalla = $seura && tiliSeuraPalveluPaalla($seura, 'gps');
    $kisatPaalla = $seura && tiliSeuraPalveluPaalla($seura, 'kilpailut');
    $illatPaalla = $seura && tiliSeuraPalveluPaalla($seura, 'iltarastit');
    $vainIltarastit = $seura && tiliSeuraVainIltarastit($seura);
    $sallitutPalvelut = $seura ? tiliSeuraSallitutJasenPalvelut($seura) : [];
    $ominaisuudet = ($seura && $gpsPaalla) ? tiliPakettiOminaisuudet([
        'koodi' => $seura['paketti_koodi'],
        'ominaisuudet' => $seura['ominaisuudet'],
    ]) : [];
}

$csrfKentta = function_exists('csrfKentta') ? csrfKentta() : '';

$jasenet = [];
$rl = null;
if ($seura && $voiHallita) {
    $st = $pdo->prepare("
        SELECT j.*, u.username, u.email, u.nimi AS user_nimi, u.role AS user_role
        FROM seura_jasenet j
        INNER JOIN users u ON u.id = j.user_id
        WHERE j.seura_id = ? AND j.tila = 'aktiivinen'
        ORDER BY FIELD(j.rooli, 'omistaja', 'admin', 'jasen'), u.username
    ");
    $st->execute([(int) $seura['id']]);
    $jasenet = $st->fetchAll(PDO::FETCH_ASSOC);
}
if ($seura && $illatPaalla && $voiHallita) {
    $rl = tiliRastilippuLaskutus($pdo, (int) $seura['id']);
}

$labels = [
    'gps' => 'GPS',
    'gps_asetukset' => 'GPS-asetusten muokkaus',
    'gps_tv' => 'TV-preset',
    'gps_iframe' => 'Iframe-upotus',
    'gps_ghost' => 'Ghost',
    'gps_replay' => 'Replay / jälkikatselu',
    'gps_analyysi' => 'Välianalyysi',
    'gps_gpx' => 'GPX-vienti',
    'tulospalvelu' => 'Tulospalvelu',
];
$palveluNimet = [
    'gps' => 'GPS',
    'tulokset' => 'Kilpailut',
    'molemmat' => 'GPS + kilpailut',
    'iltarastit' => 'Iltarastit',
];

$jasenTauluCss = <<<'CSS'
<style>
.jasen-taulu { display:flex; flex-direction:column; gap:0.85rem; }
.jasen-taulu-otsikot, .jasen-taulu-rivi {
  display:grid;
  grid-template-columns: minmax(7rem,0.9fr) minmax(12rem,1.2fr) minmax(10.5rem,0.9fr) minmax(16rem,2.2fr) 12.5rem;
  gap:0.55rem 0.9rem;
  align-items:start;
}
.jasen-taulu-otsikot {
  font-weight:700;
  padding:0 0 0.4rem;
  border-bottom:1px solid #c5d0c8;
}
.jasen-taulu-rivi { padding:0.35rem 0 0.7rem; border-bottom:1px solid #e4ebe6; }
.jasen-taulu-rivi input[type=email] { width:100%; min-width:0; padding:6px; box-sizing:border-box; }
.jasen-rooli select, select.jasen-rooli {
  font-size:1.15rem;
  min-height:2.4rem;
  min-width:10.5rem;
  width:100%;
  padding:0.35rem 0.5rem;
  box-sizing:border-box;
}
.kisa-valikot { border:1px solid #c5d0c8; border-radius:8px; padding:0.5rem 0.7rem; margin:0; }
.kisa-valikot legend { font-weight:600; }
.kisa-valikot-vaihtoehto, .iltarasti-oikeus-kentta { display:block; margin:0.22rem 0; }
.iltarasti-oikeus-kentta { margin-top:0.55rem; font-weight:600; }
.jasen-taulu-toiminnot { display:flex; flex-direction:column; gap:0.4rem; align-items:stretch; width:100%; }
.jasen-taulu-toiminnot button {
  width:100%;
  box-sizing:border-box;
  margin:0;
  padding:0.45rem 0.6rem;
  text-align:center;
  white-space:nowrap;
}
.jasen-taulu-toiminnot .jasen-poista,
.jasen-taulu-toiminnot button.btn-danger {
  background:#b42318;
  border-color:#8f1d14;
  color:#fff;
}
.jasen-taulu-toiminnot .jasen-poista:hover,
.jasen-taulu-toiminnot button.btn-danger:hover {
  background:#8f1d14;
  border-color:#7a1811;
  color:#fff;
}
.jasen-taulu form[hidden], .jasen-taulu .jasen-poista-lomake { display:none !important; }
@media (max-width: 820px) {
  .jasen-taulu-otsikot { display:none; }
  .jasen-taulu-rivi, .jasen-taulu-otsikot {
    grid-template-columns: 1fr;
  }
}
</style>
CSS;

if ($hasLayout) {
    pageStart('Oma seura', ['admin' => true, 'active' => 'oma_seura', 'pdo' => $pdo, 'extraHead' => $jasenTauluCss]);
} else {
    echo '<!DOCTYPE html><html lang="fi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Oma seura</title><link rel="stylesheet" href="../assets/css/style.css"></head><body><div class="container">';
    echo '<p><a href="index.php">← Hallinta</a></p>';
}
?>

<h1>Oma seura</h1>

<?php if ($viesti): ?><p class="notice" style="color:#c49a22"><?= htmlspecialchars($viesti) ?></p><?php endif; ?>
<?php if ($virhe): ?><p class="error" style="color:#c62828"><?= htmlspecialchars($virhe) ?></p><?php endif; ?>

<?php if (!$seura): ?>
    <div class="public-card">
        <p>Sinulla ei ole vielä seuraa. Luo se aloittaaksesi iltarastit.</p>
        <form method="post">
            <?= $csrfKentta ?>
            <input type="hidden" name="toiminto" value="luo_seura">
            <label>Seuran nimi<br>
                <input name="seura_nimi" required style="width:100%;max-width:360px;padding:8px">
            </label>
            <br><br>
            <button type="submit">Luo seura</button>
        </form>
    </div>
<?php else: ?>
    <div class="public-card">
        <h2><?= htmlspecialchars($seura['nimi']) ?></h2>
        <?php if (count($omatSeurat) > 1): ?>
            <form method="post" style="margin:0 0 1rem">
                <?= $csrfKentta ?>
                <input type="hidden" name="toiminto" value="vaihda_seura">
                <label>Aktiivinen seura
                    <select name="seura_id">
                        <?php foreach ($omatSeurat as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) $seura['id'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) $s['nimi']) ?>
                                (<?= htmlspecialchars((string) $s['jasen_rooli']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Vaihda seuraa</button>
            </form>
        <?php endif; ?>
        <p>
            Palvelut:
            <?php
            $nimet = [];
            if ($gpsPaalla) {
                $nimet[] = 'GPS';
            }
            if ($kisatPaalla) {
                $nimet[] = 'Kilpailut';
            }
            if ($illatPaalla) {
                $nimet[] = 'Iltarastit';
            }
            echo $nimet ? htmlspecialchars(implode(' · ', $nimet)) : '–';
            ?>
        </p>
        <?php if ($gpsPaalla): ?>
            <p>
                GPS-paketti: <strong><?= htmlspecialchars($seura['paketti_nimi']) ?></strong>
                (<?= htmlspecialchars($seura['paketti_koodi']) ?>)
                · max <?= (int) $seura['max_laitteet'] ?> laitetta
                · max <?= (int) $seura['max_kesto_tuntia'] ?> h
                · max <?= (int) $seura['max_tapahtumat_kk'] ?> tapahtumaa / kk
            </p>
            <h3>Ominaisuudet</h3>
            <ul>
                <?php foreach ($labels as $k => $lab): ?>
                    <?php if ($k !== 'tulospalvelu' || $kisatPaalla): ?>
                        <li><?= !empty($ominaisuudet[$k]) ? '✓' : '–' ?> <?= htmlspecialchars($lab) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <?php if (($seura['paketti_koodi'] ?? '') === 'ilmainen'): ?>
                <p class="muted"><em>Ilmainen paketti: tapahtumat vakioasetuksilla. Pro/Analyysi avaa lisäominaisuudet – pyydä ylläpitäjältä päivitystä.</em></p>
            <?php endif; ?>
            <p><a href="../gps/hallinta.php">Avaa GPS-hallinta →</a></p>
        <?php endif; ?>
        <p class="muted">Roolisi: <?= htmlspecialchars($seura['jasen_rooli']) ?><?php if (!$vainIltarastit): ?> · jäsenen palvelut: <?= htmlspecialchars($seura['jasen_palvelut']) ?><?php endif; ?></p>
        <?php if ($illatPaalla && $voiHallita): ?>
            <p><a href="/public/omat_tapahtumat.php">Omat tapahtumat<?= tiliSeuraRastilippuPaalla($seura) ? ' ja Rastilippu-avain' : '' ?> →</a></p>
        <?php endif; ?>
    </div>

    <?php if ($illatPaalla && $voiHallita): ?>
        <div class="public-card" style="margin-top:1rem">
            <h3>IRMA/Rastilippu-liitäntä</h3>
            <p class="muted">
                Valinnainen lisäominaisuus. Maksaa
                <strong><?= htmlspecialchars(tiliEuroTeksti(tiliRastilippuHintaEuro())) ?>/tapahtuma</strong>
                ja laskutetaan vuosittain tätä varten annettuun laskutussähköpostiin.
            </p>
            <?php if ($rl): ?>
                <p><?= htmlspecialchars($rl['teksti']) ?></p>
                <?php if ($rl['liitanta'] && $rl['laskutus_email'] === '' && empty($rl['ei_laskutusta'])): ?>
                    <p class="notice">Lisää laskutussähköposti, jotta vuosittainen lasku voidaan lähettää.</p>
                <?php endif; ?>
            <?php endif; ?>
            <form method="post">
                <?= $csrfKentta ?>
                <input type="hidden" name="toiminto" value="rastilippu_liitanta">
                <p>
                    <label>
                        <input type="checkbox" name="rastilippu_liitanta" value="1"<?= tiliSeuraRastilippuPaalla($seura) ? ' checked' : '' ?>>
                        Ota IRMA/Rastilippu-liitäntä käyttöön
                    </label>
                </p>
                <label>Laskutussähköposti<br>
                    <input type="email" name="laskutus_email" value="<?= htmlspecialchars((string) ($seura['laskutus_email'] ?? '')) ?>"
                           style="width:100%;max-width:320px;padding:8px" autocomplete="email"
                           placeholder="laskutus@seura.fi">
                </label>
                <p class="muted">Pakollinen, kun liitäntä on päällä. Lasku lähetetään tähän osoitteeseen kerran vuodessa.</p>
                <br>
                <button type="submit">Tallenna liitäntä</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($voiHallita): ?>
    <div class="public-card" style="margin-top:1rem">
        <h3><?= $vainIltarastit ? 'Iltarastikäyttäjät' : 'Jäsenet' ?></h3>
        <div class="jasen-taulu">
            <div class="jasen-taulu-otsikot">
                <div>Tunnus</div>
                <div>Sähköposti</div>
                <div>Rooli</div>
                <div><?= $vainIltarastit ? '' : 'Oikeudet' ?></div>
                <div></div>
            </div>
            <?php foreach ($jasenet as $j): ?>
                <?php
                $voiMuokata = tiliVoiMuokataSeuraJasenta($seura, $j, $userId);
                $formId = 'muokkaa-jasen-' . (int) $j['id'];
                $rooliTeksti = ($vainIltarastit && function_exists('iltarastiKayttajaTasoLabel'))
                    ? iltarastiKayttajaTasoLabel((string) $j['rooli'])
                    : (string) $j['rooli'];
                ?>
                <form method="post" class="jasen-taulu-rivi" id="<?= htmlspecialchars($formId) ?>">
                    <?= $csrfKentta ?>
                    <input type="hidden" name="toiminto" value="muokkaa_jasen">
                    <input type="hidden" name="jasen_id" value="<?= (int) $j['id'] ?>">
                    <div><?= htmlspecialchars($j['username']) ?><?= $j['user_nimi'] ? ' (' . htmlspecialchars($j['user_nimi']) . ')' : '' ?></div>
                    <div>
                        <?php if ($voiMuokata): ?>
                            <input type="email" name="email" required aria-label="Sähköposti"
                                   value="<?= htmlspecialchars((string) ($j['email'] ?? '')) ?>" autocomplete="email">
                        <?php else: ?>
                            <?= htmlspecialchars((string) ($j['email'] ?? '—')) ?>
                        <?php endif; ?>
                    </div>
                    <div class="jasen-rooli">
                        <?php if ($voiMuokata && $j['rooli'] !== 'omistaja'): ?>
                            <?php if ($vainIltarastit || $sallitutPalvelut === ['iltarastit']): ?>
                                <select name="taso" aria-label="Rooli">
                                    <option value="kayttaja"<?= $j['rooli'] !== 'admin' ? ' selected' : '' ?>>Iltarastikäyttäjä</option>
                                    <option value="paakayttaja"<?= $j['rooli'] === 'admin' ? ' selected' : '' ?>>Pääkäyttäjä</option>
                                </select>
                            <?php else: ?>
                                <select name="rooli" aria-label="Rooli">
                                    <option value="jasen"<?= $j['rooli'] !== 'admin' ? ' selected' : '' ?>>Jäsen</option>
                                    <option value="admin"<?= $j['rooli'] === 'admin' ? ' selected' : '' ?>>Seuran admin</option>
                                </select>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= htmlspecialchars($rooliTeksti) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($voiMuokata && $j['rooli'] !== 'omistaja' && !($vainIltarastit || $sallitutPalvelut === ['iltarastit'])): ?>
                            <?php if ($kisatPaalla): ?>
                                <input type="hidden" name="kisa_valikot_muokkaus" value="1">
                                <?php tiliKisaValikotKenttaHtml($j['kisa_valikot'] ?? ''); ?>
                            <?php endif; ?>
                            <?php if ($illatPaalla): ?>
                                <input type="hidden" name="iltarasti_oikeus_muokkaus" value="1">
                                <?php tiliIltarastiOikeusKenttaHtml(tiliJasenellaIltarastiOikeus($j)); ?>
                            <?php endif; ?>
                        <?php elseif ($j['rooli'] === 'omistaja' || $j['rooli'] === 'admin'): ?>
                            <span class="muted">Kaikki oikeudet</span>
                        <?php endif; ?>
                    </div>
                    <div class="jasen-taulu-toiminnot">
                        <?php if ($voiMuokata): ?>
                            <button type="submit">Tallenna</button>
                            <?php if ($j['rooli'] !== 'omistaja'): ?>
                                <button type="submit" class="jasen-poista btn-danger" form="poista-jasen-<?= (int) $j['id'] ?>">Poista seurasta</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($voiMuokata && $j['rooli'] !== 'omistaja'): ?>
                    <form method="post" id="poista-jasen-<?= (int) $j['id'] ?>" class="jasen-poista-lomake" hidden
                          onsubmit="return confirm('Poistetaanko käyttäjä tästä seurasta?');">
                        <?= $csrfKentta ?>
                        <input type="hidden" name="toiminto" value="poista_jasen">
                        <input type="hidden" name="jasen_id" value="<?= (int) $j['id'] ?>">
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <h3><?= $vainIltarastit ? 'Lisää iltarastikäyttäjä' : 'Lisää jäsen' ?></h3>
        <p class="muted">
            <?= $vainIltarastit
                ? 'Luo uusi iltarastitunnus seuraan tai liitä olemassa oleva käyttäjätunnus.'
                : 'Luo uusi tunnus seuraan tai liitä olemassa oleva käyttäjätunnus.' ?>
        </p>
        <form method="post">
            <?= $csrfKentta ?>
            <input type="hidden" name="toiminto" value="kutsu">
            <label>Käyttäjätunnus<br><input name="username" required style="width:100%;max-width:280px;padding:8px"></label>
            <br><br>
            <label>Sähköposti<br>
                <input type="email" name="email" required style="width:100%;max-width:280px;padding:8px" autocomplete="email">
            </label>
            <br><br>
            <label>Salasana (uudelle tunnukselle)<br>
                <input type="password" name="password" required minlength="8" style="width:100%;max-width:280px;padding:8px">
            </label>
            <p class="muted"><?= htmlspecialchars(tiliSalasanaVaatimusTeksti()) ?></p>
            <br><br>
            <?php if ($vainIltarastit || $sallitutPalvelut === ['iltarastit']): ?>
                <label>Taso
                    <select name="taso" class="jasen-rooli">
                        <option value="kayttaja">Iltarastikäyttäjä</option>
                        <option value="paakayttaja">Pääkäyttäjä</option>
                    </select>
                </label>
            <?php else: ?>
                <label>Rooli
                    <select name="rooli" class="jasen-rooli">
                        <option value="jasen">Jäsen</option>
                        <option value="admin">Seuran admin</option>
                    </select>
                </label>
                <br><br>
                <?php if ($kisatPaalla): ?>
                    <?php tiliKisaValikotKenttaHtml([]); ?>
                <?php endif; ?>
                <?php if ($illatPaalla): ?>
                    <?php tiliIltarastiOikeusKenttaHtml(false); ?>
                <?php endif; ?>
            <?php endif; ?>
            <br><br>
            <button type="submit"><?= $vainIltarastit ? 'Lisää iltarastikäyttäjä' : 'Lisää jäsen' ?></button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (tiliVoiLuodaSeuran($seura)): ?>
        <div class="public-card" style="margin-top:1rem">
            <h3>Lisää seura</h3>
            <p class="muted">Luo toinen seura. Sinusta tulee sen pääkäyttäjä, ja se vaihtuu aktiiviseksi.</p>
            <form method="post">
                <?= $csrfKentta ?>
                <input type="hidden" name="toiminto" value="luo_seura">
                <label>Seuran nimi<br>
                    <input name="seura_nimi" required style="width:100%;max-width:360px;padding:8px">
                </label>
                <br><br>
                <button type="submit">Lisää seura</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (tiliOnSeuranOmistaja($seura)): ?>
        <div class="public-card" style="margin-top:1rem">
            <h3>Lopeta seura</h3>
            <p class="muted">
                Seura merkitään suljetuksi JAHOnlinessa. Käyttäjätunnuksia ei poisteta.
                Sivuston pääkäyttäjä voi poistaa seuran ja sen käyttäjät kokonaan.
            </p>
            <form method="post" class="lopeta-seura-vahvistus">
                <?= $csrfKentta ?>
                <input type="hidden" name="toiminto" value="lopeta_seura">
                <p>Kirjoita seuran nimi <strong><?= htmlspecialchars((string) $seura['nimi']) ?></strong> ja paina vahvistus.</p>
                <label>Seuran nimi<br>
                    <input name="lopeta_seura_nimi" required autocomplete="off"
                           style="width:100%;max-width:360px;padding:8px"
                           placeholder="<?= htmlspecialchars((string) $seura['nimi']) ?>">
                </label>
                <br><br>
                <button type="submit" name="haluan_lopettaa" value="1" class="btn-danger">Haluan lopettaa seuran</button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
if ($hasLayout) {
    pageEnd();
} else {
    echo '</div></body></html>';
}
