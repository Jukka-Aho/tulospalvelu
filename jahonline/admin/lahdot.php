<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/kilpailu_context.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/layout.php';

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
$kilpailu_id = (int) $kilpailu['id'];
$pirila = pirilaSynkkaPaalla($kilpailu);
$muokattava_id = isset($_GET['muokkaa']) ? (int) $_GET['muokkaa'] : null;

estaaMuokkausJosPirila($kilpailu);

if ($pirila && $muokattava_id) {
    header('Location: lahdot.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!tarkistaCsrf($_POST['csrf'] ?? '')) {
        die('Virheellinen pyyntö');
    }

    $action = $_POST['action'] ?? 'create';
    $lahtoaika = parseDatetimeSec($_POST['lahtoaika'] ?? '');

    if ($action === 'create') {
        if ($lahtoaika === null) {
            header('Location: lahdot.php?virhe=' . urlencode('Virheellinen lähtöaika'));
            exit;
        }
        $stmt = $pdo->prepare('
            INSERT INTO lahdot (kilpailu_id, nimi, lahtoaika)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([
            $kilpailu_id,
            $_POST['nimi'],
            $lahtoaika
        ]);
    }

    if ($action === 'update') {
        if ($lahtoaika === null) {
            header('Location: lahdot.php?virhe=' . urlencode('Virheellinen lähtöaika'));
            exit;
        }
        $stmt = $pdo->prepare('
            UPDATE lahdot
            SET nimi = ?, lahtoaika = ?
            WHERE id = ? AND kilpailu_id = ?
        ');
        $stmt->execute([
            $_POST['nimi'],
            $lahtoaika,
            $_POST['id'],
            $kilpailu_id
        ]);
        // Lähtöajan muutos → laske netot uudelleen
        uudelleenLaskeSarjanAjat($pdo, $kilpailu_id);
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM lahdot WHERE id = ? AND kilpailu_id = ?');
        $stmt->execute([$_POST['id'], $kilpailu_id]);
    }

    $redir = 'lahdot.php';
    if (in_array($action, ['create', 'update'], true)
        && $lahtoaika !== null
        && function_exists('lahtoAikaPoikkeaaKisapaivasta')
        && lahtoAikaPoikkeaaKisapaivasta($kilpailu, $lahtoaika)
    ) {
        $pvmTeksti = function_exists('formatPaiva')
            ? formatPaiva((string) ($kilpailu['paiva'] ?? ''))
            : (string) ($kilpailu['paiva'] ?? '');
        $redir .= '?varoitus=' . urlencode(
            'Lähtöaika ei ole kisapäivänä (' . $pvmTeksti . '). Nettoajat lasketaan tästä kellosta.'
        );
    }
    header('Location: ' . $redir);
    exit;
}

$stmt = $pdo->prepare('
    SELECT *
    FROM lahdot
    WHERE kilpailu_id = ?
    ORDER BY lahtoaika
');
$stmt->execute([$kilpailu_id]);
$lahdot = $stmt->fetchAll(PDO::FETCH_ASSOC);

$muokattava = null;
if ($muokattava_id) {
    $stmt = $pdo->prepare('SELECT * FROM lahdot WHERE id = ? AND kilpailu_id = ?');
    $stmt->execute([$muokattava_id, $kilpailu_id]);
    $muokattava = $stmt->fetch(PDO::FETCH_ASSOC);
}

pageStart('Lähdöt', ['admin' => true, 'active' => 'lahdot', 'pdo' => $pdo]);
$lahdotVirhe = (string) ($_GET['virhe'] ?? '');
$lahdotVaroitus = (string) ($_GET['varoitus'] ?? '');
$kisaPvm = function_exists('kisapaivaYmd') ? kisapaivaYmd($kilpailu) : substr((string) ($kilpailu['paiva'] ?? ''), 0, 10);
?>

<h1>Lähdöt</h1>
<p class="hallinta-takaisin"><a class="btn-secondary" href="index.php">← Kisan hallinta</a></p>
<p class="muted"><?= htmlspecialchars($kilpailu['nimi']) ?></p>

<?php pirilaVirheIlmoitus(); ?>
<?php pirilaTilaIlmoitus($kilpailu); ?>

<?php if ($lahdotVirhe !== ''): ?>
    <p class="notice notice-varoitus"><?= htmlspecialchars($lahdotVirhe) ?></p>
<?php endif; ?>
<?php if ($lahdotVaroitus !== ''): ?>
    <p class="notice notice-varoitus"><?= htmlspecialchars($lahdotVaroitus) ?></p>
<?php endif; ?>

<?php if (!$pirila): ?>

<h2><?= $muokattava ? 'Muokkaa lähtöä' : 'Lisää lähtö' ?></h2>

<form method="post" class="form-grid">
    <?= csrfKentta() ?>
    <input type="hidden" name="action" value="<?= $muokattava ? 'update' : 'create' ?>">
    <?php if ($muokattava): ?>
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
    <?php endif; ?>

    <label>Nimi</label>
    <input type="text" name="nimi" value="<?= htmlspecialchars($muokattava['nimi'] ?? '') ?>">

    <label>Lähtöaika</label>
    <?php
    $lahtoLocal = $muokattava ? datetimeLocalSec($muokattava['lahtoaika']) : '';
    $lahtoPvm = '';
    $lahtoH = '';
    $lahtoM = '';
    $lahtoS = '';
    if ($lahtoLocal === '' && $kisaPvm !== '') {
        $lahtoPvm = $kisaPvm;
    }
    if ($lahtoLocal !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})$/', $lahtoLocal, $lm)) {
        $lahtoPvm = $lm[1];
        $lahtoH = $lm[2];
        $lahtoM = $lm[3];
        $lahtoS = $lm[4];
    } elseif ($lahtoLocal !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/', $lahtoLocal, $lm)) {
        $lahtoPvm = $lm[1];
        $lahtoH = $lm[2];
        $lahtoM = $lm[3];
        $lahtoS = '00';
    }
    ?>
    <div class="lahtoaika-rivi">
        <input type="date" id="lahto_pvm" value="<?= htmlspecialchars($lahtoPvm) ?>" required aria-label="Lähtöpäivä">
        <div class="lahtoaika-kello" role="group" aria-label="Lähtökello sekunteineen">
            <input type="number" id="lahto_h" class="lahtoaika-osa" min="0" max="23" step="1"
                   inputmode="numeric" required placeholder="tt"
                   value="<?= htmlspecialchars($lahtoH) ?>" aria-label="Tunnit">
            <span class="lahtoaika-erotin" aria-hidden="true">:</span>
            <input type="number" id="lahto_m" class="lahtoaika-osa" min="0" max="59" step="1"
                   inputmode="numeric" required placeholder="mm"
                   value="<?= htmlspecialchars($lahtoM) ?>" aria-label="Minuutit">
            <span class="lahtoaika-erotin" aria-hidden="true">:</span>
            <input type="number" id="lahto_s" class="lahtoaika-osa" min="0" max="59" step="1"
                   inputmode="numeric" required placeholder="ss"
                   value="<?= htmlspecialchars($lahtoS) ?>" aria-label="Sekunnit">
        </div>
        <button type="button" class="btn-small btn-secondary kisa-btn-muokkaa" id="btnLahtoaikaNyt">Aseta nyt</button>
    </div>
    <input type="hidden" name="lahtoaika" id="lahtoaika" value="<?= htmlspecialchars($lahtoLocal) ?>" required>
    <p class="muted form-hint">Syötä päivä ja kello sekunnin tarkkuudella (tt:mm:ss). ”Aseta nyt” täyttää painamishetken ajan.</p>
    <p class="notice notice-varoitus" id="lahtoPaivaVaroitus" hidden>
        Lähtöaika ei ole kisapäivänä<?= $kisaPvm !== '' ? (' (' . htmlspecialchars(function_exists('formatPaiva') ? formatPaiva($kisaPvm) : $kisaPvm) . ')') : '' ?>.
        Nettoajat lasketaan tästä kellosta.
    </p>

    <div class="form-actions">
        <button type="submit"><?= $muokattava ? 'Tallenna' : 'Lisää lähtö' ?></button>
        <?php if ($muokattava): ?>
            <a class="btn-secondary" href="lahdot.php">Peruuta</a>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
    var pvm = document.getElementById('lahto_pvm');
    var h = document.getElementById('lahto_h');
    var m = document.getElementById('lahto_m');
    var s = document.getElementById('lahto_s');
    var hidden = document.getElementById('lahtoaika');
    var btn = document.getElementById('btnLahtoaikaNyt');
    var varo = document.getElementById('lahtoPaivaVaroitus');
    var kisaPvm = <?= json_encode($kisaPvm, JSON_UNESCAPED_UNICODE) ?>;
    if (!pvm || !h || !m || !s || !hidden || !btn) return;

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function raja(el, max) {
        var v = parseInt(el.value, 10);
        if (isNaN(v)) {
            el.value = '';
            return '';
        }
        if (v < 0) v = 0;
        if (v > max) v = max;
        el.value = pad(v);
        return el.value;
    }

    function synkkaa() {
        var pv = String(pvm.value || '').trim();
        var hh = raja(h, 23);
        var mm = raja(m, 59);
        var ss = raja(s, 59);
        if (!pv || hh === '' || mm === '' || ss === '') {
            hidden.value = '';
            if (varo) varo.hidden = true;
            return false;
        }
        hidden.value = pv + 'T' + hh + ':' + mm + ':' + ss;
        if (varo) {
            varo.hidden = !(kisaPvm && pv !== kisaPvm);
        }
        return true;
    }

    function onkoAikaa() {
        return String(hidden.value || '').trim() !== ''
            || String(pvm.value || '').trim() !== ''
            || String(h.value || '').trim() !== ''
            || String(m.value || '').trim() !== ''
            || String(s.value || '').trim() !== '';
    }

    [pvm, h, m, s].forEach(function (el) {
        el.addEventListener('change', synkkaa);
        el.addEventListener('input', synkkaa);
        el.addEventListener('blur', synkkaa);
    });

    btn.addEventListener('click', function () {
        if (onkoAikaa()) {
            if (!window.confirm('Haluatko asettaa tämän kellon ajan lähtöajaksi?')) {
                return;
            }
        }
        var d = new Date();
        pvm.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        h.value = pad(d.getHours());
        m.value = pad(d.getMinutes());
        s.value = pad(d.getSeconds());
        synkkaa();
        s.focus();
    });

    var form = pvm.closest('form');
    if (form) {
        form.addEventListener('submit', function (ev) {
            if (!synkkaa()) {
                ev.preventDefault();
                window.alert('Anna lähtöpäivä ja kello sekunteineen.');
            }
        });
    }

    synkkaa();
})();
</script>

