<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/layout.php';

vaadiAjanottaja();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/kisa_liput.php';
require_once __DIR__ . '/../includes/kisa_luonne.php';
require_once __DIR__ . '/../includes/maali_jono.php';
kisaLiputVarmista($pdo);
kisaLuonneVarmista($pdo);

[$ehto, $params] = kilpailuOmistusEhto('k');
$stmt = $pdo->prepare("
    SELECT k.*
    FROM kilpailut k
    WHERE $ehto
      AND (
            (k.kisa_tyyppi = 'normaali' AND COALESCE(k.pirila_synkka, 0) = 0)
            OR COALESCE(k.jaettu_jono, 0) = 1
          )
      AND k.tila <> 'arkisto'
    ORDER BY
        CASE k.tila WHEN 'kaynnissa' THEN 0 WHEN 'luonnos' THEN 1 ELSE 2 END,
        k.paiva DESC
");
$stmt->execute($params);
$kilpailut = $stmt->fetchAll(PDO::FETCH_ASSOC);

pageStart('Ajanotto', ['base' => '../', 'class' => 'ajanotto-valinta-page', 'palvelu' => 'tulokset']);

$voiHallintaan = function_exists('voiKisahallintaan') && voiKisahallintaan();
?>
<style>
.kisa-link.is-disabled {
    opacity: 0.45;
    pointer-events: none;
    cursor: not-allowed;
}
</style>

<h1>Mobiiliajanotto</h1>
<p class="muted">Valitse juoksukisa. <strong>Ruudukko</strong> = paina numeroa. <strong>Maalinappi + Numerot</strong> = toisella laitteella painetaan nappia viivalla, toisella syötetään numerot järjestyksessä. Jaetussa listassa Pirilä-kisan numerot syötetään täällä ja ajat tulevat Pirilästä.</p>
<p class="muted">Paras tila tabletilla: Chrome → valikko → <em>Lisää aloitusnäyttöön</em> (piilottaa osoitepalkin). Ajanotossa ensimmäinen kosketus avaa myös koko näytön.</p>
<p class="muted">Valitse joko ruudukko tai Maalinappi+Numerot. Samaa kisaa ei voi ajaa molemmilla.</p>

<?php if ($voiHallintaan): ?>
    <p class="ajanotto-valinta-hallinta">
        <a class="btn kisa-btn-valitse" href="../admin/index.php">← Hallinta</a>
    </p>
<?php endif; ?>

<?php if (empty($kilpailut)): ?>
    <p class="tyhja">
        Ei normaalikilpailuja.
        <?php if ($voiHallintaan): ?>
            <a href="../admin/kilpailut.php?uusi=1">Luo kilpailu hallinnassa</a>.
        <?php else: ?>
            Pääkäyttäjä luo kilpailun hallinnassa.
        <?php endif; ?>
    </p>
<?php else: ?>
<div class="card-list">
    <?php foreach ($kilpailut as $k): ?>
        <?php
            $linkit = julkinenLinkit($k['id']);
            $moodi = ajanottoMoodiNyt($pdo, (int) $k['id']);
        ?>
        <article class="public-card">
            <h2><?= htmlspecialchars($k['nimi']) ?></h2>
            <p class="muted">
                <span class="badge"><?= htmlspecialchars($k['tila']) ?></span>
                <?php if ($k['paiva']): ?> · <?= htmlspecialchars($k['paiva']) ?><?php endif; ?>
                <?php if ($k['paikka']): ?> · <?= htmlspecialchars($k['paikka']) ?><?php endif; ?>
                <?php if ($moodi === 'jono'): ?> · Maalinappi+Numerot<?php elseif ($moodi === 'ruudukko'): ?> · Ruudukko<?php endif; ?>
            </p>
            <div class="public-links">
                <?php $vainNumerot = (($k['kisa_tyyppi'] ?? '') === 'pirila' || !empty($k['pirila_synkka'])) && !empty($k['jaettu_jono']); ?>
                <?php if (!$vainNumerot): ?>
                    <?php if ($moodi === 'jono'): ?>
                        <span class="kisa-link is-disabled" title="Kisa käyttää Maalinappi+Numerot">Ruudukko</span>
                    <?php else: ?>
                        <a class="kisa-link kisa-link-primary" href="<?= $linkit['mobiili'] ?>">Ruudukko</a>
                    <?php endif; ?>
                    <?php if ($moodi === 'ruudukko'): ?>
                        <span class="kisa-link is-disabled" title="Kisa käyttää ruudukkoa">Maalinappi</span>
                    <?php else: ?>
                        <a class="kisa-link" href="<?= $linkit['nappi'] ?>">Maalinappi</a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($moodi === 'ruudukko'): ?>
                    <span class="kisa-link is-disabled" title="Kisa käyttää ruudukkoa">Numerot</span>
                <?php else: ?>
                    <a class="kisa-link<?= $vainNumerot ? ' kisa-link-primary' : '' ?>" href="<?= $linkit['numerot'] ?>">Numerot</a>
                <?php endif; ?>
                <a href="<?= $linkit['seuranta'] ?>"><?= htmlspecialchars(seurantaOtsikko($k)) ?></a>
                <?php if (kisaNaytaValikko($k, 'emit')): ?>
                    <?php $onViestiAj = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($k); ?>
                    <a class="kisa-link" href="<?= $onViestiAj ? $linkit['viesti_lukija'] : $linkit['emit_lukija'] ?>"><?= $onViestiAj ? 'Viesti-lukija' : 'Emit-lukija' ?></a>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="muted ajanotto-valinta-footer" style="margin-top:1.5rem">
    <?php if ($voiHallintaan): ?>
        <a href="../admin/index.php">Hallinta</a> ·
    <?php endif; ?>
    <a href="../admin/salasana.php">Vaihda salasana</a>
    · <a href="../auth/logout.php">Kirjaudu ulos</a>
</p>

<?php pageEnd(); ?>
