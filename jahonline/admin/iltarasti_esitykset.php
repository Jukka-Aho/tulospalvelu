<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../includes/iltarasti_esitys.php';
require_once __DIR__ . '/../db.php';

if (function_exists('tiliVarmistaTaulut')) {
    tiliVarmistaTaulut($pdo);
}
if (function_exists('tiliLataaKayttajaSessioon')) {
    tiliLataaKayttajaSessioon($pdo);
}

vaadiKisahallinta();

$getId = (int) ($_GET['kilpailu_id'] ?? 0);
if ($getId > 0) {
    $kilpailu = haeKilpailu($pdo, $getId);
    if (!$kilpailu || !kisaOnIltarasti($kilpailu)) {
        header('Location: /public/iltarastit.php');
        exit;
    }
    vaadiKilpailunHallinta($kilpailu);
    asetaValittuKilpailu($getId);
} else {
    $kilpailu = vaadiValittuKilpailu($pdo);
    vaadiKilpailunHallinta($kilpailu);
    if (!kisaOnIltarasti($kilpailu)) {
        header('Location: iltarastit.php');
        exit;
    }
}
iltarastiOhjaaJulkiseen('/public/iltarasti_esitykset.php');

$kilpailuId = (int) $kilpailu['id'];
$viesti = '';
$virhe = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
        die('Virheellinen pyyntö');
    }
    $tulos = iltarastiEsitysKasittele($pdo, $kilpailu, $_POST);
    if (!empty($tulos['ok'])) {
        $q = ['kilpailu_id' => $kilpailuId, 'viesti' => (string) ($tulos['message'] ?? 'Tallennettu.')];
        header('Location: iltarasti_esitykset.php?' . http_build_query($q), true, 302);
        exit;
    }
    $virhe = (string) ($tulos['message'] ?? 'Tallennus epäonnistui.');
}

$esitykset = iltarastiHaeEsitykset($pdo, $kilpailu);
$luvut = iltarastiTilanneLuvut($pdo, $kilpailu);
$viesti = trim((string) ($_GET['viesti'] ?? $viesti));

pageStart('Esitykset', iltarastiKuoriOpts($pdo, $kilpailu, 'esitykset', 'iltarasti-esitykset-sivu'));
?>
<h1>Esitykset</h1>
<p class="muted">Maastossa <?= (int) $luvut['maastossa'] ?>.
    Hyväksy, hylkää, keskeytä tai vaihda rata ilman kortin uudelleenlukua.</p>
<?php if ($viesti !== ''): ?>
    <p class="ok"><?= htmlspecialchars($viesti) ?></p>
<?php endif; ?>
<?php if ($virhe !== ''): ?>
    <p class="error"><?= htmlspecialchars($virhe) ?></p>
<?php endif; ?>

<p class="esitys-tyhja" id="esitys-tyhja"<?= $esitykset === [] ? '' : ' hidden' ?>>Ei esityksiä.</p>
<?php if ($esitykset !== []): ?>
    <ul class="esitys-lista" id="esitys-lista">
        <?php foreach ($esitykset as $e):
            $eid = (int) $e['id'];
            ?>
            <li class="esitys-kortti" data-id="<?= $eid ?>">
                <div class="esitys-yla">
                    <strong class="esitys-nimi"><?= htmlspecialchars((string) $e['nimi']) ?></strong>
                    <?php if ($e['seura'] !== ''): ?>
                        <span class="esitys-seura"><?= htmlspecialchars((string) $e['seura']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="esitys-meta">
                    <span>Rata <?= htmlspecialchars((string) $e['rata']) ?></span>
                    <?php if ($e['aika_txt'] !== ''): ?>
                        <span><?= htmlspecialchars((string) $e['aika_txt']) ?></span>
                    <?php endif; ?>
                    <?php if ((int) $e['emit'] > 0): ?>
                        <span>kortti <?= (int) $e['emit'] ?></span>
                    <?php endif; ?>
                </div>
                <p class="esitys-leimat">
                    <?php if ($e['puuttuvat'] !== []): ?>
                        Puuttuu: <?= htmlspecialchars(implode(', ', array_map('strval', $e['puuttuvat']))) ?>
                    <?php elseif (!empty($e['tark_ok'])): ?>
                        Rastit täsmäävät — tarkista silti.
                    <?php else: ?>
                        Ei rastitarkistusta.
                    <?php endif; ?>
                    <?php if ($e['vaarat'] !== []): ?>
                        · Väärät: <?= htmlspecialchars(implode(', ', array_map('strval', $e['vaarat']))) ?>
                    <?php endif; ?>
                </p>
                <div class="esitys-toimet">
                    <form method="post" class="esitys-lomake" data-action="paatos">
                        <?= csrfKentta() ?>
                        <input type="hidden" name="kilpailu_id" value="<?= $kilpailuId ?>">
                        <input type="hidden" name="kilpailija_id" value="<?= $eid ?>">
                        <input type="hidden" name="action" value="paatos">
                        <button type="submit" name="paatos" value="T" class="btn esitys-ok">Hyväksy</button>
                        <button type="submit" name="paatos" value="H" class="btn-secondary esitys-hyl">Hyl</button>
                        <button type="submit" name="paatos" value="K" class="btn-secondary esitys-kes">Kesk</button>
                    </form>
                    <?php if (!empty($e['arvaus']['id'])): ?>
                        <form method="post" class="esitys-lomake" data-action="vaihda_rata">
                            <?= csrfKentta() ?>
                            <input type="hidden" name="kilpailu_id" value="<?= $kilpailuId ?>">
                            <input type="hidden" name="kilpailija_id" value="<?= $eid ?>">
                            <input type="hidden" name="action" value="vaihda_rata">
                            <input type="hidden" name="sarja_id" value="<?= (int) $e['arvaus']['id'] ?>">
                            <button type="submit" class="btn esitys-vaihda">Vaihda: <?= htmlspecialchars((string) $e['arvaus']['nimi']) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<script>
window.JAH_ESITYS = {
    kilpailu_id: <?= $kilpailuId ?>,
    csrf: <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/js/iltarasti_esitykset.js?v=20261112esitys"></script>
<?php pageEnd(); ?>
