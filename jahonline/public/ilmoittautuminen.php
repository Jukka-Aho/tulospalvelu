<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/tulospalvelu.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/maksu.php';
maksuVarmista($pdo);

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

// Sama julkisuusportti kuin kilpailun aloitussivulla
vaadiJulkinenNakyvyys($kilpailu);

$linkit = julkinenLinkit($kilpailu_id, true);
$maksuEur = ilmoMaksuEur($kilpailu);
$sarjat = haeSarjatIlmoittautumiseen($pdo, $kilpailu_id);
$aikatauluAuki = ilmoittautuminenAuki($kilpailu);
$auki = $aikatauluAuki && $sarjat !== [];
$virhe = '';
$onnistui = null;
$form = [
    'etunimi' => '',
    'sukunimi' => '',
    'seura' => '',
    'sarja_id' => '',
];

$pirilaIlmo = (($kilpailu['kisa_tyyppi'] ?? '') === 'pirila');

$suljettuSyy = '';
if (!$auki) {
    if (!$aikatauluAuki) {
        if (empty($kilpailu['ilmoittautuminen_auki']) && ($kilpailu['tila'] ?? '') !== 'ilmoittautuminen') {
            $suljettuSyy = 'Ilmoittautuminen ei ole käytössä tälle kilpailulle.';
        } elseif (($kilpailu['tila'] ?? '') === 'valmis') {
            $suljettuSyy = 'Kilpailu on päättynyt — ilmoittautuminen on suljettu.';
        } elseif (!empty($kilpailu['ilmo_alkaa']) && time() < strtotime((string) $kilpailu['ilmo_alkaa'])) {
            $suljettuSyy = 'Ilmoittautuminen alkaa '
                . date('j.n.Y H:i', strtotime((string) $kilpailu['ilmo_alkaa'])) . '.';
        } elseif (!empty($kilpailu['ilmo_paattyy']) && time() > strtotime((string) $kilpailu['ilmo_paattyy'])) {
            $suljettuSyy = 'Ilmoittautuminen päättyi '
                . date('j.n.Y H:i', strtotime((string) $kilpailu['ilmo_paattyy'])) . '.';
        } else {
            $suljettuSyy = 'Ilmoittautuminen ei ole auki tälle kilpailulle.';
        }
    } else {
        $suljettuSyy = $pirilaIlmo
            ? 'Sarjoja ei ole vielä. Luo ne Pirilässä ja yhdistä kisa, tai lisää sarjat hallinnassa.'
            : 'Sarjoja ei ole vielä määritelty. Ilmoittautuminen avataan kun sarjat on luotu.';
    }
}

csrfToken();

