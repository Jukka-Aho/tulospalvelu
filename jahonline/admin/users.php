<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/tili.php';

vaadiPaakayttaja();

require_once __DIR__ . '/../db.php';
tiliVarmistaTaulut($pdo);
kayttajaRoolitVarmista($pdo);

$muokattava_id = isset($_GET['muokkaa']) ? (int) $_GET['muokkaa'] : null;
$viesti = $_GET['viesti'] ?? '';
$virhe = '';
$omaId = (int) kayttajaId();
$omaRooli = kayttajaRooli();

/** Mitä rooleja nykyinen käyttäjä saa luoda. */
function sallitutLuotavatRoolit(): array
{
    if (onAdmin()) {
        return ['paakayttaja', 'ajanottaja', 'lahtopaikka', 'lahtotoiminnat', 'ampumapaikat', 'tv', 'iltarasti', 'admin'];
    }

        return ['ajanottaja', 'lahtopaikka', 'lahtotoiminnat', 'ampumapaikat', 'tv', 'iltarasti'];
}

function voiMuokataKayttajaa(array $kohde): bool
{
    if (onAdmin()) {
        return true;
    }
    // Pääkäyttäjä muokkaa vain omia aliakäyttäjiään
    return (int) ($kohde['paakayttaja_id'] ?? 0) === (int) kayttajaId()
        && in_array($kohde['role'] ?? '', kenttaKayttajaRoolit(), true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }

    $action = $_POST['action'] ?? 'create';
    $sallitut = sallitutLuotavatRoolit();
    $rooli = $_POST['role'] ?? 'ajanottaja';
    if (!in_array($rooli, $sallitut, true)) {
        $rooli = 'ajanottaja';
    }

    $omaSeuraEnnen = tiliHaeSeuraKayttajalle($pdo, $omaId);
    $seuraIdKonteksti = $omaSeuraEnnen ? (int) $omaSeuraEnnen['id'] : null;

    // Admin voi valita seuran; pääkäyttäjä käyttää omaa seuraansa
    $valittuSeuraId = null;
    if (onAdmin() && array_key_exists('seura_id', $_POST)) {
        $rawSeura = trim((string) ($_POST['seura_id'] ?? ''));
        $valittuSeuraId = $rawSeura !== '' ? (int) $rawSeura : null;
    } elseif (!onAdmin()) {
        $valittuSeuraId = $seuraIdKonteksti;
    }
    $emailSeuraKonteksti = $valittuSeuraId ?: $seuraIdKonteksti;

    if ($action === 'create') {
        $parent = null;
        if (in_array($rooli, kenttaKayttajaRoolit(), true)) {
            $parent = onAdmin() && !empty($_POST['paakayttaja_id'])
                ? (int) $_POST['paakayttaja_id']
                : (int) kayttajaId();
        }

        try {
            $email = tiliValidoiSahkoposti($pdo, (string) ($_POST['email'] ?? ''), null, $emailSeuraKonteksti);
            tiliValidoiSalasana((string) ($_POST['password'] ?? ''));
            $viestiKisa = !empty($_POST['viesti_kilpailu']) ? 1 : 0;
            $pirilaKisa = !empty($_POST['suunnistus_pirila']) ? 1 : 0;
            $stmt = $pdo->prepare('
                INSERT INTO users (username, password, role, paakayttaja_id, aktiivinen, email, viesti_kilpailu, suunnistus_pirila)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?)
            ');
            $stmt->execute([
                trim($_POST['username']),
                password_hash($_POST['password'], PASSWORD_DEFAULT),
                $rooli,
                $parent,
                $email,
                $viestiKisa,
                $pirilaKisa,
            ]);
            $uusiId = (int) $pdo->lastInsertId();
            if ($valittuSeuraId && $uusiId > 0) {
                $jasenRooli = ($rooli === 'paakayttaja' || $rooli === 'admin') ? 'omistaja' : 'jasen';
                $palvelut = $rooli === 'iltarasti' ? 'iltarastit' : null;
                tiliAsetaKayttajanSeura($pdo, $uusiId, $valittuSeuraId, $jasenRooli, $palvelut);
            }
            header('Location: users.php?viesti=' . urlencode('Käyttäjä luotu'));
            exit;
        } catch (RuntimeException $e) {
            $virhe = $e->getMessage();
        } catch (PDOException $e) {
            $virhe = 'Käyttäjän luonti epäonnistui (tunnus ehkä jo käytössä).';
        }
    }

    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $kohde = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$kohde || !voiMuokataKayttajaa($kohde)) {
            die('Ei käyttöoikeutta');
        }

        try {
            $kohdeSeura = tiliHaeSeuraKayttajalle($pdo, $id);
            $emailSeuraId = $valittuSeuraId
                ?: ($kohdeSeura ? (int) $kohdeSeura['id'] : $seuraIdKonteksti);
            $email = tiliValidoiSahkoposti($pdo, (string) ($_POST['email'] ?? ''), $id, $emailSeuraId);

            $parentSql = '';
            $parentVal = null;
            if (onAdmin() && in_array($rooli, kenttaKayttajaRoolit(), true)) {
                $parentVal = !empty($_POST['paakayttaja_id']) ? (int) $_POST['paakayttaja_id'] : null;
                $parentSql = ', paakayttaja_id = ?';
            } elseif (onAdmin() && in_array($rooli, ['paakayttaja', 'admin'], true)) {
                $parentVal = null;
                $parentSql = ', paakayttaja_id = ?';
            }

            $viestiKisa = !empty($_POST['viesti_kilpailu']) ? 1 : 0;
            $pirilaKisa = !empty($_POST['suunnistus_pirila']) ? 1 : 0;
            if (!empty($_POST['password'])) {
                tiliValidoiSalasana((string) $_POST['password']);
                $sql = 'UPDATE users SET username = ?, password = ?, role = ?, email = ?, viesti_kilpailu = ?, suunnistus_pirila = ?' . $parentSql . ' WHERE id = ?';
                $params = [trim($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT), $rooli, $email, $viestiKisa, $pirilaKisa];
            } else {
                $sql = 'UPDATE users SET username = ?, role = ?, email = ?, viesti_kilpailu = ?, suunnistus_pirila = ?' . $parentSql . ' WHERE id = ?';
                $params = [trim($_POST['username']), $rooli, $email, $viestiKisa, $pirilaKisa];
            }
            if ($parentSql !== '') {
                $params[] = $parentVal;
            }
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);

            if (onAdmin() && array_key_exists('seura_id', $_POST)) {
                $jasenRooli = ($rooli === 'paakayttaja' || $rooli === 'admin') ? 'omistaja' : 'jasen';
                $palvelut = $rooli === 'iltarasti' ? 'iltarastit' : null;
                tiliAsetaKayttajanSeura($pdo, $id, $valittuSeuraId, $jasenRooli, $palvelut);
            } elseif ($rooli === 'iltarasti' && $valittuSeuraId) {
                tiliAsetaKayttajanSeura($pdo, $id, $valittuSeuraId, 'jasen', 'iltarastit');
            }

            header('Location: users.php?viesti=' . urlencode('Tallennettu'));
            exit;
        } catch (RuntimeException $e) {
            $virhe = $e->getMessage();
            $muokattava_id = $id;
        }
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        if ($id === $omaId) {
            header('Location: users.php?viesti=' . urlencode('Et voi poistaa itseäsi'));
            exit;
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $kohde = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kohde || !voiMuokataKayttajaa($kohde)) {
            die('Ei käyttöoikeutta');
        }
        if (function_exists('tiliPoistaKayttaja')) {
            tiliPoistaKayttaja($pdo, $id, $omaId);
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        header('Location: users.php?viesti=' . urlencode('Poistettu'));
        exit;
    }

    if ($action === 'regenerate_api_token') {
        require_once __DIR__ . '/../includes/api_auth.php';
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $id = $omaId;
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $kohde = $stmt->fetch(PDO::FETCH_ASSOC);
        $saa = $kohde && (((int) $id === $omaId) || voiMuokataKayttajaa($kohde));
        if (!$saa) {
            die('Ei käyttöoikeutta');
        }
        $tok = jahApiKayttajaTokenVarmista($pdo, $id, true);
        $redir = 'users.php?viesti=' . urlencode($tok ? 'API-avain luotu uudelleen' : 'API-avaimen luonti epäonnistui');
        if ((int) $id !== $omaId || $muokattava_id === $id) {
            $redir .= '&muokkaa=' . $id;
        }
        header('Location: ' . $redir);
        exit;
    }
}

