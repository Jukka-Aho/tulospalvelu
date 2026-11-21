<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../db.php';

if (function_exists('tiliVarmistaTaulut')) {
    tiliVarmistaTaulut($pdo);
}
if (function_exists('tiliLataaKayttajaSessioon')) {
    tiliLataaKayttajaSessioon($pdo);
}
$getId = (int) ($_GET['kilpailu_id'] ?? 0);
if ($getId > 0) {
    $kilpailu = haeKilpailu($pdo, $getId);
    if (!$kilpailu || !kisaOnIltarasti($kilpailu)) {
        header('Location: /public/index.php');
        exit;
    }
    vaadiJulkinenNakyvyys($kilpailu, '/public/index.php');
    asetaValittuKilpailu($getId);
} else {
    vaadiKisahallinta();
    $kilpailu = vaadiValittuKilpailu($pdo);
    vaadiKilpailunHallinta($kilpailu);
    if (!kisaOnIltarasti($kilpailu)) {
        header('Location: index.php');
        exit;
    }
}
iltarastiOhjaaJulkiseen('/public/iltarasti_tulokset.php');

$nakyma = trim((string) ($_GET['nakyma'] ?? 'tulokset'));
if ($nakyma !== 'valiajat') {
    $nakyma = 'tulokset';
}

$src = '/public/seuranta.php?kilpailu_id=' . (int) $kilpailu['id']
    . '&embed=1&scroll=1&nakyma=' . rawurlencode($nakyma);
$naytaSsl = false;
if (is_readable(__DIR__ . '/../includes/ssl_tulokset.php')) {
    require_once __DIR__ . '/../includes/ssl_tulokset.php';
    $naytaSsl = function_exists('sslTuloksetSallittu') && sslTuloksetSallittu($kilpailu);
}
pageStart('Tulokset', iltarastiKuoriOpts($pdo, $kilpailu, 'tulokset', 'iltarasti-tulokset-sivu'));
?>
<?php if ($naytaSsl): ?>
<p class="form-actions" style="margin-bottom:0.75rem">
    <?php sslTuloksetNappiHtml($pdo, $kilpailu, 'btn-secondary'); ?>
</p>
<?php endif; ?>
<iframe class="iltarasti-tulokset-iframe" src="<?= htmlspecialchars($src) ?>" title="Tulokset"></iframe>
<?php pageEnd(); ?>
