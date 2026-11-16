<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/iof_xml.php';
if (is_readable(__DIR__ . '/../includes/ssl_tulokset.php')) {
    require_once __DIR__ . '/../includes/ssl_tulokset.php';
}
require_once __DIR__ . '/../db.php';

vaadiKisahallinta();
$kilpailu = vaadiValittuKilpailu($pdo);
vaadiKilpailunHallinta($kilpailu);
$kilpailu_id = (int) $kilpailu['id'];

$onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
$live = $onViesti
    ? haeLiveViestiTulokset($pdo, $kilpailu_id)
    : haeLiveTulokset($pdo, $kilpailu_id);
$ryhmat = ryhmitteleLiveTulokset($live);

$fmt = (string) ($_GET['fmt'] ?? 'html');
if ($fmt === 'iof' || $fmt === 'iof30' || $fmt === 'xml') {
    iofXmlLahetaLataus($pdo, $kilpailu, $ryhmat);
}
if ($fmt === 'ssl') {
    header('Location: /public/tulokset_ssl.php?kilpailu_id=' . $kilpailu_id, true, 302);
    exit;
}
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tulokset_' . $kilpailu_id . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM Excelille
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sarja', 'Matka', 'Sija', 'Numero', 'Nimi', 'Seura', 'Aika', 'Maaliaika', 'Status'], ';');
    foreach ($ryhmat as $sarjaNimi => $data) {
        $sija = 0;
        $matkaFmt = formatSarjaMatka($data['matka'] ?? '');
        foreach ($data['maalissa'] ?? [] as $r) {
            $sija++;
            fputcsv($out, [
                $sarjaNimi,
                $matkaFmt,
                $sija,
                $r['numero'] ?? '',
                kilpailijaNimiRivista($r),
                $r['seura'] ?? '',
                isset($r['maali_aika_sec']) && $r['maali_aika_sec'] !== null
                    ? formatAikaSec((int) $r['maali_aika_sec'])
                    : '',
                $r['maaliaika'] ?? '',
                $r['status'] ?? 'OK',
            ], ';');
        }
        foreach ($data['ei_maaleissa'] ?? [] as $r) {
            fputcsv($out, [
                $sarjaNimi,
                $matkaFmt,
                '',
                $r['numero'] ?? '',
                kilpailijaNimiRivista($r),
                $r['seura'] ?? '',
                '',
                '',
                $r['status'] ?? '',
            ], ';');
        }
    }
    fclose($out);
    exit;
}

pageStart('Tulosten vienti', ['admin' => true, 'active' => 'vienti', 'pdo' => $pdo]);
?>

<h1>Tulosten vienti</h1>
<p class="hallinta-takaisin"><a class="btn-secondary" href="index.php">← Kisan hallinta</a></p>
<p class="muted"><?= htmlspecialchars($kilpailu['nimi']) ?></p>

<p class="form-actions">
    <a class="btn kisa-btn-valitse" href="tulokset_vienti.php?fmt=iof">Lataa IOF XML 3.0</a>
    <?php if (!$onViesti && function_exists('sslTuloksetSallittu') && sslTuloksetSallittu($kilpailu)): ?>
        <a class="btn kisa-btn-valitse" href="/public/tulokset_ssl.php?kilpailu_id=<?= (int) $kilpailu_id ?>">Lataa SSL-tulokset</a>
    <?php endif; ?>
    <a class="btn-secondary" href="tulokset_vienti.php?fmt=csv">Lataa CSV</a>
    <button type="button" class="btn-secondary" onclick="window.print()">Tulosta / PDF</button>
</p>
<?php if (!$onViesti): ?>
<p class="muted" style="margin-top:0.5rem">
    SSL-tiedosto on liiton vaatima muoto. Ensimmäinen lataus tältä kisalta maksaa
    <?= htmlspecialchars(function_exists('tiliEuroTeksti') ? tiliEuroTeksti(function_exists('tiliSslHintaEuro') ? tiliSslHintaEuro() : 10) : '10,00 €') ?>
    ja laskutetaan kuukausittain.
</p>
<?php endif; ?>
<p class="muted" style="margin-top:0.5rem">
    IOF XML 3.0 (ResultList) sopii muihin tulospalveluihin ja analyysiohjelmiin.
    Julkinen latauslinkki:
    <a href="/public/tulokset_iof.php?kilpailu_id=<?= (int) $kilpailu_id ?>">/public/tulokset_iof.php?kilpailu_id=<?= (int) $kilpailu_id ?></a>
</p>

<div class="tulokset-vienti-print">
<?php foreach ($ryhmat as $sarjaNimi => $data): ?>
    <section class="kisa-lista-osio" style="margin-bottom:1.5rem">
        <h2><?= htmlspecialchars(sarjaOtsikkoMatka((string) $sarjaNimi, $data['matka'] ?? null)) ?></h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sija</th>
                        <th>Nro</th>
                        <th>Nimi</th>
                        <th>Seura</th>
                        <th>Aika</th>
                        <th>Maaliaika</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sija = 0; foreach ($data['maalissa'] ?? [] as $r): $sija++; ?>
                        <tr>
                            <td><?= $sija ?></td>
                            <td><?= htmlspecialchars((string) ($r['numero'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(kilpailijaNimiRivista($r)) ?></td>
                            <td><?= htmlspecialchars((string) ($r['seura'] ?? '')) ?></td>
                            <td><?= isset($r['maali_aika_sec']) && $r['maali_aika_sec'] !== null
                                ? htmlspecialchars(formatAikaSec((int) $r['maali_aika_sec']))
                                : '—' ?></td>
                            <td><?= htmlspecialchars(substr((string) ($r['maaliaika'] ?? ''), 11, 8) ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($data['maalissa'])): ?>
                        <tr><td colspan="6" class="muted">Ei vielä maaliin tulleita</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
</div>

<style>
@media print {
  .hallinta-takaisin, .form-actions, .site-topbar, .admin-nav, .admin-menu-toggle { display: none !important; }
  .admin-layout, .admin-main, .container { margin: 0 !important; padding: 0 !important; max-width: none !important; }
}
</style>

<?php pageEnd(); ?>