<?php endif; ?>

<section class="kisa-lista-osio">
    <div class="kisa-lista-header">
        <h2>Lähtölista</h2>
        <p class="muted">Sarjojen yhteiset lähdöt. ”Lähtö nyt” asettaa kellonajan heti ja laskee nettoajat uudelleen.</p>
    </div>

    <?php if ($lahdot === []): ?>
        <p class="home-empty muted">Ei lähtöjä vielä.</p>
    <?php else: ?>
        <div class="kisa-lista">
            <?php foreach ($lahdot as $lahto): ?>
                <?php
                $lid = (int) $lahto['id'];
                $muokataan = $muokattava && (int) ($muokattava['id'] ?? 0) === $lid;
                $aikaNaytto = (string) ($lahto['lahtoaika'] ?? '');
                $kello = (strlen($aikaNaytto) >= 19) ? substr($aikaNaytto, 11, 8) : '';
                $pvm = (strlen($aikaNaytto) >= 10) ? substr($aikaNaytto, 0, 10) : '';
                ?>
                <article class="kisa-lista-kortti<?= $muokataan ? ' is-selected' : '' ?>">
                    <div class="kisa-lista-info">
                        <div class="kisa-lista-otsikko">
                            <h3><?= htmlspecialchars((string) ($lahto['nimi'] ?? 'Lähtö')) ?></h3>
                            <?php if ($muokataan): ?>
                                <span class="kisa-lista-valittu">Muokataan</span>
                            <?php endif; ?>
                        </div>
                        <p class="kisa-lista-meta muted">
                            <?php if ($pvm !== ''): ?>
                                <?= htmlspecialchars(formatPaiva($pvm)) ?>
                            <?php endif; ?>
                            <?php if ($pvm !== '' && $kello !== ''): ?> · <?php endif; ?>
                            <?php if ($kello !== ''): ?>
                                <strong class="lahto-kello"><?= htmlspecialchars($kello) ?></strong>
                            <?php else: ?>
                                Ei lähtöaikaa
                            <?php endif; ?>
                            <?php if (function_exists('lahtoAikaPoikkeaaKisapaivasta') && lahtoAikaPoikkeaaKisapaivasta($kilpailu, $aikaNaytto)): ?>
                                <br><span class="notice notice-varoitus" style="display:inline-block;margin-top:0.35rem">Ei kisapäivänä — nettoajat voivat olla päiviä pitkiä</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if (!$pirila): ?>
                        <div class="kisa-lista-toiminnot" role="group" aria-label="Lähdön toiminnot">
                            <button
                                type="button"
                                class="btn-small kisa-btn-muokkaa btn-lahto-nyt"
                                data-lahto-id="<?= $lid ?>"
                                data-lahto-nimi="<?= htmlspecialchars((string) ($lahto['nimi'] ?? 'Lähtö'), ENT_QUOTES, 'UTF-8') ?>"
                            >Lähtö nyt</button>
                            <a class="btn-small btn-secondary kisa-btn-muokkaa" href="lahdot.php?muokkaa=<?= $lid ?>">Muokkaa</a>
                            <form method="post" class="inline-form" onsubmit="return confirm('Poistetaanko lähtö?');">
                                <?= csrfKentta() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $lid ?>">
                                <button type="submit" class="btn-danger btn-small kisa-btn-poista">Poista</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!$pirila && $lahdot !== []): ?>
<script>
(function () {
    var kilpailuId = <?= (int) $kilpailu_id ?>;
    document.querySelectorAll('.btn-lahto-nyt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var lahtoId = parseInt(btn.getAttribute('data-lahto-id') || '0', 10);
            var nimi = btn.getAttribute('data-lahto-nimi') || ('#' + lahtoId);
            if (!lahtoId) return;
            if (!window.confirm('Asetetaanko lähtöaika NYT?\n\n' + nimi + '\n\nNettoajat lasketaan uudelleen.')) {
                return;
            }
            btn.disabled = true;
            fetch('../api/api_lahto_nyt.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    kilpailu_id: kilpailuId,
                    lahto_id: lahtoId
                })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.status === 'ok') {
                    window.location.reload();
                    return;
                }
                window.alert((d && d.message) ? d.message : 'Lähtö epäonnistui');
                btn.disabled = false;
            }).catch(function () {
                window.alert('Yhteysvirhe');
                btn.disabled = false;
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php pageEnd(); ?>
