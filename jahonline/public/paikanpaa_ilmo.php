<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/maksu.php';
require_once __DIR__ . '/../includes/sarja_numerot.php';
maksuVarmista($pdo);
sarjaNumerotVarmista($pdo);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

$token = paikanpaaLueTokenPyynnossa();
$kilpailu = $token !== '' ? paikanpaaHaeKilpailuTokenilla($pdo, $token) : null;
if (!$kilpailu) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="fi"><head><meta charset="UTF-8"><title>Ilmoittautuminen</title></head>';
    echo '<body><p>Ilmoittautuminen ei ole käytössä.</p></body></html>';
    exit;
}

$kilpailu_id = (int) $kilpailu['id'];
$kioskiPolku = paikanpaaKioskiPolku($token);
$auki = kisaPaikanpaaIlmo($kilpailu);
$sarjat = $auki ? haeSarjatIlmoittautumiseen($pdo, $kilpailu_id) : [];
$sarjaHinnat = function_exists('kisaMaksuSarjoittain') && kisaMaksuSarjoittain($kilpailu);
$maksuEur = $sarjaHinnat ? null : paikanpaaMaksuEur($kilpailu);
$virhe = '';
$onnistuiNimi = trim((string) ($_GET['ok'] ?? ''));
$form = [
    'etunimi' => '',
    'sukunimi' => '',
    'seura' => '',
    'sarja_id' => (string) ($_GET['sarja'] ?? ''),
];

csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auki) {
        $virhe = 'Paikan päällä ilmoittautuminen ei ole käytössä.';
    } elseif (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        $virhe = 'Virheellinen pyyntö. Lataa sivu uudelleen.';
    } else {
        $form['etunimi'] = trim((string) ($_POST['etunimi'] ?? ''));
        $form['sukunimi'] = trim((string) ($_POST['sukunimi'] ?? ''));
        $form['seura'] = trim((string) ($_POST['seura'] ?? ''));
        $form['sarja_id'] = (string) (int) ($_POST['sarja_id'] ?? 0);
        $sarjaId = (int) $form['sarja_id'];
        $sarjaOk = false;
        foreach ($sarjat as $s) {
            if ((int) $s['id'] === $sarjaId) {
                $sarjaOk = true;
                break;
            }
        }
        $nimet = normalisoiKilpailijaNimet($form['etunimi'], $form['sukunimi']);
        if ($form['etunimi'] === '') {
            $virhe = 'Anna etunimi.';
        } elseif ($form['sukunimi'] === '') {
            $virhe = 'Anna sukunimi.';
        } elseif (mb_strlen($form['etunimi']) > 100 || mb_strlen($form['sukunimi']) > 100) {
            $virhe = 'Nimi on liian pitkä.';
        } elseif (mb_strlen($form['seura']) > 255) {
            $virhe = 'Seura on liian pitkä.';
        } elseif (!$sarjaOk) {
            $virhe = 'Valitse sarja.';
        } else {
            $seuraVal = $form['seura'] !== '' ? $form['seura'] : null;
            try {
                ilmoTallennaKilpailija($pdo, $kilpailu, $sarjaId, $nimet, $seuraVal, 'paikanpaa', false);
                $okNimi = trim($nimet['etunimi'] . ' ' . $nimet['sukunimi']);
                header('Location: ' . $kioskiPolku
                    . '?ok=' . rawurlencode($okNimi)
                    . ($sarjaId ? '&sarja=' . $sarjaId : ''));
                exit;
            } catch (Throwable $e) {
                $virhe = 'Ilmoittautuminen epäonnistui. Yritä uudelleen.';
            }
        }
    }
}

$seuraavaUrl = $kioskiPolku
    . ($form['sarja_id'] !== '' ? '?sarja=' . (int) $form['sarja_id'] : '');

$opts = [
    'embed' => true,
    'class' => 'live-board-page tulospalvelu-page paikanpaa-paljas',
    'manifest' => '/public/paikanpaa_manifest.php?t=' . rawurlencode($token) . '&v=20261114kioski5',
    'pwaTitle' => 'Ilmoittautuminen',
    'extraHead' => '<link rel="stylesheet" href="/assets/css/live_board.css?v=20261114kioski5">'
        . '<style>'
        . 'body.paikanpaa-paljas .site-topbar,body.paikanpaa-paljas .admin-sidebar,'
        . 'body.paikanpaa-paljas .admin-menu-toggle,body.paikanpaa-paljas .theme-toggle,'
        . 'body.paikanpaa-paljas .demo-kisa-ilmoitus,body.paikanpaa-paljas .lb-top,'
        . 'body.paikanpaa-paljas .lb-actions{display:none!important}'
        . '.pp-paljas{max-width:28rem;margin:0 auto;padding:1.25rem 1.1rem 2rem}'
        . '.pp-paljas h1{margin:0 0 1rem;font-size:1.55rem}'
        . '</style>',
];
if ($onnistuiNimi !== '') {
    $opts['extraHead'] .= '<meta http-equiv="refresh" content="7;url=' . htmlspecialchars($seuraavaUrl) . '">';
}

