<?php

require_once __DIR__ . '/../includes/auth.php';
vaadiKirjautuminen();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tili.php';

tiliVarmistaTaulut($pdo);
tiliLataaKayttajaSessioon($pdo);

if (!onAdmin()
    && function_exists('tiliSessioSeuraPalvelu')
    && tiliSessioSeuraPalvelu('iltarastit')
    && !tiliSessioSeuraPalvelu('gps')
    && !tiliSessioSeuraPalvelu('kilpailut')
) {
    header('Location: /admin/iltarastit.php');
    exit;
}

$hasLayout = is_readable(__DIR__ . '/../includes/layout.php')
    && is_readable(__DIR__ . '/../includes/helpers.php');

if ($hasLayout) {
    require_once __DIR__ . '/../includes/helpers.php';
    if (is_readable(__DIR__ . '/../includes/acl.php')) {
        require_once __DIR__ . '/../includes/acl.php';
    }
    if (is_readable(__DIR__ . '/../includes/kilpailu_aikavali.php')) {
        require_once __DIR__ . '/../includes/kilpailu_aikavali.php';
    }
    if (is_readable(__DIR__ . '/../includes/kilpailu_context.php')) {
        require_once __DIR__ . '/../includes/kilpailu_context.php';
    }
    if (is_readable(__DIR__ . '/../includes/pirila_mode.php')) {
        require_once __DIR__ . '/../includes/pirila_mode.php';
    }
    require_once __DIR__ . '/../includes/layout.php';

    $saaHallintaan = (function_exists('voiKisahallintaan') && voiKisahallintaan())
        || (function_exists('onPaakayttaja') && onPaakayttaja());
    if (!$saaHallintaan) {
        header('Location: ' . kirjautumisKohde(kayttajaRooli()));
        exit;
    }
    if (function_exists('onIltarasti') && onIltarasti() && !voiKisahallintaan()) {
        header('Location: /admin/iltarastit.php');
        exit;
    }
}

// Kilpailun valinta / vaihto
if ($hasLayout && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('tarkistaCsrf') && !tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }
    $toiminto = (string) ($_POST['toiminto'] ?? $_POST['action'] ?? '');
    if ($toiminto === 'valitse_kilpailu') {
        $kid = (int) ($_POST['kilpailu_id'] ?? 0);
        $k = function_exists('haeKilpailuKayttoon')
            ? haeKilpailuKayttoon($pdo, $kid)
            : (function_exists('haeKilpailuHallintaan') ? haeKilpailuHallintaan($pdo, $kid) : null);
        if (!$k) {
            die('Ei käyttöoikeutta');
        }
        asetaValittuKilpailu($kid);
        header('Location: index.php');
        exit;
    }
    if ($toiminto === 'vaihda_kilpailu') {
        tyhjennaValittuKilpailu();
        header('Location: index.php');
        exit;
    }
    if ($toiminto === 'tyhjenna_testidata') {
        $kid = (int) ($_POST['kilpailu_id'] ?? 0);
        $k = function_exists('haeKilpailuHallintaan') ? haeKilpailuHallintaan($pdo, $kid) : null;
        if (!$k) {
            die('Ei käyttöoikeutta');
        }
        require_once __DIR__ . '/../includes/ajanotto_testidata.php';
        $myosAjat = !empty($_POST['myos_ajat']);
        try {
            $tulos = ajanottoTyhjennaTestidata($pdo, $kid, $myosAjat);
            $msg = 'Testidata tyhjennetty: '
                . (int) $tulos['leimat'] . ' leimaa, '
                . (int) $tulos['numerot'] . ' nroa'
                . ($myosAjat ? (', ' . (int) $tulos['ajat'] . ' aikaa') : '');
            header('Location: index.php?viesti=' . urlencode($msg));
        } catch (Throwable $e) {
            header('Location: index.php?viesti=' . urlencode('Tyhjennys epäonnistui') . '&virhe=1');
        }
        exit;
    }
    if ($toiminto === 'luo_tv_token') {
        $kid = (int) ($_POST['kilpailu_id'] ?? 0);
        $k = function_exists('haeKilpailuHallintaan') ? haeKilpailuHallintaan($pdo, $kid) : null;
        if (!$k) {
            die('Ei käyttöoikeutta');
        }
        require_once __DIR__ . '/../includes/tv_profiilit.php';
        // Pakota uusi token
        try {
            tvProfiilitVarmistaTaulut($pdo);
            $token = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE kilpailut SET tv_token = ? WHERE id = ?')->execute([$token, $kid]);
            header('Location: index.php?viesti=' . urlencode('TV-kiosk token luotu'));
        } catch (Throwable $e) {
            header('Location: index.php?viesti=' . urlencode('Tokenin luonti epäonnistui') . '&virhe=1');
        }
        exit;
    }
}

