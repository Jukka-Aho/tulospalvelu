<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/radat.php';
require_once __DIR__ . '/../includes/sarja_numerot.php';
if (is_readable(__DIR__ . '/../includes/maksu.php')) {
    require_once __DIR__ . '/../includes/maksu.php';
}
if (is_readable(__DIR__ . '/../includes/viesti_sarjat.php')) {
    require_once __DIR__ . '/../includes/viesti_sarjat.php';
}

require_once __DIR__ . '/../db.php';

if (function_exists('tiliVarmistaTaulut')) {
    tiliVarmistaTaulut($pdo);
}
if (function_exists('tiliLataaKayttajaSessioon')) {
    tiliLataaKayttajaSessioon($pdo);
}
vaadiKisahallinta();

$kilpailu = vaadiValittuKilpailu($pdo);
vaadiKilpailunHallinta($kilpailu);
radatVarmistaTaulut($pdo);
sarjaNumerotVarmista($pdo);
if (function_exists('maksuVarmista')) {
    maksuVarmista($pdo);
}
$kilpailu_id = (int) $kilpailu['id'];
$maksuSarjoittain = function_exists('kisaMaksuSarjoittain') && kisaMaksuSarjoittain($kilpailu);
$pirila = pirilaSynkkaPaalla($kilpailu);
$onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
if (function_exists('viestiSarjatVarmista')) {
    viestiSarjatVarmista($pdo);
}
$muokattava_id = isset($_GET['muokkaa']) ? (int) $_GET['muokkaa'] : 0;
$viesti = (string) ($_GET['viesti'] ?? '');

