<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/seura_kisavalikko.php';

kilpailuVarmistaTilaEnum($pdo);
kilpailuSynkkaaTilaIlmosta($pdo);
require_once __DIR__ . '/../includes/kisa_liput.php';
kisaLiputVarmista($pdo);

$kirjautunut = !empty($_SESSION['user']);
$rooliTyokalu = $kirjautunut ? seuraRooliTyokalu() : null;

/**
 * Julkinen etusivu: ei luonnoksia, demo/testikisoja eikä nimeltään testejä.
 */
function etusivuKisaNaytaJulkisesti(array $k): bool
{
    $tila = (string) ($k['tila'] ?? '');
    if (!in_array($tila, ['kaynnissa', 'tulossa', 'ilmoittautuminen', 'valmis'], true)) {
        return false;
    }
    if (!empty($k['testikisa'])) {
        return false;
    }
    if (array_key_exists('julkinen', $k) && (int) $k['julkinen'] !== 1) {
        return false;
    }
    $nimi = mb_strtolower(trim((string) ($k['nimi'] ?? '')), 'UTF-8');
    if ($nimi === '') {
        return false;
    }
    if (preg_match('/jono-testi|testikisa|testijuoksu|^(jono-)?testi(\b|$)/u', $nimi)) {
        return false;
    }
    // Ilmoittautuminen-sarakkeeseen vain kun täppä on päällä
    if ($tila === 'ilmoittautuminen' && empty($k['ilmoittautuminen_auki'])) {
        return false;
    }

    return true;
}

