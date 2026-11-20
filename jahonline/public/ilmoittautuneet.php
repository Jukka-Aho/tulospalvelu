<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/tulospalvelu.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/pirila_mode.php';

$kilpailu_id = isset($_GET['kilpailu_id']) ? (int) $_GET['kilpailu_id'] : 0;

if (!$kilpailu_id) {
    header('Location: index.php');
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailu_id);

if (!$kilpailu) {
    header('Location: index.php');
    exit;
}

vaadiJulkinenNakyvyys($kilpailu);

$seuraSuodatin = julkinenSeuraQ();
$linkit = julkinenLinkit($kilpailu_id, true);
$ryhmat = haeIlmoittautuneetRyhmiteltyna($pdo, $kilpailu_id);
$yhteensaKaikki = 0;
$yhteensa = 0;
foreach ($ryhmat as $g) {
    $yhteensaKaikki += count($g['rivit']);
    foreach ($g['rivit'] as $r) {
        if (riviVastaaSeuraan($r, $seuraSuodatin)) {
            $yhteensa++;
        }
    }
}
$auki = function_exists('ilmoittautuminenJulkinenAuki')
    ? ilmoittautuminenJulkinenAuki($pdo, $kilpailu)
    : ilmoittautuminenAuki($kilpailu);

$extraActions = [];
if ($auki) {
    $extraActions[] = [
        'href' => $linkit['ilmoittautuminen'],
        'label' => 'Ilmoittaudu',
    ];
}
if ($seuraSuodatin !== '') {
    $extraActions[] = [
        'href' => seuraLink($kilpailu_id, $seuraSuodatin, 'lahtolista'),
        'label' => 'Lähtöajat',
    ];
    $extraActions[] = [
        'href' => seuraLink($kilpailu_id, $seuraSuodatin, 'seuranta'),
        'label' => 'Tulokset',
    ];
}

pageStart('Ilmoittautuneet', tulospalveluPageOpts('ilmoittautuneet-page'));
tulospalveluYla($kilpailu, $linkit, 'Ilmoittautuneet', [
    'aktiivinen' => 'ilmoittautuneet',
    'meta_html' => (int) $yhteensa . ' osallistujaa',
    'extra_actions' => $extraActions,
]);
?>

<div class="lb-main">
  <?php if ($seuraSuodatin !== ''): ?>
    <div class="lb-controls">
      <div class="lb-nakyma" role="group" aria-label="Seurasuodatin">
        <span class="lb-chip is-active">Seura: <?= htmlspecialchars($seuraSuodatin) ?></span>
        <a class="lb-chip" href="<?= htmlspecialchars(
            'ilmoittautuneet.php?' . http_build_query(['kilpailu_id' => $kilpailu_id]),
            ENT_QUOTES,
            'UTF-8'
        ) ?>">Näytä kaikki seurat</a>
      </div>
    </div>
  <?php endif; ?>

<?php if ($yhteensa === 0): ?>
  <div class="lb-paneeli lb-hub">
    <p class="lb-notice">
      <?php if ($yhteensaKaikki === 0): ?>
        Ei vielä ilmoittautuneita.
      <?php elseif ($seuraSuodatin !== ''): ?>
        Ei ilmoittautuneita seuralla “<?= htmlspecialchars($seuraSuodatin) ?>”.
      <?php else: ?>
        Ei ilmoittautuneita.
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<?php if (!empty($ryhmat)): ?>
  <?php foreach ($ryhmat as $sarjaAvain => $data): ?>
    <?php
    $sarjaNimi = (string) ($data['nimi'] ?? $sarjaAvain);
    $nakyvat = 0;
    foreach ($data['rivit'] as $rr) {
        if (riviVastaaSeuraan($rr, $seuraSuodatin)) {
            $nakyvat++;
        }
    }
    if ($nakyvat === 0) {
        continue;
    }
    ?>
    <section class="lb-paneeli lb-list-ryhma">
      <div class="lb-otsikko">
        <?= sarjaLinkHtml($kilpailu_id, (string) $sarjaNimi, $data['matka'] ?? null, [], 'lb-sarja-link') ?>
        <span class="lb-otsikko-meta">(<?= (int) $nakyvat ?>)</span>
      </div>
      <div class="table-scroll">
        <table class="lb-table ilmoittautuneet-table">
          <thead>
            <tr>
              <th>Sukunimi</th>
              <th>Etunimi</th>
              <th>Seura</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['rivit'] as $r): ?>
              <?php if (!riviVastaaSeuraan($r, $seuraSuodatin)) {
                  continue;
              } ?>
              <?php
              $etu = trim((string) ($r['etunimi'] ?? ''));
              $suku = trim((string) ($r['sukunimi'] ?? ''));
              if ($etu === '' && $suku === '') {
                  $jaettu = jaaNimiKenttiin((string) ($r['nimi'] ?? ''));
                  $etu = $jaettu['etunimi'];
                  $suku = $jaettu['sukunimi'];
              }
              ?>
              <tr>
                <td><?= htmlspecialchars($suku) ?></td>
                <td><?= htmlspecialchars($etu) ?></td>
                <td><?= seuraLinkHtml($kilpailu_id, $r['seura'] ?? '', 'ilmoittautuneet', [], 'lb-seura-link') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<?php
tulospalveluLoppu();
pageEnd();
?>