if ($hasLayout && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $getKisa = (int) ($_GET['kilpailu_id'] ?? 0);
    if ($getKisa > 0 && function_exists('asetaValittuKilpailu')) {
        $k = function_exists('haeKilpailuKayttoon')
            ? haeKilpailuKayttoon($pdo, $getKisa)
            : (function_exists('haeKilpailuHallintaan') ? haeKilpailuHallintaan($pdo, $getKisa) : null);
        if ($k) {
            asetaValittuKilpailu($getKisa);
            $q = $_GET;
            unset($q['kilpailu_id']);
            $redir = 'index.php';
            if ($q !== []) {
                $redir .= '?' . http_build_query($q);
            }
            header('Location: ' . $redir);
            exit;
        }
    }
}

$asetusViesti = '';
if (onAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['toiminto'] ?? '') === 'rekisterointi') {
    if (function_exists('tarkistaCsrf') && !tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }
    $auki = (($_POST['rekisterointi_auki'] ?? '') === '1');
    jahAsetaAsetus($pdo, 'rekisterointi_auki', $auki ? '1' : '0');
    $asetusViesti = $auki ? 'Rekisteröityminen avattu.' : 'Rekisteröityminen suljettu.';
}

$rekisterointiAuki = jahRekisterointiAuki($pdo);

$tervetuloa = isset($_GET['tervetuloa']);
$getViesti = trim((string) ($_GET['viesti'] ?? ''));
$getViestiVirhe = !empty($_GET['virhe']);
$seuraNimi = $_SESSION['seura_nimi'] ?? '';
$paketti = $_SESSION['paketti_koodi'] ?? '';
$voiGps = function_exists('tiliVoiPalvelun') ? tiliVoiPalvelun('gps') : true;
$voiTulokset = function_exists('tiliVoiPalvelun') ? tiliVoiPalvelun('tulokset') : true;
$ominaisuudet = function_exists('tiliNykyinenOminaisuudet')
    ? tiliNykyinenOminaisuudet($pdo)
    : ['gps' => true, 'tulospalvelu' => true];

$omatKisat = [];
$kisaAikavali = null;
if ($hasLayout && function_exists('onAdmin') && onAdmin() && function_exists('kilpailuParsiaikavali')) {
    $kisaAikavali = kilpailuParsiaikavali($_GET);
}
if ($hasLayout && function_exists('haeKayttajanKilpailut')) {
    try {
        $omatKisat = haeKayttajanKilpailut(
            $pdo,
            "CASE k.tila WHEN 'kaynnissa' THEN 0 WHEN 'ilmoittautuminen' THEN 1 WHEN 'tulossa' THEN 2 WHEN 'luonnos' THEN 3 ELSE 4 END, k.paiva DESC, k.id DESC",
            $kisaAikavali
        );
        $omatKisat = array_values(array_filter(
            $omatKisat,
            static fn(array $k): bool => ($k['tila'] ?? '') !== 'arkisto'
        ));
    } catch (Throwable $e) {
        $omatKisat = [];
    }
}

$kilpailu = null;
$tilastot = null;
if ($hasLayout && function_exists('haeValittuKilpailu')) {
    $kilpailu = haeValittuKilpailu($pdo);
    if ($kilpailu && function_exists('voiKayttaaSeuranKilpailua') && !voiKayttaaSeuranKilpailua($kilpailu)) {
        tyhjennaValittuKilpailu();
        $kilpailu = null;
    } elseif ($kilpailu && !function_exists('voiKayttaaSeuranKilpailua') && function_exists('voiHallitaKilpailua') && !voiHallitaKilpailua($kilpailu)) {
        tyhjennaValittuKilpailu();
        $kilpailu = null;
    }
    if ($kilpailu) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sarjat WHERE kilpailu_id = ?');
        $stmt->execute([$kilpailu['id']]);
        $sarja_lkm = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kilpailijat WHERE kilpailu_id = ?');
        $stmt->execute([$kilpailu['id']]);
        $kilpailija_lkm = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM ajat
            JOIN kilpailijat ON ajat.kilpailija_id = kilpailijat.id
            WHERE kilpailijat.kilpailu_id = ?
        ');
        $stmt->execute([$kilpailu['id']]);
        $maali_lkm = (int) $stmt->fetchColumn();
        $tilastot = ['sarjat' => $sarja_lkm, 'kilpailijat' => $kilpailija_lkm, 'maalit' => $maali_lkm];
    }
}

