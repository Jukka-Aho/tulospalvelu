<?php

/**
 * SSL-tulostiedoston lataus (Suomen Suunnistusliitto).
 * 10 € / kisa, jossa latausta käytetään. Vain henkilökohtainen kisa, hallinta.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/ssl_tulokset.php';
if (is_readable(__DIR__ . '/../includes/tili.php')) {
    require_once __DIR__ . '/../includes/tili.php';
}

vaadiKisahallinta();

$kilpailu_id = isset($_GET['kilpailu_id']) ? (int) $_GET['kilpailu_id'] : 0;
if ($kilpailu_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "kilpailu_id puuttuu\n";
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailu_id);
if (!$kilpailu) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kilpailua ei löydy\n";
    exit;
}

vaadiKilpailunHallinta($kilpailu);
sslTuloksetVarmista($pdo);
$kilpailu = haeKilpailu($pdo, $kilpailu_id) ?: $kilpailu;

if (function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SSL-tiedosto on vain henkilökohtaisille kilpailuille.\n";
    exit;
}

$hinta = function_exists('tiliSslHintaEuro') ? tiliSslHintaEuro() : 10.0;
$hintaTxt = function_exists('tiliEuroTeksti') ? tiliEuroTeksti($hinta) : '10,00 €';
$postiOk = sslTuloksetLaskutusOk($pdo, $kilpailu);
$kaytetty = sslTuloksetKaytetty($kilpailu);
$lataaNyt = $postiOk && $kaytetty;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
        die('Virheellinen pyyntö');
    }
    if (!$postiOk) {
        $lataaNyt = false;
    } else {
        $lataaNyt = true;
    }
}

if ($lataaNyt) {
    sslLahetaTiedosto($pdo, $kilpailu);
}

pageStart('SSL-tulokset', ['admin' => true, 'pdo' => $pdo]);
?>
<h1>Lataa SSL-tulokset</h1>
<p class="muted"><?= htmlspecialchars((string) ($kilpailu['nimi'] ?? '')) ?></p>
<p>
    Tiedosto on Suomen Suunnistusliiton SSL-muodossa (ISO-8859-1).
    Ensimmäinen lataus tältä kisalta maksaa
    <strong><?= htmlspecialchars($hintaTxt) ?></strong>
    ja laskutetaan kuukausittain seuran laskutussähköpostiin.
</p>
<?php if (!$postiOk): ?>
    <?php tiliLaskutuspostiHuomioHtml('Lisää se seuran asetuksiin, ennen kuin tulokset voi ladata.'); ?>
    <p class="form-actions">
        <?php tiliLaskutuspostiEstettyNappi('Lataa ja merkitse laskutettavaksi'); ?>
        <a class="btn-secondary" href="/admin/oma_seura.php">Seuran asetukset</a>
    </p>
<?php else: ?>
<form method="post">
    <?= csrfKentta() ?>
    <p class="form-actions">
        <button type="submit">Lataa ja merkitse laskutettavaksi</button>
        <a class="btn-secondary" href="/admin/tulokset_vienti.php">Peruuta</a>
    </p>
</form>
<?php endif; ?>
<?php pageEnd(); ?>