try {
    $kilpailut = $pdo->query("
        SELECT k.*, s.nimi AS seura_nimi
        FROM kilpailut k
        LEFT JOIN seurat s ON s.id = k.seura_id
        WHERE COALESCE(k.julkinen, 1) = 1
          AND COALESCE(k.testikisa, 0) = 0
          AND k.tila IN ('kaynnissa', 'tulossa', 'ilmoittautuminen', 'valmis')
        ORDER BY FIELD(k.tila, 'kaynnissa', 'tulossa', 'ilmoittautuminen', 'valmis'), k.paiva DESC, k.nimi ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $kilpailut = $pdo->query("
        SELECT * FROM kilpailut
        WHERE tila IN ('kaynnissa', 'valmis')
        ORDER BY FIELD(tila, 'kaynnissa', 'valmis'), paiva DESC, nimi ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$kilpailut = array_values(array_filter($kilpailut, 'etusivuKisaNaytaJulkisesti'));

$sarjaLkm = [];
if ($kilpailut !== [] && function_exists('kilpailujenSarjaLkm')) {
    $sarjaLkm = kilpailujenSarjaLkm($pdo, array_column($kilpailut, 'id'));
}
foreach ($kilpailut as &$kisaRivi) {
    $kisaRivi['_sarjoja'] = (int) ($sarjaLkm[(int) ($kisaRivi['id'] ?? 0)] ?? 0);
}
unset($kisaRivi);

$sarakkeet = [
    'kilpailut' => [
        'otsikko' => 'Käynnissä',
        'luokka' => 'is-kilpailut',
        'avoimuus' => true,
        'tilat' => ['kaynnissa', 'tulossa'],
    ],
    'ilmoittautuminen' => [
        'otsikko' => 'Ilmoittautuminen',
        'luokka' => 'is-ilmo',
        'avoimuus' => true,
        'tilat' => ['ilmoittautuminen'],
    ],
    'valmis' => [
        'otsikko' => 'Valmiit',
        'luokka' => 'is-valmis',
        'avoimuus' => true,
        'tilat' => ['valmis'],
    ],
];

function etusivuOnIltarasti(array $k): bool
{
    return function_exists('kisaOnIltarasti') && kisaOnIltarasti($k);
}

/**
 * @param list<array> $kisat
 * @param array<string,array> $sarakkeet
 * @return array<string,list<array>>
 */
function etusivuJaaSarakkeisiin(array $kisat, array $sarakkeet): array
{
    $ulos = [];
    foreach ($sarakkeet as $avain => $_) {
        $ulos[$avain] = [];
    }
    foreach ($kisat as $k) {
        $tila = (string) ($k['tila'] ?? '');
        if ($tila === 'ilmoittautuminen' && (empty($k['_sarjoja']) || empty($k['ilmoittautuminen_auki']))) {
            $tila = 'tulossa';
        }
        foreach ($sarakkeet as $avain => $sarake) {
            if (in_array($tila, $sarake['tilat'], true)) {
                $ulos[$avain][] = $k;
                break;
            }
        }
    }

    return $ulos;
}

$kisatKilpailut = [];
$kisatIltarastit = [];
foreach ($kilpailut as $k) {
    if (etusivuOnIltarasti($k)) {
        $kisatIltarastit[] = $k;
    } else {
        $kisatKilpailut[] = $k;
    }
}
usort($kisatIltarastit, static function (array $a, array $b): int {
    $da = (string) ($a['paiva'] ?? '');
    $db = (string) ($b['paiva'] ?? '');
    if ($da !== $db) {
        return $db <=> $da;
    }

    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

$ryhmat = [
    [
        'avain' => 'kilpailut',
        'otsikko' => 'Kilpailut',
        'kisat' => $kisatKilpailut,
        'yksi_sarake' => false,
        'tyhjat' => [
            'kilpailut' => 'Ei käynnissä olevia kilpailuja.',
            'ilmoittautuminen' => 'Ei avoimia ilmoittautumisia.',
            'valmis' => 'Ei valmiita kilpailuja.',
        ],
    ],
    [
        'avain' => 'iltarastit',
        'otsikko' => 'Iltarastit',
        'kisat' => $kisatIltarastit,
        'yksi_sarake' => true,
        'tyhja' => 'Ei julkaistuja iltarasteja.',
    ],
];

/**
 * Etusivun oletuslinkki tilan mukaan (vierailija pääsee heti oikeaan näkymään).
 *
 * @param array $k
 * @param array<string,string> $linkit
 */
function etusivuKisaHref(array $k, array $linkit): string
{
    if (etusivuOnIltarasti($k)) {
        $id = (int) ($k['id'] ?? 0);
        return '/public/iltarasti_tulokset.php?kilpailu_id=' . $id;
    }
    $tila = (string) ($k['tila'] ?? '');
    if ($tila === 'kaynnissa' || $tila === 'valmis') {
        return (string) ($linkit['seuranta'] ?? $linkit['kilpailu']);
    }
    if ($tila === 'ilmoittautuminen'
        && !empty($k['ilmoittautuminen_auki'])
        && !empty($k['_sarjoja'])
        && !empty($linkit['ilmoittautuminen'])
    ) {
        return (string) $linkit['ilmoittautuminen'];
    }

    return (string) $linkit['kilpailu'];
}

/**
 * @param array $k
 */
function etusivuKisaRivi(array $k): void
{
    $live = ($k['tila'] === 'kaynnissa');
    $tulossa = ($k['tila'] === 'tulossa');
    $linkit = julkinenLinkit($k['id'], true);
    $href = etusivuKisaHref($k, $linkit);
    $haku = mb_strtolower(trim(implode(' ', array_filter([
        (string) ($k['nimi'] ?? ''),
        (string) ($k['seura_nimi'] ?? ''),
        (string) ($k['paikka'] ?? ''),
        (string) ($k['paiva'] ?? ''),
    ]))), 'UTF-8');
    ?>
    <li data-home-kisa-item data-haku="<?= htmlspecialchars($haku, ENT_QUOTES, 'UTF-8') ?>">
        <a href="<?= htmlspecialchars($href) ?>" class="home-kisa<?= $live ? ' is-live' : '' ?>">
            <div class="home-kisa-body">
                <strong class="home-kisa-nimi"><?= htmlspecialchars($k['nimi']) ?></strong>
                <?php if (!empty($k['testikisa'])): ?><span class="badge badge-demo">DEMO - Ei julkinen</span><?php endif; ?>
                <span class="home-kisa-meta muted">
                    <?php if (!empty($k['seura_nimi'])): ?>
                        <?= htmlspecialchars((string) $k['seura_nimi']) ?>
                    <?php endif; ?>
                    <?php if (!empty($k['seura_nimi']) && ($k['paiva'] || $k['paikka'])): ?> · <?php endif; ?>
                    <?php if ($k['paiva']): ?>
                        <?= htmlspecialchars(formatPaiva($k['paiva'])) ?>
                    <?php endif; ?>
                    <?php if ($k['paiva'] && $k['paikka']): ?> · <?php endif; ?>
                    <?php if ($k['paikka']): ?>
                        <?= htmlspecialchars($k['paikka']) ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($live): ?>
                <span class="live-badge">LIVE</span>
            <?php elseif (!$tulossa): ?>
                <span class="status-badge status-<?= htmlspecialchars((string) $k['tila']) ?>">
                    <?= htmlspecialchars(kilpailuTilaLabel($k['tila'])) ?>
                </span>
            <?php endif; ?>
        </a>
    </li>
    <?php
}

pageStart('Etusivu', [
    'base' => '../',
    'class' => 'home-page',
    'palvelu' => 'julkinen',
    'extraHead' => '<style>
.asenna-sovellus{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.7rem;margin:0 0 1rem;padding:.8rem .95rem;border-radius:12px;background:color-mix(in srgb,var(--primary) 14%,var(--bg));border:1px solid color-mix(in srgb,var(--primary) 45%,transparent);color:var(--text)}
.asenna-sovellus strong{display:block;font-size:1.08rem}
.asenna-sovellus p{margin:.25rem 0 0}
.asenna-sovellus-napit{display:flex;gap:.4rem}
.asenna-sovellus button{font:inherit;font-weight:700;border-radius:8px;padding:.55rem .85rem;cursor:pointer}
#asenna-nappi{background:var(--primary);color:var(--on-primary,#fff);border:0}
#asenna-sulje{background:transparent;color:var(--text);border:1px solid color-mix(in srgb,var(--text) 30%,transparent)}
@media (min-width:901px){.asenna-sovellus{display:none}.asenna-sovellus.is-auki{display:flex}}
</style>
<script>
window.jahAsennus=null;
window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();window.jahAsennus=e;var b=document.getElementById("asenna-sovellus");if(b)b.classList.add("is-auki");});
</script>',
]);
?>

<p class="home-intro muted">Tulospalvelu juoksu- ja suunnistuskilpailuihin</p>

<aside id="asenna-sovellus" class="asenna-sovellus">
    <div>
        <strong>Asenna sivusto puhelimeen</strong>
        <p>Kotinäytön kuvake avaa JAHOnlinen ilman osoiteriviä. iPhone: Jaa → Lisää kotinäyttöön.</p>
    </div>
    <div class="asenna-sovellus-napit">
        <button type="button" id="asenna-nappi">Asenna</button>
        <button type="button" id="asenna-sulje">Ei nyt</button>
    </div>
</aside>
<script>
(function () {
    var laatikko = document.getElementById('asenna-sovellus');
    if (!laatikko) return;
    var sovellus = window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: fullscreen)').matches
        || window.navigator.standalone === true;
    var piilossa = false;
    try { piilossa = sessionStorage.getItem('jah-asenna-piilossa') === '1'; } catch (e) {}
    if (sovellus || piilossa) {
        laatikko.remove();
        return;
    }
    var puhelin = window.matchMedia('(max-width: 900px)').matches
        || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (puhelin || window.jahAsennus) laatikko.classList.add('is-auki');
    var nappi = document.getElementById('asenna-nappi');
    var sulje = document.getElementById('asenna-sulje');
    var ios = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (ios && nappi) nappi.hidden = true;
    if (nappi && !ios) {
        nappi.addEventListener('click', function () {
            if (!window.jahAsennus) {
                var ohje = laatikko.querySelector('p');
                if (ohje) ohje.textContent = 'Avaa selaimen valikko ja valitse Asenna sovellus tai Lisää aloitusnäytölle.';
                return;
            }
            window.jahAsennus.prompt();
            window.jahAsennus.userChoice.then(function () {
                window.jahAsennus = null;
                laatikko.remove();
            });
        });
    }
    if (sulje) {
        sulje.addEventListener('click', function () {
            try { sessionStorage.setItem('jah-asenna-piilossa', '1'); } catch (e) {}
            laatikko.remove();
        });
    }
})();
</script>

<?php
$naytaEtusivuTyokalu = $rooliTyokalu
    && !(
        $kirjautunut
        && function_exists('jahNaytaOmatTapahtumat')
        && jahNaytaOmatTapahtumat($pdo instanceof PDO ? $pdo : null)
    );
?>

<?php if ($naytaEtusivuTyokalu): ?>
<section class="home-rooli-tyokalu">
    <h2><?= htmlspecialchars($rooliTyokalu['label']) ?></h2>
    <p class="muted"><?= htmlspecialchars($rooliTyokalu['desc']) ?></p>
    <p>
        <a class="btn home-rooli-btn" href="<?= htmlspecialchars($rooliTyokalu['href']) ?>">
            Avaa <?= htmlspecialchars($rooliTyokalu['label']) ?>
        </a>
    </p>
    <?php if (function_exists('onPaakayttaja') && onPaakayttaja()): ?>
        <div class="home-seura-actions" style="margin-top:0.75rem">
            <a class="home-seura-action is-primary" href="/admin/index.php">Hallinta</a>
            <a class="home-seura-action" href="/admin/kilpailut.php">Kilpailut</a>
            <a class="home-seura-action" href="/admin/users.php">Käyttäjät</a>
            <a class="home-seura-action" href="/admin/oma_seura.php">Oma seura</a>
            <?php if (function_exists('onAdmin') && onAdmin()): ?>
                <a class="home-seura-action" href="/admin/seurat.php">Seurojen hallinta</a>
            <?php endif; ?>
        </div>
    <?php elseif (function_exists('voiKisahallintaan') && voiKisahallintaan()): ?>
        <div class="home-seura-actions" style="margin-top:0.75rem">
            <a class="home-seura-action is-primary" href="/admin/index.php">Hallinta</a>
            <a class="home-seura-action" href="/admin/kilpailut.php">Kilpailut</a>
            <a class="home-seura-action" href="/admin/oma_seura.php">Oma seura</a>
        </div>
    <?php endif; ?>
</section>
<?php elseif ($kirjautunut && function_exists('voiKisahallintaan') && voiKisahallintaan() && !(function_exists('jahNaytaOmatTapahtumat') && jahNaytaOmatTapahtumat($pdo instanceof PDO ? $pdo : null))): ?>
<section class="home-rooli-tyokalu">
    <h2>Hallinta</h2>
    <p class="muted">Oman seuran kilpailujen hallinta.</p>
    <p>
        <a class="btn home-rooli-btn" href="/admin/index.php">Avaa Hallinta</a>
    </p>
</section>
<?php endif; ?>

<?php if (empty($kilpailut)): ?>
    <p class="home-empty muted">Ei kilpailuja vielä julkaistu.</p>
<?php else: ?>
    <?php
    $valmiitVuosiRaja = 6;
    ?>
    <div class="home-kisa-haku-wrap">
        <label class="home-kisa-haku-label" for="home-kisa-haku">Hae kilpailua tai iltarastia</label>
        <div class="home-kisa-haku-row">
            <input
                type="search"
                id="home-kisa-haku"
                class="home-kisa-haku"
                placeholder="Nimi, seura, paikka…"
                autocomplete="off"
                enterkeyhint="search"
            >
            <button type="button" class="home-kisa-haku-tyhjennys" data-home-haku-tyhjennys hidden aria-label="Tyhjennä haku">×</button>
        </div>
        <p class="home-kisa-haku-tyhja muted" data-home-haku-tyhja hidden>Ei osumia.</p>
    </div>
    <div class="home-kisa-board" data-home-kisa-board>
        <?php foreach ($ryhmat as $ryhma): ?>
            <?php
            $yksiSarake = !empty($ryhma['yksi_sarake']);
            $kisatSarakkeessa = $yksiSarake
                ? ['kaikki' => $ryhma['kisat']]
                : etusivuJaaSarakkeisiin($ryhma['kisat'], $sarakkeet);
            $tyhjatTekstit = $ryhma['tyhjat'] ?? [];
            $sarakkeetNaytto = $yksiSarake
                ? ['kaikki' => ['otsikko' => $ryhma['otsikko'], 'luokka' => 'is-iltarastit']]
                : $sarakkeet;
            ?>
            <section class="home-kisa-ryhma<?= $yksiSarake ? ' is-yksi' : '' ?>" data-home-kisa-ryhma data-ryhma="<?= htmlspecialchars($ryhma['avain'], ENT_QUOTES, 'UTF-8') ?>">
                <h2 class="home-kisa-ryhma-otsikko">
                    <?= htmlspecialchars($ryhma['otsikko']) ?>
                    <?php if ($yksiSarake): ?>
                        <span class="home-kisa-col-count" data-home-col-count><?= count($ryhma['kisat']) ?></span>
                    <?php endif; ?>
                </h2>
                <div class="home-kisa-ryhma-sarakkeet">
        <?php foreach ($sarakkeetNaytto as $avain => $sarake): ?>
            <?php
            $lista = $kisatSarakkeessa[$avain] ?? [];
            $lkm = count($lista);
            $vuosiRyhmat = null;
            if (!$yksiSarake && $avain === 'valmis' && $lkm >= $valmiitVuosiRaja) {
                $vuosiRyhmat = [];
                foreach ($lista as $k) {
                    $v = '';
                    if (!empty($k['paiva']) && preg_match('/^(\d{4})/', (string) $k['paiva'], $m)) {
                        $v = $m[1];
                    }
                    if ($v === '') {
                        $v = 'Muut';
                    }
                    $vuosiRyhmat[$v][] = $k;
                }
                krsort($vuosiRyhmat, SORT_NATURAL);
            }
            ?>
            <section class="home-kisa-col <?= htmlspecialchars($sarake['luokka']) ?>" data-home-kisa-col data-col-key="<?= htmlspecialchars($ryhma['avain'] . '-' . $avain, ENT_QUOTES, 'UTF-8') ?>">
                <?php if (!$yksiSarake): ?>
                <h3 class="home-kisa-col-heading">
                    <span class="home-kisa-col-title"><?= htmlspecialchars($sarake['otsikko']) ?></span>
                    <span class="home-kisa-col-count" data-home-col-count><?= $lkm ?></span>
                </h3>
                <?php endif; ?>
                <div class="home-kisa-col-body">
                    <?php if ($lista === []): ?>
                        <p class="home-kisa-col-tyhja muted"><?= htmlspecialchars($tyhjatTekstit[$avain] ?? ($ryhma['tyhja'] ?? 'Ei kilpailuja.')) ?></p>
                    <?php elseif ($vuosiRyhmat !== null): ?>
                        <?php foreach ($vuosiRyhmat as $vuosi => $vuosiLista): ?>
                            <div class="home-kisa-vuosi" data-home-vuosi>
                                <h4 class="home-kisa-vuosi-otsikko"><?= htmlspecialchars((string) $vuosi) ?></h4>
                                <ul class="home-kisa-list">
                                    <?php foreach ($vuosiLista as $k): ?>
                                        <?php etusivuKisaRivi($k); ?>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <ul class="home-kisa-list">
                            <?php foreach ($lista as $k): ?>
                                <?php etusivuKisaRivi($k); ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <script>
    (function () {
        var input = document.getElementById('home-kisa-haku');
        var board = document.querySelector('[data-home-kisa-board]');
        var tyhja = document.querySelector('[data-home-haku-tyhja]');
        var tyhjennys = document.querySelector('[data-home-haku-tyhjennys]');
        if (!input || !board) return;

        function normalisoi(s) {
            return String(s || '').toLocaleLowerCase('fi-FI').trim();
        }

        function suodata() {
            var q = normalisoi(input.value);
            if (tyhjennys) tyhjennys.hidden = !q;
            var naytetyt = 0;
            board.querySelectorAll('[data-home-kisa-col]').forEach(function (col) {
                var n = 0;
                col.querySelectorAll('[data-home-kisa-item]').forEach(function (li) {
                    var hay = li.getAttribute('data-haku') || '';
                    var ok = !q || hay.indexOf(q) !== -1;
                    li.hidden = !ok;
                    if (ok) n++;
                });
                col.querySelectorAll('[data-home-vuosi]').forEach(function (yr) {
                    var yn = 0;
                    yr.querySelectorAll('[data-home-kisa-item]').forEach(function (li) {
                        if (!li.hidden) yn++;
                    });
                    yr.hidden = yn === 0;
                });
                var tyhjaCol = col.querySelector('.home-kisa-col-tyhja');
                col.hidden = false;
                if (tyhjaCol) {
                    // tyhjä sarake näkyy aina ilman hakua; haulla piiloon jos ei osumia
                    col.hidden = !!q;
                } else {
                    col.hidden = n === 0;
                }
                var countEl = col.querySelector('[data-home-col-count]');
                if (!countEl) {
                    var ryhmaEl = col.closest('[data-home-kisa-ryhma]');
                    if (ryhmaEl) {
                        countEl = ryhmaEl.querySelector('.home-kisa-ryhma-otsikko [data-home-col-count]');
                    }
                }
                if (countEl) countEl.textContent = String(n);
                naytetyt += n;
            });
            board.querySelectorAll('[data-home-kisa-ryhma]').forEach(function (ryhma) {
                var nakyvissa = false;
                ryhma.querySelectorAll('[data-home-kisa-col]').forEach(function (col) {
                    if (!col.hidden) nakyvissa = true;
                });
                ryhma.hidden = !!q && !nakyvissa;
            });
            if (tyhja) tyhja.hidden = naytetyt > 0 || !q;
        }

        input.addEventListener('input', suodata);
        if (tyhjennys) {
            tyhjennys.addEventListener('click', function () {
                input.value = '';
                input.focus();
                suodata();
            });
        }
    })();
    </script>
<?php endif; ?>

<?php pageEnd(); ?>
