<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/seura_kisavalikko.php';
require_once __DIR__ . '/../includes/etusivu.php';
if (is_readable(__DIR__ . '/../includes/seura_logo.php')) {
    require_once __DIR__ . '/../includes/seura_logo.php';
}
if (is_readable(__DIR__ . '/../includes/kisa_osoite.php')) {
    require_once __DIR__ . '/../includes/kisa_osoite.php';
}

kilpailuVarmistaTilaEnum($pdo);
kilpailuSynkkaaTilaIlmosta($pdo);
if (function_exists('kisaOsoiteVarmista')) {
    kisaOsoiteVarmista($pdo);
}
require_once __DIR__ . '/../includes/kisa_liput.php';
kisaLiputVarmista($pdo);

$kirjautunut = !empty($_SESSION['user']);
$rooliTyokalu = $kirjautunut ? seuraRooliTyokalu() : null;

try {
    $kilpailut = $pdo->query("
        SELECT k.*, s.nimi AS seura_nimi, s.logo AS seura_logo
        FROM kilpailut k
        LEFT JOIN seurat s ON s.id = k.seura_id
        WHERE COALESCE(k.julkinen, 1) = 1
          AND COALESCE(k.testikisa, 0) = 0
          AND k.tila IN ('kaynnissa', 'tulossa', 'ilmoittautuminen', 'valmis')
        ORDER BY k.paiva DESC, k.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $kilpailut = $pdo->query("
        SELECT * FROM kilpailut
        WHERE tila IN ('kaynnissa', 'tulossa', 'ilmoittautuminen', 'valmis')
        ORDER BY paiva DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$kilpailut = etusivuValitseLista($kilpailut, 20);

$sarjaLkm = [];
if ($kilpailut !== [] && function_exists('kilpailujenSarjaLkm')) {
    $sarjaLkm = kilpailujenSarjaLkm($pdo, array_column($kilpailut, 'id'));
}
foreach ($kilpailut as &$kisaRivi) {
    $kisaRivi['_sarjoja'] = (int) ($sarjaLkm[(int) ($kisaRivi['id'] ?? 0)] ?? 0);
}
unset($kisaRivi);

function etusivuOnIltarasti(array $k): bool
{
    return function_exists('kisaOnIltarasti') && kisaOnIltarasti($k);
}

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
    $live = etusivuKisaLive($k);
    $ilmo = !$live && etusivuKisaIlmoAuki($k);
    $linkit = julkinenLinkit($k['id'], true);
    $href = etusivuKisaHref($k, $linkit);
    $laji = function_exists('etusivuKisaTyyppiLabel')
        ? etusivuKisaTyyppiLabel($k)
        : (etusivuOnIltarasti($k)
            ? 'Suunnistus'
            : (function_exists('kisaLuonneLabel') ? kisaLuonneLabel($k['luonne'] ?? null) : ''));
    $haku = mb_strtolower(trim(implode(' ', array_filter([
        (string) ($k['nimi'] ?? ''),
        (string) ($k['seura_nimi'] ?? ''),
        (string) ($k['paikka'] ?? ''),
        (string) ($k['osoite'] ?? ''),
        (string) ($k['paiva'] ?? ''),
        $laji,
    ]))), 'UTF-8');
    $seura = [
        'id' => (int) ($k['seura_id'] ?? 0),
        'nimi' => (string) ($k['seura_nimi'] ?? ''),
        'logo' => (string) ($k['seura_logo'] ?? ''),
    ];
    $naytaLogo = $seura['nimi'] !== '' || $seura['id'] > 0 || $seura['logo'] !== '';
    ?>
    <li data-home-kisa-item data-kisa-id="<?= (int) ($k['id'] ?? 0) ?>" data-haku="<?= htmlspecialchars($haku, ENT_QUOTES, 'UTF-8') ?>">
        <a href="<?= htmlspecialchars($href) ?>" class="home-kisa<?= $live ? ' is-live' : '' ?><?= $naytaLogo ? '' : ' ei-logoa' ?>">
            <?php if ($naytaLogo && function_exists('seuraLogoHtml')): ?>
                <?php seuraLogoHtml($seura, 'home-kisa-logo seura-logo--kisa'); ?>
            <?php endif; ?>
            <div class="home-kisa-body">
                <strong class="home-kisa-nimi"><?= htmlspecialchars($k['nimi']) ?></strong>
                <?php if (!empty($k['testikisa'])): ?><span class="badge badge-demo">DEMO - Ei julkinen</span><?php endif; ?>
                <span class="home-kisa-meta muted">
                    <?php if ($laji !== ''): ?>
                        <?= htmlspecialchars($laji) ?>
                    <?php endif; ?>
                    <?php if ($laji !== '' && (!empty($k['seura_nimi']) || $k['paiva'] || $k['paikka'])): ?> · <?php endif; ?>
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
            <span class="home-kisa-tila">
                <?php if ($live): ?>
                    <span class="live-badge">LIVE</span>
                <?php elseif ($ilmo): ?>
                    <span class="status-badge status-ilmoittautuminen">Ilmoittautuminen</span>
                <?php else: ?>
                    <span class="status-badge status-<?= htmlspecialchars((string) $k['tila']) ?>">
                        <?= htmlspecialchars(kilpailuTilaLabel($k['tila'])) ?>
                    </span>
                <?php endif; ?>
            </span>
        </a>
    </li>
    <?php
}

/**
 * @param list<array> $kisat
 * @return list<array{id:int,nimi:string,href:string,lat:float,lng:float,pvm:string,tila:string,live:bool,haku:string}>
 */
function etusivuKarttaMerkit(array $kisat): array
{
    $out = [];
    foreach ($kisat as $k) {
        if (function_exists('kisaOsoiteOnPiste') ? !kisaOsoiteOnPiste($k) : (empty($k['lat']) || empty($k['lng']))) {
            continue;
        }
        $linkit = function_exists('julkinenLinkit') ? julkinenLinkit($k['id'], true) : [];
        $laji = function_exists('etusivuKisaTyyppiLabel') ? etusivuKisaTyyppiLabel($k) : '';
        $out[] = [
            'id' => (int) ($k['id'] ?? 0),
            'nimi' => (string) ($k['nimi'] ?? ''),
            'href' => etusivuKisaHref($k, $linkit),
            'lat' => (float) $k['lat'],
            'lng' => (float) $k['lng'],
            'pvm' => !empty($k['paiva']) && function_exists('formatPaiva') ? formatPaiva($k['paiva']) : '',
            'tila' => function_exists('kilpailuTilaLabel') ? kilpailuTilaLabel($k['tila'] ?? '') : '',
            'live' => etusivuKisaLive($k),
            'haku' => mb_strtolower(trim(implode(' ', array_filter([
                (string) ($k['nimi'] ?? ''),
                (string) ($k['seura_nimi'] ?? ''),
                (string) ($k['paikka'] ?? ''),
                (string) ($k['osoite'] ?? ''),
                $laji,
            ]))), 'UTF-8'),
        ];
    }

    return $out;
}

$karttaMerkit = etusivuKarttaMerkit($kilpailut);

pageStart('Etusivu', [
    'base' => '../',
    'class' => 'home-page',
    'palvelu' => 'julkinen',
    'extraHead' => (function_exists('seuraLogoTyylit') ? seuraLogoTyylit() : '') . '<style>
.asenna-sovellus{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.7rem;margin:0 0 1rem;padding:.8rem .95rem;border-radius:12px;background:color-mix(in srgb,var(--primary) 14%,var(--bg));border:1px solid color-mix(in srgb,var(--primary) 45%,transparent);color:var(--text)}
.asenna-sovellus strong{display:block;font-size:1.08rem}
.asenna-sovellus p{margin:.25rem 0 0}
.asenna-sovellus-napit{display:flex;gap:.4rem}
.asenna-sovellus button{font:inherit;font-weight:700;border-radius:8px;padding:.55rem .85rem;cursor:pointer}
#asenna-nappi{background:var(--primary);color:var(--on-primary,#fff);border:0}
#asenna-sulje{background:transparent;color:var(--text);border:1px solid color-mix(in srgb,var(--text) 30%,transparent)}
@media (min-width:901px){.asenna-sovellus{display:none}.asenna-sovellus.is-auki{display:flex}}
.home-kisa-ryhma.is-yksi .home-kisa-ryhma-sarakkeet{grid-template-columns:minmax(260px,48rem)}
.home-page .home-kisa{display:grid;grid-template-columns:2.75rem minmax(0,1fr) auto;align-items:start;column-gap:12px;flex-wrap:nowrap}
.home-page .home-kisa.ei-logoa{grid-template-columns:minmax(0,1fr) auto}
.home-page .home-kisa-logo{align-self:center}
.home-page .home-kisa-tila{justify-self:end;align-self:start;margin:0;flex-shrink:0}
.home-page .home-kisa-tila .live-badge,.home-page .home-kisa-tila .status-badge{display:inline-block;white-space:nowrap}
.seura-logo--kisa{width:2.75rem;height:2.75rem;border-radius:8px}
.seura-logo--kisa .seura-logo-kirjaimet{font-size:.92rem}
@media (max-width:400px){.home-page .home-kisa{flex-wrap:nowrap}}
.home-nakyma-valinta{display:flex;gap:.4rem;margin:0 0 .75rem}
.home-nakyma-valinta button{font:inherit;font-weight:700;border-radius:8px;padding:.45rem .85rem;cursor:pointer;border:1px solid color-mix(in srgb,var(--text) 22%,transparent);background:var(--card,#fff);color:var(--text)}
.home-nakyma-valinta button.is-valittu{background:var(--primary);color:var(--on-primary,#fff);border-color:transparent}
.home-kartta-wrap{position:relative;min-width:0}
.home-kartta{width:100%;height:70vh;min-height:16rem;border-radius:12px;border:1px solid var(--border);overflow:hidden;background:var(--card)}
.home-kartta-tyhja{position:absolute;left:.75rem;right:.75rem;bottom:.75rem;margin:0;padding:.55rem .7rem;border-radius:8px;background:color-mix(in srgb,var(--card) 88%,transparent)}
.home-kartta-popup{font:inherit}
.home-kartta-merkki{background:none;border:none}
.leaflet-container .home-kartta-merkki svg{display:block;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45))}
@media (max-width:900px){
.home-kisa-nakyma.is-lista .home-kartta-wrap{display:none}
.home-kisa-nakyma.is-kartta .home-kisa-board{display:none}
}
@media (min-width:901px){
.home-nakyma-valinta{display:none}
.home-kisa-nakyma{display:grid;grid-template-columns:minmax(18rem,32rem) minmax(16rem,1fr);gap:1rem;align-items:start}
.home-kisa-ryhma.is-yksi .home-kisa-ryhma-sarakkeet{grid-template-columns:1fr}
.home-kartta{height:min(70vh,38rem)}
}
</style>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
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
            <a class="home-seura-action" href="/public/omat_tapahtumat.php">Omat tapahtumat</a>
            <a class="home-seura-action" href="/admin/users.php">Käyttäjät</a>
            <a class="home-seura-action" href="/admin/oma_seura.php">Oma seura</a>
            <?php if (function_exists('onAdmin') && onAdmin()): ?>
                <a class="home-seura-action" href="/admin/seurat.php">Seurojen hallinta</a>
            <?php endif; ?>
        </div>
    <?php elseif (function_exists('voiKisahallintaan') && voiKisahallintaan()): ?>
        <div class="home-seura-actions" style="margin-top:0.75rem">
            <a class="home-seura-action is-primary" href="/admin/index.php">Hallinta</a>
            <a class="home-seura-action" href="/public/omat_tapahtumat.php">Omat tapahtumat</a>
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
    <div class="home-kisa-haku-wrap">
        <label class="home-kisa-haku-label" for="home-kisa-haku">Hae tapahtumaa</label>
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
    <div class="home-nakyma-valinta" role="tablist" aria-label="Tapahtumanäkymä">
        <button type="button" role="tab" data-nakyma="lista" class="is-valittu" aria-selected="true">Lista</button>
        <button type="button" role="tab" data-nakyma="kartta" aria-selected="false">Kartta</button>
    </div>
    <div class="home-kisa-nakyma is-lista" data-home-nakyma>
    <div class="home-kisa-board" data-home-kisa-board>
        <section class="home-kisa-ryhma is-yksi" data-home-kisa-ryhma data-ryhma="tapahtumat">
            <h2 class="home-kisa-ryhma-otsikko">
                Tapahtumat
                <span class="home-kisa-col-count" data-home-col-count><?= count($kilpailut) ?></span>
            </h2>
            <div class="home-kisa-ryhma-sarakkeet">
                <section class="home-kisa-col is-tapahtumat" data-home-kisa-col data-col-key="tapahtumat">
                    <div class="home-kisa-col-body">
                        <ul class="home-kisa-list">
                            <?php foreach ($kilpailut as $k): ?>
                                <?php etusivuKisaRivi($k); ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            </div>
        </section>
    </div>
    <div class="home-kartta-wrap">
        <div id="home-kartta" class="home-kartta" role="region" aria-label="Tapahtumat kartalla"></div>
        <p class="home-kartta-tyhja muted" data-home-kartta-tyhja hidden>Ei tapahtumia kartalla. Lisää osoite tapahtuman asetuksiin.</p>
    </div>
    </div>
    <script type="application/json" id="home-kartta-data"><?= json_encode($karttaMerkit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
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
            if (typeof window.jahKarttaSuodata === 'function') window.jahKarttaSuodata(q);
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/assets/js/etusivu_kartta.js?v=20261117kartta2"></script>
<?php endif; ?>

<?php pageEnd(); ?>