if ($hasLayout) {
    pageStart('Hallinta', ['admin' => true, 'active' => 'index', 'pdo' => $pdo]);
} else {
    echo '<!DOCTYPE html><html lang="fi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Hallinta</title><link rel="stylesheet" href="../assets/css/style.css"></head><body><div class="container">';
}
?>

<h1>Hallinta</h1>
<p class="muted">
    Tervetuloa, <?= htmlspecialchars((string) ($_SESSION['user'] ?? '')) ?>
    (<?= htmlspecialchars(function_exists('rooliLabel') ? rooliLabel((string) ($_SESSION['role'] ?? '')) : (string) ($_SESSION['role'] ?? '')) ?>)
    <?php if ($seuraNimi): ?>
        · Seura: <strong><?= htmlspecialchars($seuraNimi) ?></strong>
        <?php if ($paketti): ?> (<?= htmlspecialchars($paketti) ?>)<?php endif; ?>
    <?php endif; ?>
</p>

<?php if ($tervetuloa): ?>
    <p class="notice" style="background:#e8f5e9;padding:12px;border-radius:8px">
        Tervetuloa! Seura <strong><?= htmlspecialchars($seuraNimi) ?></strong> on luotu
        Ilmainen-paketilla. Voit heti luoda GPS-tapahtuman.
    </p>
<?php endif; ?>

<?php if ($asetusViesti): ?>
    <p class="notice"><?= htmlspecialchars($asetusViesti) ?></p>
<?php endif; ?>

<?php if ($getViesti !== ''): ?>
    <p class="notice<?= $getViestiVirhe ? ' notice-varoitus' : '' ?>"><?= htmlspecialchars($getViesti) ?></p>
<?php endif; ?>

