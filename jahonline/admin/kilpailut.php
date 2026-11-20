<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/acl.php';
if (is_readable(__DIR__ . '/../includes/kilpailu_poisto.php')) {
    require_once __DIR__ . '/../includes/kilpailu_poisto.php';
}
if (is_readable(__DIR__ . '/../includes/kilpailu_aikavali.php')) {
    require_once __DIR__ . '/../includes/kilpailu_aikavali.php';
}
require_once __DIR__ . '/../includes/tili.php';
require_once __DIR__ . '/../db.php';

vaadiKirjautuminen();
tiliVarmistaTaulut($pdo);
tiliLataaKayttajaSessioon($pdo);
vaadiKisahallinta();
require_once __DIR__ . '/../includes/kisa_liput.php';
kisaLiputVarmista($pdo);
require_once __DIR__ . '/../includes/kisa_luonne.php';
kisaLuonneVarmista($pdo);
if (is_readable(__DIR__ . '/../includes/kisa_osoite.php')) {
    require_once __DIR__ . '/../includes/kisa_osoite.php';
}
require_once __DIR__ . '/../includes/maksu.php';
maksuVarmista($pdo);
tiliVaadiTulospalvelu($pdo);
kilpailuVarmistaTilaEnum($pdo);
kilpailuSynkkaaTilaIlmosta($pdo);

$valitse = isset($_GET['valitse']);
$valittu_id = valittuKilpailuId();
$omaSeuraId = (int) ($_SESSION['seura_id'] ?? 0);

/**
 * Sallitut seurat kilpailulomakkeelle.
 * @return list<array{id:int,nimi:string}>
 */