pageStart('Ilmoittautuminen', $opts);
?>

<div class="pp-paljas">
    <h1><?= htmlspecialchars((string) $kilpailu['nimi']) ?></h1>

<?php if (!$auki): ?>
    <p class="lb-notice">Paikan päällä ilmoittautuminen ei ole tälle kilpailulle käytössä.</p>
<?php elseif ($sarjat === []): ?>
    <p class="lb-notice">Sarjoja ei ole vielä. Kioski avataan, kun sarjat on luotu.</p>
<?php elseif ($onnistuiNimi !== ''): ?>
    <p class="lb-notice lb-notice--ok">
        <strong>Kiitos!</strong><br>
        <?= htmlspecialchars($onnistuiNimi) ?> — siirry toimihenkilölle numeroa ja maksua varten.
    </p>
    <form method="get" action="<?= htmlspecialchars($kioskiPolku) ?>">
        <?php if ($form['sarja_id'] !== ''): ?>
            <input type="hidden" name="sarja" value="<?= (int) $form['sarja_id'] ?>">
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit">Seuraava ilmoittautuja</button>
        </div>
    </form>
<?php else: ?>
    <?php if ($virhe !== ''): ?>
        <p class="lb-notice lb-notice--virhe"><?= htmlspecialchars($virhe) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($kioskiPolku) ?>" class="lb-form" autocomplete="off">
        <div class="form-grid">
            <?= csrfKentta() ?>

            <label for="etunimi">Etunimi</label>
            <input type="text" id="etunimi" name="etunimi" required maxlength="100"
                   value="<?= htmlspecialchars($form['etunimi']) ?>"
                   autocomplete="off" autocapitalize="words" autofocus>

            <label for="sukunimi">Sukunimi</label>
            <input type="text" id="sukunimi" name="sukunimi" required maxlength="100"
                   value="<?= htmlspecialchars($form['sukunimi']) ?>"
                   autocomplete="off" autocapitalize="words">

            <label for="seura">Seura</label>
            <input type="text" id="seura" name="seura" maxlength="255"
                   value="<?= htmlspecialchars($form['seura']) ?>"
                   autocomplete="off">

            <label for="sarja_id">Sarja</label>
            <select id="sarja_id" name="sarja_id" required>
                <option value="">— Valitse sarja —</option>
                <?php foreach ($sarjat as $s): ?>
                    <?php
                    $hintaEur = $sarjaHinnat && function_exists('paikanpaaMaksuEurIlmoittautuvalle')
                        ? paikanpaaMaksuEurIlmoittautuvalle($kilpailu, $s)
                        : null;
                    $hintaTxt = $hintaEur !== null ? formatEuro($hintaEur) : '';
                    ?>
                    <option value="<?= (int) $s['id'] ?>"
                        <?= (string) (int) $s['id'] === $form['sarja_id'] ? 'selected' : '' ?>
                        <?php if ($sarjaHinnat): ?>data-hinta="<?= htmlspecialchars($hintaTxt) ?>"<?php endif; ?>>
                        <?= htmlspecialchars(sarjaOtsikkoMatka($s['nimi'], $s['matka'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($sarjaHinnat): ?>
            <p class="form-hint" id="sarja-hinta" hidden></p>
            <script>
            (function () {
                var sel = document.getElementById('sarja_id');
                var el = document.getElementById('sarja-hinta');
                if (!sel || !el) return;
                function paivita() {
                    if (!sel.value) { el.hidden = true; return; }
                    var opt = sel.options[sel.selectedIndex];
                    var h = opt ? (opt.getAttribute('data-hinta') || '') : '';
                    el.hidden = false;
                    el.textContent = h
                        ? ('Osallistumismaksu ' + h + ' maksetaan toimihenkilölle numeron luovutuksen yhteydessä.')
                        : 'Tälle sarjalle ei ole osallistumismaksua.';
                }
                sel.addEventListener('change', paivita);
                paivita();
            })();
            </script>
            <?php elseif ($maksuEur !== null): ?>
            <p class="form-hint">
                Osallistumismaksu <?= htmlspecialchars(formatEuro($maksuEur)) ?> maksetaan toimihenkilölle numeron luovutuksen yhteydessä.
            </p>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit">Ilmoittaudu</button>
            </div>
        </div>
    </form>
<?php endif; ?>
</div>

<script src="/assets/js/paikanpaa_herata.js?v=20261114kioski5"></script>
<?php pageEnd(); ?>