<?php if ($hasLayout && $kilpailu && $tilastot): ?>
    <?php
    $linkit = julkinenLinkit($kilpailu['id']);
    $pirila = function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($kilpailu);
    $iltarasti = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu);
    $vk = static function (string $osio): bool {
        return !function_exists('tiliNaytaKisaValikko') || tiliNaytaKisaValikko($osio);
    };
    $vkKaikki = $vk('kaikki');
    $vkAjanotto = $vk('ajanotto');
    $vkLahto = $vk('lahtopaikka');
    $vkIlmo = $vk('ilmoittautuminen');
    if (is_readable(__DIR__ . '/../includes/kisa_luonne.php')) {
        require_once __DIR__ . '/../includes/kisa_luonne.php';
        if ($pdo instanceof PDO) {
            kisaLuonneVarmista($pdo);
        }
    }
    if (is_readable(__DIR__ . '/../includes/sarja_numerot.php')) {
        require_once __DIR__ . '/../includes/sarja_numerot.php';
    }
    ?>
    <?php if ($pirila && function_exists('pirilaTilaIlmoitus')): ?>
        <?php pirilaTilaIlmoitus($kilpailu); ?>
    <?php endif; ?>

    <div class="public-card hallinta-kisa-kortti">
        <div class="hallinta-kisa-ylapalkki">
            <div>
                <h2><?= htmlspecialchars($kilpailu['nimi']) ?></h2>
                <p>
                    <span class="badge status-<?= htmlspecialchars((string) $kilpailu['tila']) ?>">
                        <?= htmlspecialchars(function_exists('kilpailuTilaLabel') ? kilpailuTilaLabel($kilpailu['tila']) : (string) $kilpailu['tila']) ?>
                    </span>
                    <?php if ($kilpailu['paiva']): ?> · <?= htmlspecialchars(function_exists('formatPaiva') ? formatPaiva($kilpailu['paiva']) : (string) $kilpailu['paiva']) ?><?php endif; ?>
                    <?php if ($kilpailu['paikka']): ?> · <?= htmlspecialchars($kilpailu['paikka']) ?><?php endif; ?>
                </p>
                <p class="muted">
                    <?= $tilastot['sarjat'] ?> <?= $iltarasti ? 'rataa' : 'sarjaa' ?> ·
                    <?= $tilastot['kilpailijat'] ?> <?= $iltarasti ? 'henkilöä' : 'kilpailijaa' ?> ·
                    <?= $tilastot['maalit'] ?> maalia
                </p>
            </div>
            <div class="hallinta-kisa-toiminnot">
                <?php if ($vkKaikki): ?>
                <a class="btn-small btn-secondary kisa-btn-muokkaa"
                   href="<?= $iltarasti ? 'iltarastit.php' : ('kilpailut.php?muokkaa=' . (int) $kilpailu['id']) ?>"><?= $iltarasti ? 'Iltarastit' : 'Muokkaa kilpailua' ?></a>
                <?php endif; ?>
                <form method="post" class="inline-form">
                    <?php if (function_exists('csrfKentta')): ?><?= csrfKentta() ?><?php endif; ?>
                    <input type="hidden" name="toiminto" value="vaihda_kilpailu">
                    <button type="submit" class="btn-small btn-secondary kisa-btn-muokkaa">Vaihda kilpailua</button>
                </form>
            </div>
        </div>

        <div class="hallinta-kisa-ryhmittely" aria-label="Kilpailun toiminnot">
            <?php if ($vkKaikki || $vkIlmo): ?>
            <section class="hallinta-kisa-ryhma">
                <h3 class="kisa-hallinta-alinkki-otsikko">Valmistelu</h3>
                <div class="hallinta-kisa-rivit">
                    <?php if ($vkKaikki && !$iltarasti): ?>
                    <div class="public-links">
                        <a href="sarjat.php"><?= $pirila ? 'Sarjat (Pirila)' : 'Sarjat' ?></a>
                    </div>
                    <div class="public-links">
                        <a href="lahdot.php"><?= $pirila ? 'Lähdöt (Pirila)' : 'Lähdöt' ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($vkKaikki): ?>
                    <div class="public-links">
                        <a href="kilpailijat.php"><?= $iltarasti ? 'Henkilöt' : ($pirila ? 'Kilpailijat (Pirila)' : 'Kilpailijat') ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if (!$iltarasti && !$pirila && !empty($kilpailu['paikanpaa_ilmo'])): ?>
                    <?php if ($vkKaikki): ?>
                    <div class="public-links">
                        <a href="kioski_qr.php?kilpailu_id=<?= (int) $kilpailu['id'] ?>">QR-koodi</a>
                    </div>
                    <?php endif; ?>
                    <?php if ($vkIlmo): ?>
                    <?php
                    $ilmoHref = ($pdo instanceof PDO && function_exists('paikanpaaIlmoHref'))
                        ? paikanpaaIlmoHref($pdo, $kilpailu)
                        : '';
                    ?>
                    <?php if ($ilmoHref !== ''): ?>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($ilmoHref) ?>">Ilmo paikan päällä</a>
                    </div>
                    <?php endif; ?>
                    <div class="public-links">
                        <a href="paikanpaa.php?kilpailu_id=<?= (int) $kilpailu['id'] ?>">Numeroiden jako</a>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($vkKaikki && ($pirila || !function_exists('kisaNaytaValikko') || kisaNaytaValikko($kilpailu, 'ratatiedot'))): ?>
                    <div class="public-links">
                        <a href="radat.php"><?= $pirila ? 'Radat (Pirila)' : 'Radat' ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($vkKaikki && !$iltarasti && !$pirila): ?>
                    <div class="public-links">
                        <a href="arvonta.php">Arvonta</a>
                    </div>
                    <?php endif; ?>
                    <?php if ($vkKaikki && $pirila): ?>
                    <div class="public-links">
                        <a href="pirila.php">Pirilä-asetukset</a>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($vkAjanotto || $vkLahto || $vkKaikki): ?>
            <section class="hallinta-kisa-ryhma">
                <h3 class="kisa-hallinta-alinkki-otsikko">Kilpailutoiminnat</h3>
                <?php
                $nAjanotto = !function_exists('kisaNaytaValikko') || kisaNaytaValikko($kilpailu, 'ajanotto');
                $nEmit = !function_exists('kisaNaytaValikko') || kisaNaytaValikko($kilpailu, 'emit');
                $naytaAjanottoLinkit = $vkAjanotto && !$pirila && $nAjanotto;
                $naytaJaettuNumerot = $vkAjanotto && $pirila && !empty($kilpailu['jaettu_jono']);
                ?>
                <?php if ($naytaAjanottoLinkit || $naytaJaettuNumerot || ($vkAjanotto && !$iltarasti)): ?>
                <?php if ($naytaAjanottoLinkit): ?>
                <div class="hallinta-kisa-alaryhma">
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['mobiili']) ?>">Mobiiliajanotto</a>
                    </div>
                </div>
                <div class="hallinta-kisa-alaryhma hallinta-maalinappi-pari">
                    <h4 class="kisa-hallinta-alinkki-otsikko">Maalinappi + Numerot</h4>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['nappi']) ?>">Maalinappi</a>
                        <a href="<?= htmlspecialchars($linkit['numerot']) ?>">Numerot</a>
                    </div>
                </div>
                <?php elseif ($naytaJaettuNumerot): ?>
                <div class="hallinta-kisa-alaryhma">
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['numerot']) ?>">Numerot</a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($vkAjanotto && !$iltarasti): ?>
                <div class="hallinta-kisa-alaryhma">
                    <div class="public-links hallinta-kisa-loki-rivi">
                        <a href="ajanotto_loki.php">Ajanotto-loki</a>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php
                $naytaAmpuma = $vkKaikki && function_exists('kisaOnAmpumapaikat') && kisaOnAmpumapaikat($kilpailu);
                ?>
                <?php if (($nEmit && ($vkLahto || $vkKaikki)) || $naytaAmpuma): ?>
                <div class="public-links">
                    <?php if ($nEmit && $vkLahto): ?>
                        <?php $onViestiHallinta = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu); ?>
                        <a href="<?= htmlspecialchars($onViestiHallinta ? $linkit['viesti_lukija'] : $linkit['emit_lukija']) ?>" target="_blank" rel="noopener"><?= $onViestiHallinta ? 'Viesti-lukija' : 'Emit-lukija' ?></a>
                    <?php endif; ?>
                    <?php if ($nEmit && $vkKaikki): ?>
                        <a href="../public/mobiili_kilpailija.php?kilpailu_id=<?= (int) $kilpailu['id'] ?>" target="_blank" rel="noopener">Muokkaa kilpailijaa</a>
                    <?php endif; ?>
                    <?php if ($naytaAmpuma): ?>
                        <a href="<?= htmlspecialchars($linkit['ampuma']) ?>" target="_blank" rel="noopener">Ampumapaikka</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($vkLahto && !$iltarasti): ?>
                <div class="hallinta-kisa-alaryhma">
                    <h4 class="kisa-hallinta-alinkki-otsikko">Lähtö</h4>
                    <div class="public-links">
                        <?php if ($pirila || (function_exists('kisaNaytaValikko') && kisaNaytaValikko($kilpailu, 'emit'))): ?>
                            <a href="<?= htmlspecialchars($linkit['lahtoluennat']) ?>" target="_blank" rel="noopener">Lähtöluenta</a>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($linkit['nimenhuuto']) ?>">Nimenhuuto</a>
                        <a href="<?= htmlspecialchars($linkit['lahtokello']) ?>">Lähtökello</a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($vkKaikki): ?>
                <div class="hallinta-kisa-alaryhma">
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['tv']) ?>" target="_blank" rel="noopener">Tulostaulu</a>
                    </div>
                </div>
                <?php if (!$iltarasti): ?>
                <div class="hallinta-kisa-alaryhma">
                    <div class="public-links">
                        <a href="tulokset_vienti.php">Tulosten vienti</a>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($vkKaikki): ?>
            <section class="hallinta-kisa-ryhma">
                <h3 class="kisa-hallinta-alinkki-otsikko">Julkinen</h3>
                <div class="hallinta-kisa-rivit">
                    <?php if (!$iltarasti): ?>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['kilpailu']) ?>" target="_blank" rel="noopener">Aloitussivu</a>
                    </div>
                    <?php endif; ?>
                    <?php if (!$pirila && !$iltarasti): ?>
                        <?php if (function_exists('naytaIlmoittautuminenValikossa') && naytaIlmoittautuminenValikossa($kilpailu)): ?>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['ilmoittautuminen']) ?>" target="_blank" rel="noopener">Ilmoittautuminen</a>
                    </div>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['ilmoittautuneet']) ?>" target="_blank" rel="noopener">Ilmoittautuneet</a>
                    </div>
                        <?php else: ?>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['ilmoittautuneet']) ?>" target="_blank" rel="noopener">Ilmoittautuneet</a>
                    </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['lahtolista']) ?>" target="_blank" rel="noopener">Lähtölista</a>
                    </div>
                    <div class="public-links">
                        <a href="<?= htmlspecialchars($linkit['seuranta']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(seurantaOtsikko($kilpailu)) ?></a>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <?php
        $checklist = null;
        if ($vkKaikki && is_readable(__DIR__ . '/../includes/kisapaiva_checklist.php')) {
            require_once __DIR__ . '/../includes/kisapaiva_checklist.php';
            $checklist = kisapaivaChecklist($pdo, $kilpailu);
        }
        ?>
        <?php if ($checklist): ?>
            <div class="kisapaiva-checklist<?= !empty($checklist['valmis_lahtoon']) ? ' is-ok' : ' is-varoitus' ?>">
                <h3 class="kisa-hallinta-alinkki-otsikko">
                    Kisapäivän checklist
                    <?php if (!empty($checklist['valmis_lahtoon'])): ?>
                        <span class="badge badge-valmis-lahtoon">Valmis lähtöön</span>
                    <?php elseif (!empty($checklist['ok'])): ?>
                        <span class="badge">Valmis</span>
                    <?php else: ?>
                        <span class="badge">Tarkista</span>
                    <?php endif; ?>
                </h3>
                <?php
                $checklistJako = function_exists('kisapaivaChecklistJaaKohdat')
                    ? kisapaivaChecklistJaaKohdat($checklist)
                    : ['huomio' => $checklist['kohdat'], 'valmiit' => []];
                ?>
                <?php if ($checklistJako['huomio']): ?>
                    <ul class="checklist-lista">
                        <?php foreach ($checklistJako['huomio'] as $kohta): ?>
                            <?php kisapaivaChecklistKohtaHtml($kohta); ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($checklistJako['valmiit']): ?>
                    <details class="checklist-valmiit">
                        <summary>Valmiit</summary>
                        <ul class="checklist-lista">
                            <?php foreach ($checklistJako['valmiit'] as $kohta): ?>
                                <?php kisapaivaChecklistKohtaHtml($kohta); ?>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <?php if (!$pirila): ?>
                    <form method="post" class="inline-form" style="margin-top:0.75rem"
                          onsubmit="return confirm('Tyhjennetäänkö maalinappi-jonon leimat ja numerot?\n\nJos rastitat \"Poista myös kaikki maaliajat\", kaikki maaliajat poistuvat.');">
                        <?php if (function_exists('csrfKentta')): ?><?= csrfKentta() ?><?php endif; ?>
                        <input type="hidden" name="toiminto" value="tyhjenna_testidata">
                        <input type="hidden" name="kilpailu_id" value="<?= (int) $kilpailu['id'] ?>">
                        <label class="checkbox-label" style="display:inline-flex;align-items:center;gap:0.35rem;margin-right:0.75rem">
                            <input type="checkbox" name="myos_ajat" value="1">
                            Poista myös kaikki maaliajat
                        </label>
                        <button type="submit" class="btn-small btn-secondary">Tyhjennä testidata</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($vkKaikki && !$pirila): ?>
            <form method="post" class="inline-form" style="margin-top:0.75rem"
                  onsubmit="return confirm('Tyhjennetäänkö maalinappi-jonon leimat ja numerot?\n\nJos rastitat \"Poista myös kaikki maaliajat\", kaikki maaliajat poistuvat.');">
                <?php if (function_exists('csrfKentta')): ?><?= csrfKentta() ?><?php endif; ?>
                <input type="hidden" name="toiminto" value="tyhjenna_testidata">
                <input type="hidden" name="kilpailu_id" value="<?= (int) $kilpailu['id'] ?>">
                <label class="checkbox-label" style="display:inline-flex;align-items:center;gap:0.35rem;margin-right:0.75rem">
                    <input type="checkbox" name="myos_ajat" value="1">
                    Poista myös kaikki maaliajat
                </label>
                <button type="submit" class="btn-small btn-secondary">Tyhjennä testidata</button>
            </form>
        <?php endif; ?>

        <?php if ($vkAjanotto): ?>
        <div class="ajanotto-live-jono-paneeli" id="liveJonoPaneeli"
             data-kilpailu-id="<?= (int) $kilpailu['id'] ?>">
            <h3 class="kisa-hallinta-alinkki-otsikko">Live-jono <span class="muted" id="liveJonoMeta"></span></h3>
            <div id="liveJonoSisalto" class="muted">Ladataan…</div>
        </div>
        <?php endif; ?>

        <?php
        if (is_readable(__DIR__ . '/../includes/ajanotto_laitteet.php')) {
            require_once __DIR__ . '/../includes/ajanotto_laitteet.php';
            $laitteet = ajanottoLaitteetHae($pdo, (int) $kilpailu['id'], 180);
        } else {
            $laitteet = [];
        }
        $offlineJonossa = array_filter($laitteet, static fn($l) => (int) ($l['jono_lkm'] ?? 0) > 0);

        $tvToken = '';
        if (is_readable(__DIR__ . '/../includes/tv_profiilit.php')) {
            require_once __DIR__ . '/../includes/tv_profiilit.php';
            $tvToken = tvVarmistaToken($pdo, $kilpailu);
        }
        $tvKioskUrl = '../public/tv.php?kilpailu_id=' . (int) $kilpailu['id']
            . ($tvToken !== '' ? ('&tv_token=' . rawurlencode($tvToken)) : '');
        ?>
        <?php if ($vkAjanotto): ?>
        <div class="ajanotto-laitteet-paneeli">
            <h3 class="kisa-hallinta-alinkki-otsikko">
                Ajanottolaitteet
                <?php if ($offlineJonossa !== []): ?>
                    <span class="badge">Offline-jono</span>
                <?php endif; ?>
            </h3>
            <?php if ($laitteet === []): ?>
                <p class="muted">Ei aktiivisia laitteita (viimeiset 3 h). Laitteet ilmoittavat itsensä ajanotonäkymästä.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nimi</th>
                                <th>Tila</th>
                                <th>Offline-jono</th>
                                <th>Verkko</th>
                                <th>Viimeksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($laitteet as $l): ?>
                                <tr class="<?= (int) ($l['jono_lkm'] ?? 0) > 0 ? 'is-offline-jono' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars((string) ($l['nimi'] ?: 'Nimetön')) ?></strong>
                                        <div class="muted"><code><?= htmlspecialchars((string) $l['laite_id']) ?></code></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($l['tila'] ?: '—')) ?></td>
                                    <td>
                                        <?php $jl = (int) ($l['jono_lkm'] ?? 0); ?>
                                        <?= $jl > 0 ? ('<strong>' . $jl . '</strong> odottaa') : '0' ?>
                                    </td>
                                    <td><?= !empty($l['online_tila']) ? 'Online' : 'Offline' ?></td>
                                    <td><?= htmlspecialchars(substr((string) $l['viimeksi'], 0, 19)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="muted">Offline-jonon rivit lähtevät automaattisesti, kun laite palaa verkkoon.</p>
            <?php endif; ?>
            <p>
                <a class="btn-secondary" href="ajanotto_loki.php">Avaa ajanotto-loki</a>
                <?php if ($vkKaikki && $tvToken !== ''): ?>
                    · <a href="<?= htmlspecialchars($tvKioskUrl) ?>" target="_blank" rel="noopener">TV-kiosk (token)</a>
                <?php endif; ?>
            </p>
            <?php if ($vkKaikki): ?>
            <form method="post" class="inline-form" style="margin-top:0.5rem">
                <?php if (function_exists('csrfKentta')): ?><?= csrfKentta() ?><?php endif; ?>
                <input type="hidden" name="toiminto" value="luo_tv_token">
                <input type="hidden" name="kilpailu_id" value="<?= (int) $kilpailu['id'] ?>">
                <button type="submit" class="btn-small btn-secondary">Uusi TV-kiosk token</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <script>
        (function () {
            var paneeli = document.getElementById('liveJonoPaneeli');
            if (!paneeli) return;
            var kid = paneeli.getAttribute('data-kilpailu-id');
            var sisalto = document.getElementById('liveJonoSisalto');
            var meta = document.getElementById('liveJonoMeta');
            function esc(s) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }
            function kello(a) {
                if (!a) return '—';
                var m = String(a).match(/(\d{2}:\d{2}:\d{2})/);
                return m ? m[1] : a;
            }
            async function hae() {
                try {
                    var r = await fetch('../api/api_admin_jono.php?kilpailu_id=' + encodeURIComponent(kid), {
                        credentials: 'same-origin', cache: 'no-store'
                    });
                    var d = await r.json();
                    if (d.status !== 'ok') {
                        sisalto.textContent = d.message || 'Virhe';
                        return;
                    }
                    if (meta) {
                        meta.textContent = (d.leimat_lkm || 0) + ' leimaa · '
                            + (d.numerot_lkm || 0) + ' nroa · '
                            + (d.yhdistetty_lkm || 0) + ' yhdistetty';
                    }
                    var rivit = (d.rivit || []).slice().reverse().slice(0, 25);
                    if (!rivit.length) {
                        sisalto.innerHTML = '<p class="muted">Ei vielä rivejä jonossa.</p>';
                        return;
                    }
                    sisalto.innerHTML = '<ul class="live-jono-lista">' + rivit.map(function (row) {
                        var cls = 'live-jono-' + esc(row.tila || '');
                        return '<li class="' + cls + '">'
                            + '<strong>#' + esc(row.numero || '—') + '</strong> '
                            + esc(row.nimi || '') + ' · '
                            + esc(kello(row.maaliaika)) + ' · '
                            + '<em>' + esc(row.viesti || row.tila || '') + '</em>'
                            + '</li>';
                    }).join('') + '</ul>';
                } catch (e) {
                    sisalto.textContent = 'Yhteysvirhe';
                }
            }
            hae();
            setInterval(hae, 2500);
        })();
        </script>
    </div>