if ($pirila && $muokattava_id) {
    header('Location: sarjat.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
        die('Virheellinen pyyntö');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($pirila && $action === 'update_valia_lkm') {
        $stmt = $pdo->prepare('UPDATE sarjat SET valia_lkm = ? WHERE id = ? AND kilpailu_id = ?');
        $stmt->execute([
            max(0, (int) ($_POST['valia_lkm'] ?? 0)),
            (int) ($_POST['id'] ?? 0),
            $kilpailu_id,
        ]);
        header('Location: sarjat.php?viesti=' . urlencode('Online-väliajat tallennettu.'));
        exit;
    }
    estaaMuokkausJosPirila($kilpailu);

    if ($action === 'create') {
        $matka = normalisoiSarjaMatka((string) ($_POST['matka'] ?? ''));
        $valit = sarjaNumeroValitTeksti((string) ($_POST['numero_valit'] ?? ''));
        $osuuksia = $onViesti && function_exists('viestiSarjaOsuuksiaArvo')
            ? viestiSarjaOsuuksiaArvo($_POST['osuuksia'] ?? 3)
            : 0;
        $stmt = $pdo->prepare('
            INSERT INTO sarjat (kilpailu_id, nimi, matka, lahto_id, nayta_ajat, valia_lkm, jarjestys, numero_valit, osuuksia, ennakko_maksu_eur, paikanpaa_maksu_eur)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $kilpailu_id,
            $_POST['nimi'],
            $matka,
            $_POST['lahto_id'] ?: null,
            isset($_POST['nayta_ajat']) ? 1 : 0,
            max(0, (int) ($_POST['jarjestys'] ?? 0)),
            $valit !== '' ? $valit : null,
            $osuuksia,
            function_exists('lomakeMaksuEur') ? lomakeMaksuEur($_POST['ennakko_maksu_eur'] ?? '') : null,
            function_exists('lomakeMaksuEur') ? lomakeMaksuEur($_POST['paikanpaa_maksu_eur'] ?? '') : null,
        ]);
        if ($onViesti && function_exists('viestiSarjatPaivitaKisaOsuudet')) {
            viestiSarjatPaivitaKisaOsuudet($pdo, $kilpailu_id);
        }
        header('Location: sarjat.php?viesti=' . urlencode('Sarja lisätty.'));
        exit;
    }

    if ($action === 'update') {
        $matka = normalisoiSarjaMatka((string) ($_POST['matka'] ?? ''));
        $valit = sarjaNumeroValitTeksti((string) ($_POST['numero_valit'] ?? ''));
        $osuuksia = $onViesti && function_exists('viestiSarjaOsuuksiaArvo')
            ? viestiSarjaOsuuksiaArvo($_POST['osuuksia'] ?? 3)
            : 0;
        $stmt = $pdo->prepare('
            UPDATE sarjat SET nimi = ?, matka = ?, lahto_id = ?, nayta_ajat = ?, jarjestys = ?, numero_valit = ?, osuuksia = ?,
                ennakko_maksu_eur = ?, paikanpaa_maksu_eur = ?
            WHERE id = ? AND kilpailu_id = ?
        ');
        $stmt->execute([
            $_POST['nimi'], $matka, $_POST['lahto_id'] ?: null,
            isset($_POST['nayta_ajat']) ? 1 : 0,
            max(0, (int) ($_POST['jarjestys'] ?? 0)),
            $valit !== '' ? $valit : null,
            $osuuksia,
            function_exists('lomakeMaksuEur') ? lomakeMaksuEur($_POST['ennakko_maksu_eur'] ?? '') : null,
            function_exists('lomakeMaksuEur') ? lomakeMaksuEur($_POST['paikanpaa_maksu_eur'] ?? '') : null,
            $_POST['id'], $kilpailu_id,
        ]);
        if ($onViesti && function_exists('viestiSarjatPaivitaKisaOsuudet')) {
            viestiSarjatPaivitaKisaOsuudet($pdo, $kilpailu_id);
        }
        header('Location: sarjat.php?viesti=' . urlencode('Sarja tallennettu.'));
        exit;
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM sarjat WHERE id = ? AND kilpailu_id = ?');
        $stmt->execute([$_POST['id'], $kilpailu_id]);
        header('Location: sarjat.php?viesti=' . urlencode('Sarja poistettu.'));
        exit;
    }

    header('Location: sarjat.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT sarjat.*, lahdot.nimi AS lahto_nimi
    FROM sarjat
    LEFT JOIN lahdot ON sarjat.lahto_id = lahdot.id
    WHERE sarjat.kilpailu_id = ?
    ORDER BY sarjat.jarjestys, sarjat.nimi
');
$stmt->execute([$kilpailu_id]);
$sarjat = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lahdot = [];
if (!$pirila) {
    $stmt = $pdo->prepare('SELECT id, nimi, lahtoaika FROM lahdot WHERE kilpailu_id = ? ORDER BY lahtoaika');
    $stmt->execute([$kilpailu_id]);
    $lahdot = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$seuraavaJarjestys = 1;
foreach ($sarjat as $s) {
    $seuraavaJarjestys = max($seuraavaJarjestys, (int) ($s['jarjestys'] ?? 0) + 1);
}

$muokattava = null;
if ($muokattava_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM sarjat WHERE id = ? AND kilpailu_id = ?');
    $stmt->execute([$muokattava_id, $kilpailu_id]);
    $muokattava = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$muokattava) {
        $muokattava_id = 0;
    }
}

function lahtoValinnat($lahdot, $valittu = null)
{
    echo '<option value="">-- Ei lähtöä --</option>';
    foreach ($lahdot as $lahto) {
        $sel = ((int) $valittu === (int) $lahto['id']) ? ' selected' : '';
        echo '<option value="' . (int) $lahto['id'] . '"' . $sel . '>'
            . htmlspecialchars($lahto['nimi'] . ' (' . $lahto['lahtoaika'] . ')') . '</option>';
    }
}

pageStart($pirila ? 'Sarjat (Pirila)' : 'Sarjat', ['admin' => true, 'active' => 'sarjat', 'pdo' => $pdo]);
?>

<h1><?= $pirila ? 'Sarjat (Pirila)' : 'Sarjat' ?></h1>
<p class="hallinta-takaisin"><a class="btn-secondary" href="index.php">← Kisan hallinta</a></p>
<p class="muted"><?= htmlspecialchars((string) $kilpailu['nimi']) ?></p>

<?php pirilaVirheIlmoitus(); ?>
<?php pirilaTilaIlmoitus($kilpailu); ?>
<?php if ($viesti !== ''): ?><p class="notice"><?= htmlspecialchars($viesti) ?></p><?php endif; ?>

<?php if ($pirila): ?>
    <p class="muted">Sarjat tulevat Pirilästä. Muokkaa nimiä, matkoja ja lähtöjä HkKisaWinissä tai ViestiWinissä; synkka päivittää ne tänne. Online-va on netin väliaikasarakkeiden määrä.</p>
<?php else: ?>
    <p class="muted">Määritä sarjat tässä. Radan voi valita sivulla <a href="radat.php">Radat</a>. Numerovälit koskevat paikan päällä annettavia kilpailunumeroita.
        <?php if ($maksuSarjoittain): ?>
            Kisa käyttää <strong>sarjakohtaisia hintoja</strong> (ennakko ja paikan päällä).
        <?php else: ?>
            Kisan asetuksissa on nyt yksi hinta kaikille; sarjan hinnat tulevat voimaan, kun valitset sarjakohtaiset hinnat.
        <?php endif; ?>
    </p>
    <h2><?= $muokattava ? 'Muokkaa sarjaa' : 'Lisää sarja' ?></h2>
    <form method="post">
        <div class="form-grid">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="<?= $muokattava ? 'update' : 'create' ?>">
        <?php if ($muokattava): ?>
            <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
        <?php endif; ?>

        <label>Nimi</label>
        <input type="text" name="nimi" required value="<?= htmlspecialchars((string) ($muokattava['nimi'] ?? '')) ?>">

        <label>Matka (km)</label>
        <input type="number" name="matka" inputmode="decimal" min="0" step="0.1"
               placeholder="esim. 10"
               value="<?= htmlspecialchars((string) (normalisoiSarjaMatka($muokattava['matka'] ?? '') ?? '')) ?>">
        <p class="muted" style="grid-column:2;margin-top:-0.5rem">Syötä vain numero; “km” lisätään automaattisesti tuloksiin.</p>

        <label>Lähtö</label>
        <select name="lahto_id"><?php lahtoValinnat($lahdot, $muokattava['lahto_id'] ?? null); ?></select>

        <?php if ($onViesti): ?>
        <label>Osuuksia</label>
        <input type="number" name="osuuksia" min="1" max="30"
               value="<?= htmlspecialchars((string) max(1, (int) ($muokattava['osuuksia'] ?? 3))) ?>">
        <p class="muted" style="grid-column:2;margin-top:-0.5rem">Montako osuutta tässä sarjassa juostaan.</p>
        <?php endif; ?>

        <label>Järjestys</label>
        <input type="number" name="jarjestys" min="0" step="1"
               value="<?= htmlspecialchars((string) ($muokattava['jarjestys'] ?? $seuraavaJarjestys)) ?>">
        <p class="muted form-hint" style="grid-column:2;margin-top:-0.5rem">Sarjojen järjestys ilmoittautumislomakkeessa, ilmoittautuneissa, lähtölistassa ja tuloksissa. Pienin numero ensin (1 = ensimmäinen). Sama numero → aakkosjärjestys nimen mukaan. Jos osalla on 0 ja osalla 1, 2, …, nollat tulevat ensin — numeroi siis kaikki sarjat, jos haluat tietyn järjestyksen.</p>

        <label>Numerovälit</label>
        <input type="text" name="numero_valit" maxlength="200"
               placeholder="esim. 1-50, 80-99"
               value="<?= htmlspecialchars(sarjaNumeroValitTeksti((string) ($muokattava['numero_valit'] ?? ''))) ?>">
        <p class="muted" style="grid-column:2;margin-top:-0.5rem">Sarjan numerot, jotka ovat käytössä. Aukot ovat sallittuja (osa numeroista voi puuttua). Seuraava annettu numero on ensimmäinen vapaa näissä väleissä. Numero on yksilöllinen koko kisassa.</p>

        <label>Ennakko (€)</label>
        <input type="number" name="ennakko_maksu_eur" step="0.01" min="0"
               placeholder="tyhjä = maksuton"
               value="<?= htmlspecialchars(function_exists('lomakeMaksuEurArvo') ? lomakeMaksuEurArvo($muokattava['ennakko_maksu_eur'] ?? null) : '') ?>">
        <p class="muted form-hint" style="grid-column:2;margin-top:-0.5rem">Verkkoilmoittautumisen hinta tälle sarjalle, kun kisan asetuksissa on sarjakohtaiset hinnat.</p>

        <label>Paikan päällä (€)</label>
        <input type="number" name="paikanpaa_maksu_eur" step="0.01" min="0"
               placeholder="tyhjä = maksuton"
               value="<?= htmlspecialchars(function_exists('lomakeMaksuEurArvo') ? lomakeMaksuEurArvo($muokattava['paikanpaa_maksu_eur'] ?? null) : '') ?>">
        <p class="muted form-hint" style="grid-column:2;margin-top:-0.5rem">Kioski-ilmoittautumisen hinta tälle sarjalle.</p>

        <label class="checkbox-label">
            <input type="checkbox" name="nayta_ajat" <?= (($muokattava['nayta_ajat'] ?? 1) ? 'checked' : '') ?>>
            Näytä ajat tuloksissa
        </label>

        <div class="form-actions">
            <button type="submit"><?= $muokattava ? 'Tallenna' : 'Lisää sarja' ?></button>
            <?php if ($muokattava): ?><a class="btn-secondary" href="sarjat.php">Peruuta</a><?php endif; ?>
        </div>
        </div>
    </form>
<?php endif; ?>

<section class="kisa-lista-osio">
    <div class="kisa-lista-header">
        <h2>Sarjalista (<?= count($sarjat) ?>)</h2>
        <?php if ($pirila): ?>
            <p class="muted">Rata on sarjan nimi, ellei Pirilässä ole valittu eri rataa. <a href="radat.php">Radat (Pirila)</a></p>
        <?php endif; ?>
    </div>

    <?php if ($sarjat === []): ?>
        <p class="home-empty muted"><?= $pirila
            ? 'Sarjoja ei ole vielä tallennettu. Ne tulevat, kun HkKisaWin synkkaa sarjat.'
            : 'Ei sarjoja vielä.' ?></p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table sarjat-taulukko">
                <thead>
                    <tr>
                        <th>Nimi</th>
                        <?php if ($onViesti): ?><th>Osuuksia</th><?php endif; ?>
                        <th>Rata</th>
                        <th>Matka</th>
                        <th>Lähtö</th>
                        <th title="Sarjojen järjestys ilmoittautuneissa, lähtölistassa ja tuloksissa">Järj.</th>
                        <th>Ajat</th>
                        <?php if (!$pirila): ?><th>Numerovälit</th><?php endif; ?>
                        <?php if (!$pirila): ?><th>Ennakko</th><th>Paikan päällä</th><?php endif; ?>
                        <?php if ($pirila): ?><th>Online-va</th><?php endif; ?>
                        <?php if (!$pirila): ?><th class="col-toiminnot">Toiminnot</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sarjat as $sarja): ?>
                        <?php
                        $sid = (int) $sarja['id'];
                        $muokataan = $muokattava && (int) ($muokattava['id'] ?? 0) === $sid;
                        ?>
                        <tr<?= $muokataan ? ' class="is-editing-row"' : '' ?>>
                            <td>
                                <?= htmlspecialchars((string) $sarja['nimi']) ?>
                                <?php if ($muokataan): ?>
                                    <span class="kisa-lista-valittu">Muokataan</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($onViesti): ?>
                            <td class="col-numero"><?= max(0, (int) ($sarja['osuuksia'] ?? 0)) ?: '—' ?></td>
                            <?php endif; ?>
                            <td>
                                <?php $rataNimi = trim((string) ($sarja['rata_nimi'] ?? '')); ?>
                                <?php if ($rataNimi !== ''): ?>
                                    <a href="radat.php?rata=<?= rawurlencode($rataNimi) ?>"><?= htmlspecialchars($rataNimi) ?></a>
                                    <?php if (empty($sarja['rata_oma'])): ?><span class="muted"> oletus</span><?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(formatSarjaMatka($sarja['matka'] ?? '') ?: '—') ?></td>
                            <td><?= htmlspecialchars((string) ($sarja['lahto_nimi'] ?? '—')) ?></td>
                            <td class="col-numero"><?= (int) $sarja['jarjestys'] ?></td>
                            <td><?= !empty($sarja['nayta_ajat']) ? 'Kyllä' : 'Ei' ?></td>
                            <?php if (!$pirila): ?>
                            <td><?= htmlspecialchars(sarjaNumeroValitTeksti((string) ($sarja['numero_valit'] ?? '')) ?: '—') ?></td>
                            <td><?= htmlspecialchars(function_exists('formatEuro') ? (formatEuro(ilmoMaksuEurKentasta($sarja['ennakko_maksu_eur'] ?? null)) ?: '—') : '—') ?></td>
                            <td><?= htmlspecialchars(function_exists('formatEuro') ? (formatEuro(ilmoMaksuEurKentasta($sarja['paikanpaa_maksu_eur'] ?? null)) ?: '—') : '—') ?></td>
                            <?php endif; ?>
                            <?php if ($pirila): ?>
                            <td>
                                <form method="post" class="inline-form valia-lkm-form">
                                    <?= csrfKentta() ?>
                                    <input type="hidden" name="action" value="update_valia_lkm">
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <input type="number" name="valia_lkm" min="0" max="30"
                                           value="<?= (int) ($sarja['valia_lkm'] ?? 0) ?>"
                                           style="width:4rem" title="Online-väliaikarastien määrä">
                                    <button type="submit" class="btn-small btn-secondary kisa-btn-muokkaa">OK</button>
                                </form>
                            </td>
                            <?php endif; ?>
                            <?php if (!$pirila): ?>
                            <td class="col-toiminnot">
                                <div class="taulukko-toiminnot">
                                    <a class="btn-small btn-secondary kisa-btn-muokkaa" href="sarjat.php?muokkaa=<?= $sid ?>">Muokkaa</a>
                                    <form method="post" class="inline-form"
                                          onsubmit="return confirm('Oletko varma, että haluat poistaa tämän sarjan?');">
                                        <?= csrfKentta() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button type="submit" class="btn-danger btn-small kisa-btn-poista">Poista</button>
                                    </form>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php pageEnd(); ?>