function kilpailuSeuraValinnat(PDO $pdo): array
{
    if (onAdmin()) {
        return $pdo->query("
            SELECT id, nimi FROM seurat
            WHERE tila = 'aktiivinen'
            ORDER BY nimi
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $out = [];
    $oma = tiliHaeSeuraKayttajalle($pdo, (int) ($_SESSION['user_id'] ?? 0));
    if ($oma) {
        $out[] = ['id' => (int) $oma['id'], 'nimi' => (string) $oma['nimi']];
    }

    return $out;
}

/**
 * Päättele seura_id lomakkeesta (admin voi valita, muut → oma seura).
 */
function kilpailuParsiseuraId(PDO $pdo, ?array $olemassa = null): ?int
{
    $valinnat = kilpailuSeuraValinnat($pdo);
    $sallitut = array_map(static fn($s) => (int) $s['id'], $valinnat);

    if (onAdmin() && array_key_exists('seura_id', $_POST)) {
        $raw = trim((string) ($_POST['seura_id'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $id = (int) $raw;
        return in_array($id, $sallitut, true) ? $id : null;
    }

    if ($olemassa !== null && !empty($olemassa['seura_id'])) {
        return (int) $olemassa['seura_id'];
    }

    $sess = (int) ($_SESSION['seura_id'] ?? 0);
    if ($sess > 0 && in_array($sess, $sallitut, true)) {
        return $sess;
    }
    if ($sallitut !== []) {
        return $sallitut[0];
    }

    return null;
}

/**
 * Ilmoittautumisasetukset lomakkeesta (juoksukisa ja Pirilä-kisa).
 *
 * @return array{auki:int,alkaa:?string,paattyy:?string,maksu:?float,ohje:?string}
 */
function kilpailuParseIlmoAsetukset(string $kisaTyyppi): array
{
    $auki = !empty($_POST['ilmoittautuminen_auki']) ? 1 : 0;

    $parseDt = static function (string $raw): ?string {
        $raw = trim(str_replace('T', ' ', $raw));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    };

    $maksuRaw = trim((string) ($_POST['ilmo_maksu_eur'] ?? ''));
    $maksu = $maksuRaw === '' ? null : (float) str_replace(',', '.', $maksuRaw);
    if ($maksu !== null && $maksu <= 0) {
        $maksu = null;
    }

    $ohje = trim((string) ($_POST['ilmo_maksuohje'] ?? ''));
    $saaja = trim((string) ($_POST['ilmo_saaja'] ?? ''));
    $ibanRaaka = trim((string) ($_POST['ilmo_iban'] ?? ''));
    $iban = $ibanRaaka === '' ? null : normalisoiIban($ibanRaaka);

    return [
        'auki' => $auki,
        'alkaa' => $parseDt((string) ($_POST['ilmo_alkaa'] ?? '')),
        'paattyy' => $parseDt((string) ($_POST['ilmo_paattyy'] ?? '')),
        'maksu' => $maksu,
        'ohje' => $ohje !== '' ? $ohje : null,
        'saaja' => $saaja !== '' ? mb_substr($saaja, 0, 140) : null,
        'iban' => $iban,
        'iban_virhe' => $ibanRaaka !== '' && $iban === null,
    ];
}

function kilpailuIlmoDatetimeLocal(?string $db): string
{
    if ($db === null || $db === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($db, 0, 16));
}

/**
 * Pirilä-synkka lomakkeesta. Uusi kisa ei muutu Piriläksi tältä sivulta
 * (luonti Omissa tapahtumissa). Olemassa oleva ei-Pirilä säilyy ennallaan.
 *
 * @return array{pirila_synkka:int,kisa_tyyppi:string}
 */
function kilpailuParsePirilaKentat(?array $olemassa): array
{
    $onPirila = is_array($olemassa)
        && (
            (function_exists('kisaOnPirila') && kisaOnPirila($olemassa))
            || (function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($olemassa))
            || (($olemassa['kisa_tyyppi'] ?? '') === 'pirila')
            || !empty($olemassa['pirila_synkka'])
        );
    if (!$onPirila) {
        return ['pirila_synkka' => 0, 'kisa_tyyppi' => 'normaali'];
    }
    if (function_exists('kilpailuParsePirilaSynkka')) {
        return kilpailuParsePirilaSynkka();
    }
    $synkka = !empty($_POST['pirila_synkka']) ? 1 : 0;

    return [
        'pirila_synkka' => $synkka,
        'kisa_tyyppi' => $synkka ? 'pirila' : 'normaali',
    ];
}

/**
 * Yksi kilpailurivi hallinnan listassa.
 *
 * @param array<string,mixed> $k
 * @param array<int,string> $seuraNimet
 */
function kilpailuListaKortti(array $k, int $valittu_id, array $seuraNimet): void
{
    $kid = (int) $k['id'];
    $valittu = $valittu_id === $kid;
    $sid = (int) ($k['seura_id'] ?? 0);
    $seuraNimi = $sid > 0 ? ($seuraNimet[$sid] ?? '—') : '—';
    $tila = (string) ($k['tila'] ?? '');
    ?>
    <article class="kisa-lista-kortti<?= $valittu ? ' is-selected' : '' ?>">
        <div class="kisa-lista-info">
            <div class="kisa-lista-otsikko">
                <h3><?= htmlspecialchars((string) $k['nimi']) ?></h3>
                <?php if ($valittu): ?>
                    <span class="kisa-lista-valittu">Valittu</span>
                <?php endif; ?>
            </div>
            <p class="kisa-lista-meta muted">
                <?php if (!empty($k['paiva'])): ?>
                    <?= htmlspecialchars(formatPaiva($k['paiva'])) ?>
                <?php else: ?>
                    Ei päivää
                <?php endif; ?>
                <?php if (!empty($k['paikka'])): ?>
                    · <?= htmlspecialchars((string) $k['paikka']) ?>
                <?php endif; ?>
                <?php if ($seuraNimi !== '—'): ?>
                    · <?= htmlspecialchars($seuraNimi) ?>
                <?php endif; ?>
            </p>
            <div class="kisa-lista-badges">
                <span class="badge status-<?= htmlspecialchars($tila) ?>">
                    <?= htmlspecialchars(kilpailuTilaLabel($tila)) ?>
                </span>
                <?php if (function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($k)): ?>
                    <span class="badge badge-muted">Pirilä-synkka</span>
                <?php endif; ?>
                <?php if (kisaLuonneLabel($k['luonne'] ?? null) !== ''): ?>
                    <span class="badge badge-muted"><?= htmlspecialchars(kisaLuonneLabel($k['luonne'])) ?></span>
                <?php endif; ?>
                <?php if (function_exists('demoKisaBadgeHtml') && !empty($k['testikisa'])): ?>
                    <?= demoKisaBadgeHtml($k) ?>
                    <span class="badge badge-muted">Ei julkinen</span>
                <?php elseif (!empty($k['testikisa'])): ?>
                    <span class="badge badge-demo">Demo</span>
                    <span class="badge badge-muted">Ei julkinen</span>
                <?php endif; ?>
                <?php if (!empty($k['jaettu_jono'])): ?>
                    <span class="badge badge-muted">Jaettu lista</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="kisa-lista-toiminnot" role="group" aria-label="Kilpailun toiminnot">
            <?php if ($tila !== 'arkisto'): ?>
                <?php if ($valittu): ?>
                    <a class="btn-small kisa-btn-valitse is-current" href="index.php">Avaa hallinta</a>
                <?php else: ?>
                    <form method="post" class="inline-form">
                        <?= csrfKentta() ?>
                        <input type="hidden" name="action" value="valitse_kilpailu">
                        <input type="hidden" name="kilpailu_id" value="<?= $kid ?>">
                        <input type="hidden" name="redirect" value="index.php">
                        <button type="submit" class="btn-small kisa-btn-valitse">Valitse</button>
                    </form>
                <?php endif; ?>
                <a class="btn-small btn-secondary kisa-btn-muokkaa" href="kilpailut.php?muokkaa=<?= $kid ?>">Muokkaa</a>
                <form method="post" class="inline-form">
                    <?= csrfKentta() ?>
                    <input type="hidden" name="action" value="arkistoi">
                    <input type="hidden" name="id" value="<?= $kid ?>">
                    <button type="submit" class="btn-small btn-secondary" onclick="return confirm('Arkistoidaanko kilpailu?\n\n<?= htmlspecialchars((string) $k['nimi'], ENT_QUOTES) ?>\n\nSe ei näy enää julkisilla listoilla.');">Arkistoi</button>
                </form>
            <?php else: ?>
                <form method="post" class="inline-form">
                    <?= csrfKentta() ?>
                    <input type="hidden" name="action" value="palauta_arkisto">
                    <input type="hidden" name="id" value="<?= $kid ?>">
                    <button type="submit" class="btn-small kisa-btn-valitse">Palauta listalle</button>
                </form>
                <a class="btn-small btn-secondary kisa-btn-muokkaa" href="kilpailut.php?muokkaa=<?= $kid ?>">Muokkaa</a>
            <?php endif; ?>
            <?php if (function_exists('kilpailuPoistoLomakeHtml')): ?>
                <?= kilpailuPoistoLomakeHtml($k, [
                    'action_name' => 'action',
                    'action_value' => 'delete',
                    'id_name' => 'id',
                    'kohde' => (function_exists('kisaOnIltarasti') && kisaOnIltarasti($k)) ? 'tapahtuman' : 'kilpailun',
                    'btn_class' => 'btn-danger btn-small kisa-btn-poista',
                ]) ?>
            <?php else: ?>
            <form method="post" class="inline-form" data-poista-kisa data-kisa-nimi="<?= htmlspecialchars((string) $k['nimi'], ENT_QUOTES, 'UTF-8') ?>">
                <?= csrfKentta() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $kid ?>">
                <input type="hidden" name="vahvista_nimi" value="">
                <button type="submit" class="btn-danger btn-small kisa-btn-poista">Poista</button>
            </form>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'valitse_kilpailu') {
        $kid = (int) ($_POST['kilpailu_id'] ?? 0);
        $k = haeKilpailuHallintaan($pdo, $kid);
        if (!$k) {
            die('Ei käyttöoikeutta');
        }
        asetaValittuKilpailu($kid);
        header('Location: ' . ($_POST['redirect'] ?? 'index.php'));
        exit;
    }

    if ($action === 'create') {

        $token = generoiApiToken();
        $pirilaKentat = kilpailuParsePirilaKentat(null);
        $kisa_tyyppi = $pirilaKentat['kisa_tyyppi'];
        $seuraId = kilpailuParsiseuraId($pdo, null);
        $ownerId = (int) paakayttajaId();
        $ilmo = kilpailuParseIlmoAsetukset($kisa_tyyppi);
        $tila = kilpailuTilaIlmoAsetuksista(
            (string) ($_POST['tila'] ?? 'luonnos'),
            $ilmo,
            $kisa_tyyppi
        );

        $stmt = $pdo->prepare('
            INSERT INTO kilpailut
                (nimi, paiva, paikka, tila, kisa_tyyppi, api_token, pirila_synkka, paakayttaja_id, seura_id,
                 ilmoittautuminen_auki, ilmo_alkaa, ilmo_paattyy, ilmo_maksu_eur, ilmo_maksuohje,
                 ilmo_saaja, ilmo_iban, testikisa, jaettu_jono, luonne, ampumapaikat)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $_POST['nimi'],
            $_POST['paiva'] ?: null,
            $_POST['paikka'],
            $tila,
            $kisa_tyyppi,
            $token,
            $pirilaKentat['pirila_synkka'],
            $ownerId > 0 ? $ownerId : null,
            $seuraId,
            $ilmo['auki'],
            $ilmo['alkaa'],
            $ilmo['paattyy'],
            $ilmo['maksu'],
            $ilmo['ohje'],
            $ilmo['saaja'],
            $ilmo['iban'],
            !empty($_POST['testikisa']) ? 1 : 0,
            !empty($_POST['jaettu_jono']) ? 1 : 0,
            kisaLuonneNormalisoi($_POST['luonne'] ?? null)
                ?? (function_exists('kisaLuonneOletusUudelle')
                    ? kisaLuonneOletusUudelle($kisa_tyyppi)
                    : 'juoksu'),
            !empty($_POST['ampumapaikat']) ? 1 : 0,
        ]);

        $uusiId = (int) $pdo->lastInsertId();
        asetaValittuKilpailu($uusiId);
        if (function_exists('kisaOsoiteTallenna')) {
            kisaOsoiteTallenna($pdo, $uusiId, (string) ($_POST['osoite'] ?? ''), null);
        }
        $virhe = !empty($ilmo['iban_virhe']) ? '&virhe=' . urlencode('Tilinumero ei ole kelvollinen IBAN.') : '';
        header('Location: kilpailut.php?muokkaa=' . $uusiId . $virhe);
        exit;
    }

    if ($action === 'update') {

        $id = (int) ($_POST['id'] ?? 0);
        $olemassa = haeKilpailuHallintaan($pdo, $id);
        if (!$olemassa) {
            die('Ei käyttöoikeutta');
        }

        $pirilaKentat = kilpailuParsePirilaKentat($olemassa);
        $kisa_tyyppi = $pirilaKentat['kisa_tyyppi'];
        $seuraId = kilpailuParsiseuraId($pdo, $olemassa);
        $ilmo = kilpailuParseIlmoAsetukset($kisa_tyyppi);
        $tila = kilpailuTilaIlmoAsetuksista(
            (string) ($_POST['tila'] ?? ($olemassa['tila'] ?? 'luonnos')),
            $ilmo,
            $kisa_tyyppi
        );

        $stmt = $pdo->prepare('
            UPDATE kilpailut
            SET nimi = ?, paiva = ?, paikka = ?, tila = ?, kisa_tyyppi = ?,
                pirila_synkka = ?,
                seura_id = ?,
                ilmoittautuminen_auki = ?,
                ilmo_alkaa = ?,
                ilmo_paattyy = ?,
                ilmo_maksu_eur = ?,
                ilmo_maksuohje = ?,
                ilmo_saaja = ?,
                ilmo_iban = ?,
                testikisa = ?,
                jaettu_jono = ?,
                luonne = ?,
                ampumapaikat = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $_POST['nimi'],
            $_POST['paiva'] ?: null,
            $_POST['paikka'],
            $tila,
            $kisa_tyyppi,
            $pirilaKentat['pirila_synkka'],
            $seuraId,
            $ilmo['auki'],
            $ilmo['alkaa'],
            $ilmo['paattyy'],
            $ilmo['maksu'],
            $ilmo['ohje'],
            $ilmo['saaja'],
            $ilmo['iban'],
            !empty($_POST['testikisa']) ? 1 : 0,
            !empty($_POST['jaettu_jono']) ? 1 : 0,
            kisaLuonneNormalisoi($_POST['luonne'] ?? null),
            !empty($_POST['ampumapaikat']) ? 1 : 0,
            $id,
        ]);

        if (function_exists('kisaOsoiteTallenna')) {
            kisaOsoiteTallenna($pdo, $id, (string) ($_POST['osoite'] ?? ''), $olemassa);
        }

        $virhe = !empty($ilmo['iban_virhe']) ? '&virhe=' . urlencode('Tilinumero ei ole kelvollinen IBAN.') : '';
        header('Location: kilpailut.php?muokkaa=' . $id . $virhe);
        exit;
    }

    if ($action === 'arkistoi') {
        $id = (int) ($_POST['id'] ?? 0);
        $k = haeKilpailuHallintaan($pdo, $id);
        if (!$k) {
            die('Ei käyttöoikeutta');
        }
        $stmt = $pdo->prepare("UPDATE kilpailut SET tila = 'arkisto' WHERE id = ?");
        $stmt->execute([$id]);
        if ($valittu_id === $id) {
            tyhjennaValittuKilpailu();
        }
        header('Location: /public/omat_tapahtumat.php?viesti=' . urlencode('Kilpailu arkistoitu.'));
        exit;
    }

    if ($action === 'palauta_arkisto') {
        $id = (int) ($_POST['id'] ?? 0);
        $k = haeKilpailuHallintaan($pdo, $id);
        if (!$k) {
            die('Ei käyttöoikeutta');
        }
        // Palauta Valmis-tilaan (turvallinen oletus arkistosta)
        $stmt = $pdo->prepare("UPDATE kilpailut SET tila = 'valmis' WHERE id = ? AND tila = 'arkisto'");
        $stmt->execute([$id]);
        header('Location: /public/omat_tapahtumat.php?viesti=' . urlencode('Kilpailu palautettu listalle.'));
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (function_exists('kilpailuPoistaHallinnasta')) {
            try {
                kilpailuPoistaHallinnasta($pdo, $id, $_POST);
            } catch (Throwable $e) {
                header('Location: /public/omat_tapahtumat.php?virhe=' . urlencode($e->getMessage()));
                exit;
            }
            header('Location: /public/omat_tapahtumat.php?viesti=' . urlencode('Kilpailu poistettu.'));
            exit;
        }
        $k = haeKilpailuHallintaan($pdo, $id);
        if (!$k) {
            die('Ei käyttöoikeutta');
        }

        $stmt = $pdo->prepare('DELETE FROM kilpailut WHERE id = ?');
        $stmt->execute([$id]);

        if ($valittu_id === $id) {
            tyhjennaValittuKilpailu();
        }

        header('Location: /public/omat_tapahtumat.php?viesti=' . urlencode('Kilpailu poistettu.'));
        exit;
    }

    if ($action === 'regenerate_token') {

        if (!onAdmin()) {
            die('Ei käyttöoikeutta');
        }

        $token = generoiApiToken();
        $stmt = $pdo->prepare('UPDATE kilpailut SET api_token = ? WHERE id = ?');
        $stmt->execute([$token, $_POST['id']]);

        header('Location: kilpailut.php?muokkaa=' . (int) $_POST['id']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['muokkaa'])) {
    $q = [];
    foreach (['nimi', 'paiva', 'paikka', 'viesti', 'virhe'] as $kentta) {
        $v = trim((string) ($_GET[$kentta] ?? ''));
        if ($v !== '') {
            $q[$kentta] = $v;
        }
    }
    if (isset($_GET['irma'])) {
        $q['irma'] = '1';
    }
    header('Location: /public/omat_tapahtumat.php' . ($q !== [] ? '?' . http_build_query($q) : ''));
    exit;
}

$seuraValinnat = kilpailuSeuraValinnat($pdo);
$kilpailut = [];
$seuraNimet = [];
$kisaAikavali = null;

$muokattava = null;
$irma_prefill = isset($_GET['irma']);

if (isset($_GET['muokkaa'])) {
    $muokattava = haeKilpailuHallintaan($pdo, (int) $_GET['muokkaa']);
    if (!$muokattava) {
        die('Ei käyttöoikeutta');
    }
} elseif ($irma_prefill) {
    $muokattava = [
        'nimi' => trim((string) ($_GET['nimi'] ?? '')),
        'paiva' => trim((string) ($_GET['paiva'] ?? '')),
        'paikka' => trim((string) ($_GET['paikka'] ?? '')),
        'tila' => 'luonnos',
        'seura_id' => $omaSeuraId > 0 ? $omaSeuraId : null,
    ];
}

$muokattavaSeuraId = (int) ($muokattava['seura_id'] ?? 0);
if ($muokattavaSeuraId <= 0 && !$muokattava) {
    $muokattavaSeuraId = $omaSeuraId;
}

$muokkaaTila = $muokattava && isset($muokattava['id']);
$uusiTila = !$muokkaaTila && (isset($_GET['uusi']) || $irma_prefill);

pageStart($uusiTila ? 'Uusi kilpailu' : 'Kilpailun asetukset', ['admin' => true, 'active' => 'omat_tapahtumat', 'pdo' => $pdo]);
?>

<p class="kisa-uusi-takaisin"><a href="/public/omat_tapahtumat.php">&larr; Omat tapahtumat</a></p>
<h1><?= $uusiTila ? 'Uusi kilpailu' : 'Kilpailun asetukset' ?></h1>


<?php pirilaVirheIlmoitus(); ?>

<?php if ($irma_prefill): ?>
    <p class="notice">Tiedot täytetty IRMA-kalenterista. Tarkista ja luo kilpailu.</p>
<?php endif; ?>

<?php if ($valitse && !$uusiTila): ?>
    <p class="notice">Valitse kilpailu listasta jatkaaksesi hallintaa. Julkiset työkalulinkit tulevat näkyviin hallinnan etusivulle valinnan jälkeen.</p>
<?php endif; ?>

<?php
ob_start();
?>
<section class="kisa-lista-osio">
    <div class="kisa-lista-header">
        <h2>Kilpailulista</h2>
        <p class="muted">Valitse kisa hallintaan, muokkaa, arkistoi tai poista. Arkistoidut eivät näy julkisilla listoilla.</p>
        <?php if ($kisaAikavali && function_exists('kilpailuAikavaliLomake')): ?>
            <?php kilpailuAikavaliLomake('kilpailut.php', $kisaAikavali, $pdo); ?>
        <?php endif; ?>
        <p><a class="btn" href="kilpailut.php?uusi=1">Lisää uusi kilpailu</a></p>
    </div>

    <?php
    $aktiivisetKisat = [];
    $arkistoKisat = [];
    foreach ($kilpailut as $k) {
        if (($k['tila'] ?? '') === 'arkisto') {
            $arkistoKisat[] = $k;
        } else {
            $aktiivisetKisat[] = $k;
        }
    }
    ?>

    <?php if ($aktiivisetKisat === [] && $arkistoKisat === []): ?>
        <p class="home-empty muted">Ei kilpailuja. <a href="kilpailut.php?uusi=1">Lisää ensimmäinen kilpailu</a>.</p>
    <?php else: ?>
        <?php if ($aktiivisetKisat !== []): ?>
            <div class="kisa-lista">
                <?php foreach ($aktiivisetKisat as $k): ?>
                    <?php kilpailuListaKortti($k, (int) $valittu_id, $seuraNimet); ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="home-empty muted">Ei aktiivisia kilpailuja. Arkistoidut alla.</p>
        <?php endif; ?>

        <?php if ($arkistoKisat !== []): ?>
            <details class="kisa-arkisto-osio" <?= isset($_GET['arkisto']) ? 'open' : '' ?>>
                <summary>Arkisto (<?= count($arkistoKisat) ?>)</summary>
                <p class="muted">Arkistoidut kisat eivät näy etusivulla eivätkä julkisissa listoissa.</p>
                <div class="kisa-lista">
                    <?php foreach ($arkistoKisat as $k): ?>
                        <?php kilpailuListaKortti($k, (int) $valittu_id, $seuraNimet); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
$listaHtml = ob_get_clean();

ob_start();
?>
<section class="kisa-lomake-osio<?= ($muokkaaTila || $uusiTila) ? ' is-editing' : '' ?>">
<?php if (!$uusiTila): ?>
<h2>Muokkaa kilpailua</h2>
<?php endif; ?>
<?php if (!empty($_GET['virhe'])): ?>
    <p class="error"><?= htmlspecialchars((string) $_GET['virhe']) ?></p>
<?php endif; ?>

<form method="post" class="form-grid">
    <?= csrfKentta() ?>
    <input type="hidden" name="action" value="<?= $muokkaaTila ? 'update' : 'create' ?>">
    <?php if ($muokkaaTila): ?>
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
    <?php endif; ?>

    <label>Nimi</label>
    <input type="text" name="nimi" required value="<?= htmlspecialchars($muokattava['nimi'] ?? '') ?>">

    <label>Päivä</label>
    <input type="date" name="paiva" value="<?= htmlspecialchars($muokattava['paiva'] ?? '') ?>">

    <label>Paikka</label>
    <input type="text" name="paikka" value="<?= htmlspecialchars($muokattava['paikka'] ?? '') ?>">
    <p class="muted form-hint">Paikan nimi listoissa, esim. urheilupuisto tai kaupunki.</p>

    <label>Osoite</label>
    <input type="text" name="osoite" maxlength="255" placeholder="Katuosoite, postitoimipaikka"
        value="<?= htmlspecialchars((string) ($muokattava['osoite'] ?? '')) ?>">
    <p class="muted form-hint">Katuosoite etusivun karttaa varten. Tapahtuma näkyy kartalla, kun osoite löytyy.</p>

    <label>Seura</label>
    <?php if (onAdmin()): ?>
        <select name="seura_id">
            <option value="">— Ei seuraa —</option>
            <?php foreach ($seuraValinnat as $s): ?>
                <option
                    value="<?= (int) $s['id'] ?>"
                    <?= $muokattavaSeuraId === (int) $s['id'] ? 'selected' : '' ?>
                ><?= htmlspecialchars((string) $s['nimi']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted form-hint">Kilpailun järjestävä seura. Näkyy listoissa ja hubissa.</p>
    <?php elseif ($seuraValinnat !== []): ?>
        <?php $s = $seuraValinnat[0]; ?>
        <input type="hidden" name="seura_id" value="<?= (int) $s['id'] ?>">
        <p><strong><?= htmlspecialchars((string) $s['nimi']) ?></strong></p>
        <p class="muted form-hint">Kilpailu liitetään omaan seuraasi.</p>
    <?php else: ?>
        <p class="muted">Ei aktiivista seuraa. Luo tai liity seuraan ensin (Oma seura).</p>
    <?php endif; ?>

    <label>Tila</label>
    <select name="tila">
        <?php
        $lomakeTilat = kilpailuTilat();
        if (($muokattava['tila'] ?? '') === 'arkisto') {
            $lomakeTilat[] = 'arkisto';
        }
        foreach ($lomakeTilat as $tila):
            ?>
            <option value="<?= $tila ?>"
                <?= (($muokattava['tila'] ?? 'luonnos') === $tila) ? 'selected' : '' ?>>
                <?= htmlspecialchars(kilpailuTilaLabel($tila)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="muted form-hint">Ilmo auki → tila Ilmoittautuminen; ilmoaika ohi → Tulossa. Käynnissä ja Valmis asetetaan manuaalisesti. Arkistointi listan Arkistoi-napilla.</p>

    <?php
    $naytaPirilaKehys = $muokkaaTila && (
        (function_exists('kisaOnPirila') && kisaOnPirila($muokattava))
        || (function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($muokattava))
        || (($muokattava['kisa_tyyppi'] ?? '') === 'pirila')
        || !empty($muokattava['pirila_synkka'])
    );
    ?>
    <?php if ($naytaPirilaKehys): ?>
    <fieldset class="pirila-kehys" style="border:1px solid var(--border,#c5d0c8);border-radius:10px;padding:.85rem 1rem;margin:1rem 0">
        <legend style="font-weight:600">Pirilä</legend>
        <p class="muted form-hint" style="margin-top:0">HkKisaWin / ViestiWin -synkka. Sarjat, radat, kilpailijat ja ajat tulevat Pirilästä. Etusivulla tyyppi on Suunnistus.</p>
        <label class="checkbox-label" style="display:flex;gap:8px;align-items:center;font-weight:500">
            <input type="checkbox" name="pirila_synkka" value="1" id="pirila_synkka"
                <?= (function_exists('pirilaSynkkaPaalla') ? pirilaSynkkaPaalla($muokattava) : !empty($muokattava['pirila_synkka'])) ? 'checked' : '' ?>>
            Pirilä-synkka
        </label>
        <p class="muted form-hint">Kun pois, kisa tehdään tässä sovelluksessa ja luonne valitsee valikot.</p>
        <label class="checkbox-label" style="display:flex;gap:8px;align-items:center;font-weight:500">
            <input type="checkbox" name="jaettu_jono" value="1"
                <?= !empty($muokattava['jaettu_jono']) ? 'checked' : '' ?>>
            Jaettu ajanottolista Pirilän kanssa
        </label>
        <p class="muted form-hint">Pirilä-kisassa avautuu Numerot-sivu: ajat tulevat Pirilästä, numerot selaimesta.</p>
    </fieldset>
    <?php endif; ?>

    <label>Kisan luonne</label>
    <select name="luonne">
        <?php
        $nykyLuonne = kisaLuonneNormalisoi($muokattava['luonne'] ?? null);
        if (!$muokkaaTila && $nykyLuonne === null) {
            $nykyLuonne = function_exists('kisaLuonneOletusUudelle')
                ? kisaLuonneOletusUudelle('normaali')
                : 'juoksu';
        }
        ?>
        <option value="" <?= $nykyLuonne === null ? 'selected' : '' ?>>Ei määritelty (kaikki valikot)</option>
        <?php foreach (kisaLuonteet() as $arvo => $label): ?>
            <option value="<?= $arvo ?>" <?= $nykyLuonne === $arvo ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="muted form-hint">Luonne valitsee näkyvät valikot: suunnistuksessa ratatiedot ja Emit-sivut, juoksussa ja hiihdossa ajanotto. Pirilä asettaa luonteen kilpailulajista, jos synkka on päällä.</p>

    <label class="checkbox-label" style="display:flex;gap:8px;align-items:center;font-weight:500">
        <input type="checkbox" name="ampumapaikat" value="1"
            <?= !empty($muokattava['ampumapaikat']) ? 'checked' : '' ?>>
        Ampumapaikat
    </label>
    <p class="muted form-hint">Kun päällä, valikoissa näkyy Ampumapaikka. Näkyy käyttäjille, joilla on käyttöoikeus <strong>ampumapaikat</strong>.</p>

    <label class="checkbox-label" style="display:flex;gap:8px;align-items:center;font-weight:500">
        <input type="checkbox" name="testikisa" value="1"
            <?= !empty($muokattava['testikisa']) ? 'checked' : '' ?>>
        Testikisa / demokisa
    </label>
    <p class="muted form-hint">Kaikki toimii normaalisti, mutta kisa ei nouse etusivun julkiseen listaan. Se näkyy sinulle, ja kisassa lukee että kyseessä on demokisa.</p>

    <?php if (!$naytaPirilaKehys): ?>
    <label class="checkbox-label" style="display:flex;gap:8px;align-items:center;font-weight:500">
        <input type="checkbox" name="jaettu_jono" value="1"
            <?= !empty($muokattava['jaettu_jono']) ? 'checked' : '' ?>>
        Jaettu ajanottolista Pirilän kanssa
    </label>
    <p class="muted form-hint">Juoksun numeroiden syöttö ja Pirilän maaliajat menevät samaan listaan. Juoksukisa toimii yhä myös ilman Pirilää.</p>
    <?php endif; ?>

    <div id="ilmo-asetukset" class="ilmo-asetukset">
        <label>Ilmoittautuminen</label>
        <label class="checkbox-label" style="display:flex;gap:8px;align-items:center;font-weight:500">
            <input type="checkbox" name="ilmoittautuminen_auki" value="1"
                <?= !empty($muokattava['ilmoittautuminen_auki']) ? 'checked' : '' ?>>
            Ilmoittautumissivu käytössä
        </label>
        <p class="muted form-hint">Kun päällä, valikoissa näkyvät Ilmoittautuminen ja Ilmoittautuneet. Pirilä-kisassa ilmoittautuja saa automaattisesti numeron (1–9979); tiedot siirtyvät Pirilään taustasynkassa tai painikkeella Hae kilpailijat nyt, kun kisa on yhdistetty API:n kautta. Sarjojen nimien pitää täsmätä Pirilän sarjoihin.</p>

        <label>Ilmoittautuminen alkaa</label>
        <input type="datetime-local" name="ilmo_alkaa"
            value="<?= htmlspecialchars(kilpailuIlmoDatetimeLocal($muokattava['ilmo_alkaa'] ?? null)) ?>">

        <label>Ilmoittautuminen päättyy</label>
        <input type="datetime-local" name="ilmo_paattyy"
            value="<?= htmlspecialchars(kilpailuIlmoDatetimeLocal($muokattava['ilmo_paattyy'] ?? null)) ?>">

        <label>Maksu (€, tyhjä = maksuton)</label>
        <input type="number" name="ilmo_maksu_eur" step="0.01" min="0"
            value="<?= htmlspecialchars(isset($muokattava['ilmo_maksu_eur']) && $muokattava['ilmo_maksu_eur'] !== null && $muokattava['ilmo_maksu_eur'] !== '' ? (string) $muokattava['ilmo_maksu_eur'] : '') ?>">
        <p class="muted form-hint">Kun summa on annettu, ilmoittautuja saa tilisiirron viitenumeron. Järjestäjä merkitsee maksun saapuneeksi kilpailijalistassa.</p>

        <label>Saaja</label>
        <input type="text" name="ilmo_saaja" maxlength="140"
            value="<?= htmlspecialchars((string) ($muokattava['ilmo_saaja'] ?? '')) ?>"
            placeholder="Seuran nimi">

        <label>Tilinumero (IBAN)</label>
        <input type="text" name="ilmo_iban" maxlength="34" autocomplete="off"
            value="<?= htmlspecialchars(isset($muokattava['ilmo_iban']) && $muokattava['ilmo_iban'] ? muotoileIban((string) $muokattava['ilmo_iban']) : '') ?>"
            placeholder="FI00 0000 0000 0000 00">

        <label>Maksuohje</label>
        <textarea name="ilmo_maksuohje" rows="3" placeholder="Esim. eräpäivä tai lisätieto"><?= htmlspecialchars((string) ($muokattava['ilmo_maksuohje'] ?? '')) ?></textarea>
    </div>

    <div class="form-actions">
        <button type="submit"><?= $muokkaaTila ? 'Tallenna muutokset' : 'Luo kilpailu' ?></button>
        <a class="btn-secondary" href="/public/omat_tapahtumat.php">Peruuta</a>
    </div>
</form>
    <?php if ($muokkaaTila): ?>
        <?php if (($muokattava['tila'] ?? '') !== 'arkisto'): ?>
            <form method="post" class="inline-form" style="margin-top:1rem" onsubmit="return confirm('Arkistoidaanko kilpailu? Se ei näy enää julkisilla listoilla.');">
                <?= csrfKentta() ?>
                <input type="hidden" name="action" value="arkistoi">
                <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
                <button type="submit" class="btn-secondary">Arkistoi kilpailu</button>
            </form>
        <?php else: ?>
            <form method="post" class="inline-form" style="margin-top:1rem">
                <?= csrfKentta() ?>
                <input type="hidden" name="action" value="palauta_arkisto">
                <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
                <button type="submit">Palauta listalle</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
$lomakeHtml = ob_get_clean();

if ($uusiTila || $muokkaaTila) {
    echo $lomakeHtml;
} else {
    echo $listaHtml;
}
?>

<script>
function kopioiApiAvain(btn) {
    const token = btn.getAttribute('data-token');
    if (!token) {
        return;
    }

    const valmis = () => naytaKopioIlmoitus();

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(token).then(valmis).catch(() => kopioiApiAvainFallback(token, valmis));
        return;
    }

    kopioiApiAvainFallback(token, valmis);
}

function kopioiApiAvainFallback(text, valmis) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        if (document.execCommand('copy')) {
            valmis();
        }
    } catch (e) {}
    document.body.removeChild(ta);
}

function naytaKopioIlmoitus() {
    let el = document.getElementById('api-avain-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'api-avain-toast';
        el.className = 'copy-toast';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        document.body.appendChild(el);
    }

    el.textContent = 'Avain kopioitu leikepöydälle';
    el.classList.add('show');
    clearTimeout(el._hideTimer);
    el._hideTimer = setTimeout(() => el.classList.remove('show'), 2500);
}

<?php if (function_exists('kilpailuPoistoVahvistusJs')): ?>
<?= kilpailuPoistoVahvistusJs() ?>
<?php else: ?>
document.querySelectorAll('form[data-poista-kisa]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
        var nimi = form.getAttribute('data-kisa-nimi') || '';
        if (!window.confirm(
            'Haluatko aivan varmasti poistaa kilpailun?\n\n'
            + nimi
            + '\n\nKaikki sarjat, kilpailijat ja ajat poistuvat. Tätä ei voi perua.'
        )) {
            ev.preventDefault();
            return;
        }
        var syote = window.prompt(
            'Vahvista poisto kirjoittamalla kisan nimi täsmälleen:\n\n' + nimi
        );
        if (syote !== nimi) {
            ev.preventDefault();
            if (syote !== null) {
                window.alert('Nimi ei täsmännyt — kilpailua ei poistettu.');
            }
        }
    });
});
<?php endif; ?>
</script>

<?php pageEnd(); ?>