if (onAdmin()) {
    $users = $pdo->query('
        SELECT u.*, p.username AS parent_nimi,
               (
                   SELECT GROUP_CONCAT(DISTINCT s.nimi ORDER BY s.nimi SEPARATOR ", ")
                   FROM seura_jasenet j
                   INNER JOIN seurat s ON s.id = j.seura_id AND s.tila = \'aktiivinen\'
                   WHERE j.user_id = u.id AND j.tila = \'aktiivinen\'
               ) AS seurat
        FROM users u
        LEFT JOIN users p ON p.id = u.paakayttaja_id
        ORDER BY FIELD(u.role,\'admin\',\'paakayttaja\',\'ajanottaja\',\'lahtopaikka\',\'lahtotoiminnat\',\'ampumapaikat\',\'tv\',\'iltarasti\'), u.username
    ')->fetchAll(PDO::FETCH_ASSOC);
    $paakayttajat = $pdo->query("SELECT id, username FROM users WHERE role IN ('admin','paakayttaja') ORDER BY username")
        ->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare('
        SELECT u.*, p.username AS parent_nimi,
               (
                   SELECT GROUP_CONCAT(DISTINCT s.nimi ORDER BY s.nimi SEPARATOR ", ")
                   FROM seura_jasenet j
                   INNER JOIN seurat s ON s.id = j.seura_id AND s.tila = \'aktiivinen\'
                   WHERE j.user_id = u.id AND j.tila = \'aktiivinen\'
               ) AS seurat
        FROM users u
        LEFT JOIN users p ON p.id = u.paakayttaja_id
        WHERE u.paakayttaja_id = ? OR u.id = ?
        ORDER BY u.role, u.username
    ');
    $stmt->execute([$omaId, $omaId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $paakayttajat = [];
}

$omaSeura = tiliHaeSeuraKayttajalle($pdo, $omaId);
$seuratLista = [];
if (onAdmin()) {
    try {
        $seuratLista = $pdo->query("
            SELECT s.id, s.nimi, s.tila, p.nimi AS paketti_nimi,
                   (SELECT COUNT(*) FROM seura_jasenet j WHERE j.seura_id = s.id AND j.tila = 'aktiivinen') AS jasenet
            FROM seurat s
            LEFT JOIN paketit p ON p.id = s.paketti_id
            ORDER BY s.nimi
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $seuratLista = [];
    }
} elseif ($omaSeura) {
    $seuratLista = [$omaSeura + [
        'jasenet' => null,
        'paketti_nimi' => $omaSeura['paketti_nimi'] ?? ($omaSeura['paketti_koodi'] ?? ''),
    ]];
}

$muokattava = null;
if ($muokattava_id) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $muokattava_id) {
            $muokattava = $u;
            break;
        }
    }
}

$muokattavaSeuraId = 0;
if ($muokattava) {
    $muokattavaSeuraId = (int) ($muokattava['viimeisin_seura_id'] ?? 0);
    if ($muokattavaSeuraId <= 0) {
        $tmpSeura = tiliHaeSeuraKayttajalle($pdo, (int) $muokattava['id']);
        $muokattavaSeuraId = $tmpSeura ? (int) $tmpSeura['id'] : 0;
    }
}

$seuraValinnat = [];
if (onAdmin()) {
    try {
        $seuraValinnat = $pdo->query("
            SELECT id, nimi FROM seurat
            WHERE tila = 'aktiivinen'
            ORDER BY nimi
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $seuraValinnat = [];
    }
}

require_once __DIR__ . '/../includes/api_auth.php';
$apiTokenKayttajaId = $muokattava ? (int) $muokattava['id'] : $omaId;
$apiTokenNayta = false;
if ($muokattava) {
    $apiTokenNayta = ((int) $muokattava['id'] === $omaId) || voiMuokataKayttajaa($muokattava);
} else {
    $apiTokenNayta = true; // oma avain listanäkymässä
}
$omaApiToken = $apiTokenNayta
    ? (jahApiKayttajaTokenVarmista($pdo, $apiTokenKayttajaId, false) ?? '')
    : '';

pageStart('Käyttäjät', ['admin' => true, 'active' => 'users', 'pdo' => $pdo]);
?>

<h1>Käyttäjähallinta</h1>
<p class="muted">
    <?php if (onAdmin()): ?>
        Järjestelmän pääkäyttäjä näkee kaikki. Luo <strong>pääkäyttäjiä</strong> (omat kilpailut)
        ja heidän alle ajanottajia / lähtötoiminta- / ampumapaikka- / TV- / iltarasti-käyttäjiä.
    <?php else: ?>
        Hallitse omia käyttäjiäsi: <strong>ajanottaja</strong> (mobiiliajanotto),
        <strong>lähtötoiminnat</strong> (lähtöluenta, nimenhuuto, lähtökello),
        <strong>ampumapaikat</strong>, <strong>TV-näyttö</strong> ja <strong>iltarastit</strong>.
    <?php endif; ?>
</p>

<?php if ($viesti): ?><p class="notice"><?= htmlspecialchars($viesti) ?></p><?php endif; ?>
<?php if ($virhe): ?><p class="error"><?= htmlspecialchars($virhe) ?></p><?php endif; ?>

<?php if ($apiTokenNayta): ?>
<div class="public-card" style="margin-bottom:1.25rem;">
    <h2>Pirilä API-avain<?= $muokattava && (int) $muokattava['id'] !== $omaId ? ' (käyttäjä)' : ' (oma)' ?></h2>
    <p class="muted">
        Käyttäjäkohtainen avain HkKisaWin / ViestiWin -yhteyteen
        (<strong>Hae kilpailut</strong>). Kopioi Pirilän JAHOnline API -ikkunaan.
    </p>
    <p>
        <strong>API-avain:</strong>
        <?php if ($omaApiToken !== ''): ?>
            <code class="token"><?= htmlspecialchars($omaApiToken) ?></code>
        <?php else: ?>
            <span class="error">puuttuu</span>
        <?php endif; ?>
    </p>
    <form method="post" class="inline-form" onsubmit="return confirm('Luodaanko uusi API-avain? Vanha lakkaa toimimasta.');">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="regenerate_api_token">
        <input type="hidden" name="id" value="<?= (int) $apiTokenKayttajaId ?>">
        <button type="submit" class="btn-secondary btn-small">Luo uusi avain</button>
    </form>
</div>
<?php endif; ?>

<h2><?= $muokattava ? 'Muokkaa käyttäjää' : 'Uusi käyttäjä' ?></h2>

<form method="post" class="form-grid">
    <?= csrfKentta() ?>
    <input type="hidden" name="action" value="<?= $muokattava ? 'update' : 'create' ?>">
    <?php if ($muokattava): ?>
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
    <?php endif; ?>

    <label>Käyttäjätunnus</label>
    <input name="username" required value="<?= htmlspecialchars($muokattava['username'] ?? '') ?>">

    <label>Sähköposti</label>
    <input type="email" name="email" required value="<?= htmlspecialchars($muokattava['email'] ?? ($_POST['email'] ?? '')) ?>" autocomplete="email">

    <label>Salasana<?= $muokattava ? ' (tyhjä = ei vaihdu)' : '' ?></label>
    <input type="password" name="password" <?= $muokattava ? '' : 'required minlength="8"' ?> autocomplete="new-password">
    <p class="muted"><?= htmlspecialchars(tiliSalasanaVaatimusTeksti()) ?></p>

    <label>Rooli</label>
    <select name="role">
        <?php foreach (sallitutLuotavatRoolit() as $r): ?>
            <option value="<?= $r ?>" <?= (($muokattava['role'] ?? '') === $r) ? 'selected' : '' ?>>
                <?= htmlspecialchars(rooliLabel($r)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <fieldset class="omat-tyyppi" style="border:1px solid var(--border);border-radius:10px;padding:.75rem 1rem;margin:.5rem 0 1rem">
        <legend>Tapahtumatyypit</legend>
        <p class="muted" style="margin-top:0">Seuran palvelut määräävät mitä voi luoda. Alla käyttäjäkohtaiset lisäominaisuudet.</p>
        <label class="checkbox-label">
            <input type="checkbox" name="viesti_kilpailu" value="1"<?= !empty($muokattava['viesti_kilpailu']) ? ' checked' : '' ?>>
            Viestikilpailu
        </label>
        <p class="muted">Suunnistusta luodessa voi valita henkilökohtaisen tai viestin. Rastilippu on toistaiseksi vain henkilökohtaisissa.</p>
        <label class="checkbox-label">
            <input type="checkbox" name="suunnistus_pirila" value="1"<?= !empty($muokattava['suunnistus_pirila']) ? ' checked' : '' ?>>
            Suunnistus-Pirilä
        </label>
        <p class="muted">Saa luoda Pirilä-synkattuja suunnistustapahtumia, kun seuran Suunnistus-Pirilä on päällä. Pääkäyttäjä perii seuran oikeuden.</p>
    </fieldset>

    <?php if (onAdmin()): ?>
    <label>Pääkäyttäjä (aliakäyttäjälle)</label>
    <select name="paakayttaja_id">
        <option value="">— Ei / itsenäinen —</option>
        <option value="<?= $omaId ?>" <?= ((int) ($muokattava['paakayttaja_id'] ?? 0) === $omaId) ? 'selected' : '' ?>>— Minä —</option>
        <?php foreach ($paakayttajat as $p): ?>
            <option
                value="<?= (int) $p['id'] ?>"
                <?= ((int) ($muokattava['paakayttaja_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>
            ><?= htmlspecialchars($p['username']) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="muted">Admin voi vaihtaa kenttäkäyttäjän pääkäyttäjän. Vaikuttaa kisaoikeuksiin.</p>

    <label>Seura</label>
    <select name="seura_id">
        <option value="">— Ei seuraa —</option>
        <?php foreach ($seuraValinnat as $s): ?>
            <option
                value="<?= (int) $s['id'] ?>"
                <?= $muokattavaSeuraId === (int) $s['id'] ? 'selected' : '' ?>
            ><?= htmlspecialchars((string) $s['nimi']) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="muted">Käyttäjän aktiivinen seura (jäsenyys + oletusseura). </p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit"><?= $muokattava ? 'Tallenna' : 'Luo käyttäjä' ?></button>
        <?php if ($muokattava): ?><a class="btn-secondary" href="users.php">Peruuta</a><?php endif; ?>
    </div>
</form>

<h2>Käyttäjät</h2>
<div class="table-scroll">
<table class="data-table">
    <tr>
        <th>Tunnus</th>
        <th>Sähköposti</th>
        <th>Rooli</th>
        <th>Seura</th>
        <th>Pääkäyttäjä</th>
        <th>Luotu</th>
        <th></th>
    </tr>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars(rooliLabel($u['role'])) ?></td>
            <td><?= htmlspecialchars($u['seurat'] ?? '—') ?></td>
            <td><?= htmlspecialchars($u['parent_nimi'] ?? '—') ?></td>
            <td><?= htmlspecialchars($u['created'] ?? '') ?></td>
            <td class="actions">
                <?php if (voiMuokataKayttajaa($u) || (int) $u['id'] === $omaId): ?>
                    <a href="users.php?muokkaa=<?= (int) $u['id'] ?>">Muokkaa</a>
                <?php endif; ?>
                <?php if ((int) $u['id'] !== $omaId && voiMuokataKayttajaa($u)): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Poistetaanko?');">
                        <?= csrfKentta() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn-danger btn-small">Poista</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</div>

<section class="admin-seurat-osio" style="margin-top:2rem">
    <h2>Seurat</h2>
    <p class="muted">
        Seurat ja jäsenyydet.
        <a href="oma_seura.php">Oma seura</a>
        <?php if (onAdmin()): ?>
            · <a href="seurat.php">Seurojen hallinta</a>
        <?php endif; ?>
    </p>

    <?php if ($seuratLista === []): ?>
        <p class="tyhja">Ei seuroja vielä. <a href="oma_seura.php">Luo tai hallitse omaa seuraa</a>.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <tr>
                    <th>Seura</th>
                    <th>Paketti</th>
                    <th>Jäseniä</th>
                    <th></th>
                </tr>
                <?php foreach ($seuratLista as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($s['nimi'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($s['paketti_nimi'] ?? $s['paketti_koodi'] ?? '—')) ?></td>
                        <td><?= isset($s['jasenet']) && $s['jasenet'] !== null ? (int) $s['jasenet'] : '—' ?></td>
                        <td class="actions">
                            <a href="oma_seura.php">Avaa</a>
                            <?php if (onAdmin()): ?>
                                · <a href="seurat.php">Hallinta</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php pageEnd(); ?>