<?php elseif ($hasLayout): ?>
    <section class="kisa-lista-osio">
        <div class="kisa-lista-header">
            <h2><?= onAdmin() ? 'Kaikki kilpailut' : 'Omat kilpailut' ?></h2>
            <p class="muted">Valitse kilpailu jatkaaksesi. Valikot avautuvat oikeuksiesi mukaan.</p>
            <?php if ($kisaAikavali && function_exists('kilpailuAikavaliLomake')): ?>
                <?php kilpailuAikavaliLomake('index.php', $kisaAikavali); ?>
            <?php endif; ?>
        </div>

        <?php if ($omatKisat === []): ?>
            <p class="home-empty muted">
                <?= $kisaAikavali ? 'Ei kilpailuja tällä aikavälillä.' : 'Ei vielä kilpailuja.' ?>
                <a href="kilpailut.php?uusi=1">Luo kilpailu</a><?php if ($kisaAikavali): ?>
                    · <a href="index.php?kaikki=1">Näytä kaikki</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="kisa-lista">
                <?php foreach ($omatKisat as $k): ?>
                    <?php
                    $kid = (int) $k['id'];
                    $tila = (string) ($k['tila'] ?? '');
                    ?>
                    <article class="kisa-lista-kortti">
                        <div class="kisa-lista-info">
                            <div class="kisa-lista-otsikko">
                                <h3><?= htmlspecialchars((string) $k['nimi']) ?></h3>
                            </div>
                            <p class="kisa-lista-meta muted">
                                <?php if (!empty($k['seura_nimi'])): ?>
                                    <?= htmlspecialchars((string) $k['seura_nimi']) ?>
                                <?php endif; ?>
                                <?php if (!empty($k['seura_nimi']) && ($k['paiva'] || $k['paikka'])): ?> · <?php endif; ?>
                                <?php if (!empty($k['paiva'])): ?>
                                    <?= htmlspecialchars(formatPaiva($k['paiva'])) ?>
                                <?php endif; ?>
                                <?php if (!empty($k['paiva']) && !empty($k['paikka'])): ?> · <?php endif; ?>
                                <?php if (!empty($k['paikka'])): ?>
                                    <?= htmlspecialchars((string) $k['paikka']) ?>
                                <?php endif; ?>
                            </p>
                            <div class="kisa-lista-badges">
                                <span class="badge status-<?= htmlspecialchars($tila) ?>">
                                    <?= htmlspecialchars(kilpailuTilaLabel($tila)) ?>
                                </span>
                                <?php if (function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($k)): ?>
                                    <span class="badge badge-muted">Pirilä-synkka</span>
                                <?php elseif (function_exists('kisaLuonneLabel') && kisaLuonneLabel($k['luonne'] ?? null) !== ''): ?>
                                    <span class="badge badge-muted"><?= htmlspecialchars(kisaLuonneLabel($k['luonne'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="kisa-lista-toiminnot">
                            <form method="post" class="inline-form">
                                <?php if (function_exists('csrfKentta')): ?><?= csrfKentta() ?><?php endif; ?>
                                <input type="hidden" name="toiminto" value="valitse_kilpailu">
                                <input type="hidden" name="kilpailu_id" value="<?= $kid ?>">
                                <button type="submit" class="btn-small kisa-btn-valitse">Valitse</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="muted" style="margin-top:1rem">
            <a href="kilpailut.php">Hallitse kilpailulistaa</a>
            <?php if ($voiGps && !empty($ominaisuudet['gps'])): ?>
                · <a href="../gps/hallinta.php">GPS-seuranta</a>
            <?php endif; ?>
        </p>
    </section>
<?php endif; ?>

<?php if (onAdmin()): ?>
<div class="public-card" style="margin-top:1.25rem">
    <h2>Rekisteröityminen</h2>
    <p class="muted">
        Julkinen <code>/auth/register.php</code> on nyt
        <strong><?= $rekisterointiAuki ? 'auki' : 'kiinni' ?></strong>.
    </p>
    <form method="post" class="form-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if (function_exists('csrfKentta')): ?><?= csrfKentta() ?><?php endif; ?>
        <input type="hidden" name="toiminto" value="rekisterointi">
        <?php if ($rekisterointiAuki): ?>
            <input type="hidden" name="rekisterointi_auki" value="0">
            <button type="submit" class="btn-danger">Sulje rekisteröityminen</button>
        <?php else: ?>
            <input type="hidden" name="rekisterointi_auki" value="1">
            <button type="submit">Avaa rekisteröityminen</button>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php if ($hasLayout && $kilpailu): ?>
<div class="public-card" style="margin-top:1rem">
    <h2>Muut työkalut</h2>
    <div class="public-links menu">
        <?php if ($voiGps && !empty($ominaisuudet['gps'])): ?>
            <a href="../gps/hallinta.php">GPS-seuranta</a>
        <?php endif; ?>
        <a href="oma_seura.php">Oma seura</a>
        <?php if (onAdmin()): ?>
            <a href="seurat.php">Seurojen hallinta</a>
            <a href="users.php">Käyttäjät</a>
        <?php endif; ?>
        <a href="kilpailut.php">Kilpailulista</a>
        <a href="../auth/logout.php">Kirjaudu ulos</a>
    </div>
</div>
<?php endif; ?>

<?php
if ($hasLayout) {
    pageEnd();
} else {
    echo '</div></body></html>';
}