$tallennaIlmo = static function (
    PDO $pdo,
    int $kilpailuId,
    int $sarjaId,
    array $nimet,
    ?string $seura,
    ?float $maksuEur,
    bool $annaNumero
): array {
    $maksuTila = $maksuEur !== null ? 'odottaa' : 'ei_vaadita';
    $numero = null;
    $keskhyl = null;
    if ($annaNumero) {
        $numero = seuraavaKilpailijaNumero($pdo, $kilpailuId);
        if ($numero <= 0) {
            throw new RuntimeException('Ei vapaita kilpailijanumeroita.');
        }
        $keskhyl = 'N';
    }
    $stmt = $pdo->prepare('
        INSERT INTO kilpailijat
            (kilpailu_id, sarja_id, numero, nimi, etunimi, sukunimi, seura, status,
             keskhyl, maksu_tila, maksu_eur, ilmoittautunut_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, \'OK\', ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $kilpailuId,
        $sarjaId,
        $numero,
        $nimet['nimi'],
        $nimet['etunimi'],
        $nimet['sukunimi'],
        $seura,
        $keskhyl,
        $maksuTila,
        $maksuEur,
    ]);
    $id = (int) $pdo->lastInsertId();
    $viite = $maksuEur !== null ? maksuTallennaViite($pdo, $id) : null;

    return [
        'id' => $id,
        'numero' => $numero,
        'maksu_tila' => $maksuTila,
        'maksu_eur' => $maksuEur,
        'maksu_viite' => $viite,
        'nimi' => $nimet['nimi'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auki) {
        $virhe = $suljettuSyy;
    } elseif (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        $virhe = 'Virheellinen pyyntö. Lataa sivu uudelleen.';
    } else {
        $form['etunimi'] = trim((string) ($_POST['etunimi'] ?? ''));
        $form['sukunimi'] = trim((string) ($_POST['sukunimi'] ?? ''));
        $form['seura'] = trim((string) ($_POST['seura'] ?? ''));
        $form['sarja_id'] = (string) (int) ($_POST['sarja_id'] ?? 0);

        $sarjaId = (int) $form['sarja_id'];
        $sarjaOk = false;
        $sarjaNimi = '';
        foreach ($sarjat as $s) {
            if ((int) $s['id'] === $sarjaId) {
                $sarjaOk = true;
                $sarjaNimi = $s['nimi'];
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
        } elseif (empty($sarjat)) {
            $virhe = 'Kilpailuun ei ole vielä sarjoja.';
        } else {
            $seuraVal = $form['seura'] !== '' ? $form['seura'] : null;
            $onnistuiRivi = static function (array $tallennettu, array $form, string $sarjaNimi): array {
                return [
                    'id' => $tallennettu['id'],
                    'numero' => $tallennettu['numero'] ?? null,
                    'nimi' => $tallennettu['nimi'],
                    'etunimi' => $form['etunimi'],
                    'sukunimi' => $form['sukunimi'],
                    'seura' => $form['seura'],
                    'sarja' => $sarjaNimi,
                    'maksu_tila' => $tallennettu['maksu_tila'],
                    'maksu_eur' => $tallennettu['maksu_eur'],
                    'maksu_viite' => $tallennettu['maksu_viite'] ?? null,
                ];
            };
            $virhe = 'Ilmoittautuminen epäonnistui. Yritä uudelleen.';
            for ($yritys = 0; $yritys < 5; $yritys++) {
                try {
                    $tallennettu = $tallennaIlmo($pdo, $kilpailu_id, $sarjaId, $nimet, $seuraVal, $maksuEur, $pirilaIlmo);
                    $onnistui = $onnistuiRivi($tallennettu, $form, $sarjaNimi);
                    $form = ['etunimi' => '', 'sukunimi' => '', 'seura' => '', 'sarja_id' => ''];
                    $virhe = '';
                    break;
                } catch (RuntimeException $e) {
                    $virhe = $e->getMessage();
                    break;
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || $yritys === 4) {
                        $virhe = 'Ilmoittautuminen epäonnistui. Yritä uudelleen.';
                        break;
                    }
                }
            }
        }
    }
}

$metaPalat = [];
if (!empty($kilpailu['paiva'])) {
    $metaPalat[] = htmlspecialchars(formatPaiva($kilpailu['paiva']), ENT_QUOTES, 'UTF-8');
}
if (!empty($kilpailu['paikka'])) {
    $metaPalat[] = htmlspecialchars((string) $kilpailu['paikka'], ENT_QUOTES, 'UTF-8');
}
if (!empty($kilpailu['ilmo_paattyy'])) {
    $metaPalat[] = 'päättyy ' . htmlspecialchars(date('j.n.Y H:i', strtotime((string) $kilpailu['ilmo_paattyy'])), ENT_QUOTES, 'UTF-8');
}
if ($maksuEur !== null) {
    $metaPalat[] = 'maksu ' . htmlspecialchars(formatEuro($maksuEur), ENT_QUOTES, 'UTF-8');
}

pageStart('Ilmoittautuminen', tulospalveluPageOpts('ilmoittautuminen-page'));
tulospalveluYla($kilpailu, $linkit, 'Ilmoittautuminen', [
    'aktiivinen' => 'ilmoittautuminen',
    'meta_html' => $metaPalat !== [] ? implode(' · ', $metaPalat) : null,
]);
?>

<div class="lb-main">
  <div class="lb-paneeli lb-hub lb-form-paneeli">

<?php if ($onnistui): ?>
    <p class="lb-notice lb-notice--ok">
        <strong>Ilmoittautuminen vastaanotettu.</strong><br>
        <?= htmlspecialchars($onnistui['etunimi']) ?> <?= htmlspecialchars($onnistui['sukunimi']) ?>
        · <?= htmlspecialchars($onnistui['sarja']) ?>
        <?php if ($onnistui['seura'] !== ''): ?>
            · <?= htmlspecialchars($onnistui['seura']) ?>
        <?php endif; ?>
        <?php if (!empty($onnistui['numero'])): ?>
            · nro <?= (int) $onnistui['numero'] ?>
        <?php endif; ?>
    </p>

    <?php if (($onnistui['maksu_tila'] ?? '') === 'odottaa' && $onnistui['maksu_eur'] !== null): ?>
        <?php maksuOhjeHtml(
            $kilpailu,
            (float) $onnistui['maksu_eur'],
            isset($onnistui['maksu_viite']) ? (string) $onnistui['maksu_viite'] : null,
            trim($onnistui['sukunimi'] . ' ' . $onnistui['etunimi'])
        ); ?>
    <?php endif; ?>

    <div class="lb-hub-linkit">
        <a class="lb-hub-link lb-hub-link--primary" href="<?= htmlspecialchars($linkit['ilmoittautuminen']) ?>">Ilmoita toinen</a>
        <a class="lb-hub-link" href="<?= htmlspecialchars($linkit['ilmoittautuneet']) ?>">Ilmoittautuneet</a>
        <a class="lb-hub-link" href="<?= htmlspecialchars($linkit['kilpailu']) ?>">Takaisin kilpailuun</a>
    </div>
<?php elseif (!$auki): ?>
    <p class="lb-notice"><?= htmlspecialchars($suljettuSyy) ?></p>
    <div class="lb-hub-linkit">
      <a class="lb-hub-link" href="<?= htmlspecialchars($linkit['kilpailu']) ?>">Takaisin kilpailuun</a>
    </div>
<?php elseif (empty($sarjat)): ?>
    <p class="lb-notice"><?= $pirilaIlmo
        ? 'Sarjoja ei ole vielä. Luo ne Pirilässä ja yhdistä kisa, tai lisää sarjat hallinnassa.'
        : 'Sarjoja ei ole vielä määritelty. Ilmoittautuminen avataan kun sarjat on luotu.' ?></p>
    <div class="lb-hub-linkit">
      <a class="lb-hub-link" href="<?= htmlspecialchars($linkit['kilpailu']) ?>">Takaisin kilpailuun</a>
    </div>
<?php else: ?>

    <?php if ($virhe !== ''): ?>
        <p class="lb-notice lb-notice--virhe"><?= htmlspecialchars($virhe) ?></p>
    <?php endif; ?>

    <form method="post" class="form-grid ilmoittautuminen-form lb-form" autocomplete="on">
        <?= csrfKentta() ?>

        <label for="etunimi">Etunimi</label>
        <input type="text" id="etunimi" name="etunimi" required maxlength="100"
               value="<?= htmlspecialchars($form['etunimi']) ?>"
               autocomplete="given-name" autofocus>

        <label for="sukunimi">Sukunimi</label>
        <input type="text" id="sukunimi" name="sukunimi" required maxlength="100"
               value="<?= htmlspecialchars($form['sukunimi']) ?>"
               autocomplete="family-name">

        <label for="seura">Seura / joukkue <span class="lb-muted">(valinnainen)</span></label>
        <input type="text" id="seura" name="seura" maxlength="255"
               value="<?= htmlspecialchars($form['seura']) ?>">

        <label for="sarja_id">Sarja</label>
        <select id="sarja_id" name="sarja_id" required>
            <option value="">— Valitse sarja —</option>
            <?php foreach ($sarjat as $s): ?>
                <option value="<?= (int) $s['id'] ?>"
                    <?= (string) (int) $s['id'] === $form['sarja_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(sarjaOtsikkoMatka($s['nimi'], $s['matka'] ?? '')) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($pirilaIlmo): ?>
        <p class="lb-muted form-hint">
            Saat kilpailijanumeron ilmoittautumisen yhteydessä. Tiedot siirtyvät
            ajanottokoneelle, kun kisa on yhdistetty Pirilään.
        </p>
        <?php else: ?>
        <p class="lb-muted form-hint">
            Kilpailunumero annetaan myöhemmin järjestäjän toimesta, ei tässä lomakkeessa.
        </p>
        <?php endif; ?>

        <?php if ($maksuEur !== null): ?>
        <p class="lb-muted form-hint">
            Osallistumismaksu <?= htmlspecialchars(formatEuro($maksuEur)) ?> tilisiirrolla.
            Ilmoittautumisen jälkeen saat viitenumeron
            <?php if (!empty($kilpailu['ilmo_iban'])): ?>
                ja tilinumeron.
            <?php else: ?>
                .
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit">Ilmoittaudu</button>
            <a class="btn-secondary" href="<?= htmlspecialchars($linkit['kilpailu']) ?>">Peruuta</a>
        </div>
    </form>

    <p class="lb-hub-ohje">
        <a href="<?= htmlspecialchars($linkit['ilmoittautuneet']) ?>">Katso ilmoittautuneet →</a>
    </p>

<?php endif; ?>

  </div>
</div>

<?php
tulospalveluLoppu();
pageEnd();
?>
