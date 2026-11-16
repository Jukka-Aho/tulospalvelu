<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/tulospalvelu.php';

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

require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/seura_kisavalikko.php';

// Luonnos / ei-julkinen: vain kirjautuneet hallitsijat
vaadiJulkinenNakyvyys($kilpailu);

if (is_readable(__DIR__ . '/../includes/iltarasti.php')) {
    require_once __DIR__ . '/../includes/iltarasti.php';
}
if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)) {
    $q = ['kilpailu_id' => $kilpailu_id];
    $nakyma = trim((string) ($_GET['nakyma'] ?? ''));
    if ($nakyma === 'valiajat') {
        $q['nakyma'] = 'valiajat';
    }
    header('Location: /public/iltarasti_tulokset.php?' . http_build_query($q), true, 302);
    exit;
}

$linkit = julkinenLinkit($kilpailu_id, true);
$live = ($kilpailu['tila'] === 'kaynnissa');
$pirila = pirilaSynkkaPaalla($kilpailu);
$seuraLinkit = seuraKisavalikkoLinkit($kilpailu, true);

$osio = trim((string) ($_GET['osio'] ?? ''));
if ($osio !== 'online') {
    $osio = 'hub';
}

if (onkoViestiKilpailu($kilpailu)) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM joukkueet WHERE kilpailu_id = ?');
    $stmt->execute([$kilpailu_id]);
    $kilpailija_lkm = (int) $stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM kilpailijat WHERE kilpailu_id = ? AND COALESCE(osuus, 0) = 0');
    $stmt->execute([$kilpailu_id]);
    $kilpailija_lkm = (int) $stmt->fetchColumn();
}

if (onkoViestiKilpailu($kilpailu)) {
    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT k.joukkue_id)
        FROM ajat a
        JOIN kilpailijat k ON k.id = a.kilpailija_id
        WHERE k.kilpailu_id = ?
          AND k.joukkue_id IS NOT NULL
    ');
} else {
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM ajat a
        JOIN kilpailijat k ON k.id = a.kilpailija_id
        WHERE k.kilpailu_id = ?
          AND COALESCE(k.osuus, 0) = 0
    ');
}
$stmt->execute([$kilpailu_id]);
$maali_lkm = (int) $stmt->fetchColumn();

$seuraNimi = null;
$seuraIdMeta = (int) ($kilpailu['seura_id'] ?? 0);
if ($seuraIdMeta > 0) {
    try {
        $stSeura = $pdo->prepare('SELECT nimi FROM seurat WHERE id = ? LIMIT 1');
        $stSeura->execute([$seuraIdMeta]);
        $seuraNimi = $stSeura->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $seuraNimi = null;
    }
}

$metaPalat = [];
if ($seuraNimi) {
    $metaPalat[] = htmlspecialchars((string) $seuraNimi, ENT_QUOTES, 'UTF-8');
}
if (!empty($kilpailu['paiva'])) {
    $metaPalat[] = htmlspecialchars(formatPaiva($kilpailu['paiva']), ENT_QUOTES, 'UTF-8');
}
if (!empty($kilpailu['paikka'])) {
    $metaPalat[] = htmlspecialchars((string) $kilpailu['paikka'], ENT_QUOTES, 'UTF-8');
}
if ($kilpailija_lkm > 0) {
    if (onkoViestiKilpailu($kilpailu)) {
        $metaPalat[] = $kilpailija_lkm . ' joukkuetta · ' . $maali_lkm . ' maalissa';
    } else {
        $metaPalat[] = $kilpailija_lkm . ' kilpailijaa · ' . $maali_lkm . ' maalissa';
    }
}

$onOnline = ($osio === 'online');

if ($onOnline) {
    // Säilytä seurannan suodattimet iframe-URLissa
    $embedParams = ['kilpailu_id' => $kilpailu_id, 'embed' => 1];
    foreach (['nakyma', 'sarja', 'matka', 'ryhmittele', 'seura', 'osuus'] as $avain) {
        if (!isset($_GET[$avain])) {
            continue;
        }
        $arvo = trim((string) $_GET[$avain]);
        if ($arvo !== '') {
            $embedParams[$avain] = $arvo;
        }
    }
    $embedSrc = 'seuranta.php?' . http_build_query($embedParams);

    $seurantaOtsikko = seurantaOtsikko($kilpailu);
    $nakymaYla = trim((string) ($_GET['nakyma'] ?? 'tulokset'));
    $ylaAktiivinen = 'seuranta';
    if ($nakymaYla === 'valiajat') {
        $ylaAktiivinen = 'valiajat';
    } elseif ($nakymaYla === 'online') {
        $ylaAktiivinen = 'online';
    }
    pageStart($seurantaOtsikko, tulospalveluPageOpts('kilpailu-page kilpailu-kuori-online'));
    tulospalveluYla($kilpailu, $linkit, $seurantaOtsikko, [
        'aktiivinen' => $ylaAktiivinen,
        'meta_html' => $metaPalat !== [] ? implode(' · ', $metaPalat) : null,
    ]);
    ?>

<div class="lb-main lb-main--kuori">
  <?php if ($seuraLinkit !== []): ?>
    <?php seuraKisavalikkoHtml($kilpailu, true, ['compact' => true]); ?>
  <?php endif; ?>
  <iframe
    class="lb-online-embed"
    id="lb-online-embed"
    title="<?= htmlspecialchars($seurantaOtsikko, ENT_QUOTES, 'UTF-8') ?>"
    loading="eager"
    scrolling="no"
    referrerpolicy="same-origin"
    data-src="<?= htmlspecialchars($embedSrc, ENT_QUOTES, 'UTF-8') ?>"
  ></iframe>
</div>
<script>
(function () {
  var frame = document.getElementById('lb-online-embed');
  if (!frame) return;

  // Aseta teema iframe-URL:iin ennen latausta (ei vaaleaa välähdystä)
  (function asetaTeemaEnnenLatausta() {
    var src = frame.getAttribute('data-src') || '';
    if (!src) return;
    var theme = 'light';
    try {
      theme = localStorage.getItem('jahonline-theme') || 'light';
    } catch (e) {}
    if (theme !== 'dark' && theme !== 'light') theme = 'light';
    frame.style.background = theme === 'dark' ? '#071018' : '#eef2f5';
    try {
      var u = new URL(src, window.location.href);
      u.searchParams.set('teema', theme);
      frame.src = u.pathname + u.search + u.hash;
    } catch (e2) {
      frame.src = src + (src.indexOf('?') >= 0 ? '&' : '?') + 'teema=' + encodeURIComponent(theme);
    }
  })();

  function fitHeight() {
    try {
      var doc = frame.contentDocument;
      if (!doc) return;
      var body = doc.body;
      var html = doc.documentElement;
      if (!body) return;
      // Estä embedin oma scrolli — parent on ainoa vierittäjä
      html.style.overflow = 'hidden';
      body.style.overflow = 'hidden';
      // Mittaa sisältö, ei iframe-ikkunan min-korkeutta
      var h = 0;
      var last = body.lastElementChild;
      if (last) {
        h = Math.ceil(last.getBoundingClientRect().bottom - body.getBoundingClientRect().top);
      }
      h = Math.max(
        h,
        body.scrollHeight || 0,
        body.offsetHeight || 0
      );
      // Älä käytä html.scrollHeightia ensisijaisesti — se voi sisältää
      // vanhan iframe-korkeuden ja paisuttaa kehystä.
      if (h < 1 && html) {
        h = html.scrollHeight || 0;
      }
      if (h > 0) {
        frame.style.height = h + 'px';
      }
    } catch (e) {}
  }

  function syncThemeToFrame() {
    try {
      var theme = localStorage.getItem('jahonline-theme') || 'light';
      if (frame.contentWindow) {
        frame.contentWindow.postMessage(
          { type: 'jah-theme', theme: theme },
          window.location.origin
        );
      }
    } catch (e) {}
  }

  function bindFitters() {
    fitHeight();
    syncThemeToFrame();
    try {
      var doc = frame.contentDocument;
      if (!doc || !doc.body) return;
      if (frame._jahRo) {
        frame._jahRo.disconnect();
      }
      if (typeof ResizeObserver !== 'undefined') {
        frame._jahRo = new ResizeObserver(function () {
          requestAnimationFrame(fitHeight);
        });
        frame._jahRo.observe(doc.body);
        if (doc.documentElement) {
          frame._jahRo.observe(doc.documentElement);
        }
      }
      if (frame._jahMo) {
        frame._jahMo.disconnect();
      }
      frame._jahMo = new MutationObserver(function () {
        requestAnimationFrame(fitHeight);
      });
      frame._jahMo.observe(doc.body, { childList: true, subtree: true, characterData: true });
    } catch (e) {}
  }

  frame.addEventListener('load', function () {
    bindFitters();
    try {
      var loc = frame.contentWindow && frame.contentWindow.location;
      if (!loc) return;
      // Jos iframeen ladattiin täysi kuori (kilpailu.php tms.), nosta parenttiin
      if (!/seuranta\.php$/i.test(loc.pathname)) {
        window.location.replace(loc.pathname + loc.search + loc.hash);
        return;
      }
      var params = new URLSearchParams(loc.search);
      params.delete('embed');
      params.delete('teema');
      params.set('osio', 'online');
      if (!params.get('kilpailu_id')) return;
      var next = 'kilpailu.php?' + params.toString();
      var nextSearch = '?' + params.toString();
      if (location.pathname.indexOf('kilpailu.php') !== -1 && location.search !== nextSearch) {
        history.replaceState(null, '', next);
      }
    } catch (e) {}
  });

  window.addEventListener('resize', function () {
    requestAnimationFrame(fitHeight);
  });
})();
</script>

<?php
    tulospalveluLoppu();
    pageEnd();
    exit;
}

pageStart($kilpailu['nimi'], tulospalveluPageOpts('kilpailu-page'));
tulospalveluYla($kilpailu, $linkit, 'Kilpailu', [
    'aktiivinen' => 'kilpailu',
    'meta_html' => $metaPalat !== [] ? implode(' · ', $metaPalat) : null,
]);
?>

<div class="lb-main">
  <div class="lb-paneeli lb-hub">
    <div class="lb-hub-otsikko">Julkiset näkymät</div>
    <div class="lb-hub-linkit">
      <?php
      $naytaIlmo = function_exists('naytaIlmoittautuminenValikossa')
          ? naytaIlmoittautuminenValikossa($kilpailu)
          : !empty($kilpailu['ilmoittautuminen_auki']);
      $naytaIlmoLomake = function_exists('naytaIlmoittautuminenLomakeValikossa')
          ? naytaIlmoittautuminenLomakeValikossa($pdo, $kilpailu)
          : $naytaIlmo;
      $ilmoAukiNyt = function_exists('ilmoittautuminenJulkinenAuki')
          ? ilmoittautuminenJulkinenAuki($pdo, $kilpailu)
          : ilmoittautuminenAuki($kilpailu);
      $hubLinkit = [];
      if ($naytaIlmoLomake && $ilmoAukiNyt) {
          $hubLinkit[] = ['href' => $linkit['ilmoittautuminen'], 'label' => 'Ilmoittautuminen', 'primary' => true];
          $hubLinkit[] = ['href' => $linkit['ilmoittautuneet'], 'label' => 'Ilmoittautuneet', 'primary' => false];
          if (function_exists('ilmoArvontaOnKierroksia') && ilmoArvontaOnKierroksia($pdo, (int) $kilpailu_id)) {
              $hubLinkit[] = ['href' => $linkit['arvonta'], 'label' => 'Arvonta', 'primary' => false];
          }
          if ($kilpailija_lkm > 0) {
              $hubLinkit[] = ['href' => $linkit['lahtolista'], 'label' => 'Lähtöajat', 'primary' => false];
          }
          $hubLinkit[] = [
              'href' => kilpailuKuoriUrl((int) $kilpailu_id, 'online', ['nakyma' => 'tulokset']),
              'label' => seurantaOtsikko($kilpailu),
              'primary' => false,
          ];
          if ($pirila) {
              $hubLinkit[] = [
                  'href' => kilpailuKuoriUrl((int) $kilpailu_id, 'online', ['nakyma' => 'online']),
                  'label' => 'Online-väliajat',
                  'primary' => false,
              ];
              $hubLinkit[] = [
                  'href' => kilpailuKuoriUrl((int) $kilpailu_id, 'online', ['nakyma' => 'valiajat']),
                  'label' => 'Rastiväliajat',
                  'primary' => false,
              ];
          }
      } else {
          $hubLinkit[] = [
              'href' => $pirila
                  ? kilpailuKuoriUrl((int) $kilpailu_id, 'online', ['nakyma' => 'tulokset'])
                  : $linkit['seuranta'],
              'label' => seurantaOtsikko($kilpailu),
              'primary' => true,
          ];
          if ($pirila) {
              $hubLinkit[] = [
                  'href' => kilpailuKuoriUrl((int) $kilpailu_id, 'online', ['nakyma' => 'online']),
                  'label' => 'Online-väliajat',
                  'primary' => false,
              ];
              $hubLinkit[] = [
                  'href' => kilpailuKuoriUrl((int) $kilpailu_id, 'online', ['nakyma' => 'valiajat']),
                  'label' => 'Rastiväliajat',
                  'primary' => false,
              ];
          }
          if ($kilpailija_lkm > 0) {
              $hubLinkit[] = ['href' => $linkit['lahtolista'], 'label' => 'Lähtöajat', 'primary' => false];
          }
          if ($naytaIlmoLomake) {
              $hubLinkit[] = ['href' => $linkit['ilmoittautuminen'], 'label' => 'Ilmoittautuminen', 'primary' => false];
          }
          if ($naytaIlmo) {
              $hubLinkit[] = ['href' => $linkit['ilmoittautuneet'], 'label' => 'Ilmoittautuneet', 'primary' => false];
          }
          if (function_exists('ilmoArvontaOnKierroksia') && ilmoArvontaOnKierroksia($pdo, (int) $kilpailu_id)) {
              $hubLinkit[] = ['href' => $linkit['arvonta'], 'label' => 'Arvonta', 'primary' => false];
          }
      }
      if (($kilpailu['tila'] ?? '') === 'valmis') {
          $hubLinkit[] = [
              'href' => 'tulokset_iof.php?kilpailu_id=' . (int) $kilpailu_id,
              'label' => 'Lataa IOF XML',
              'primary' => false,
              'download' => true,
              'title' => 'Lataa tulokset IOF XML 3.0 -muodossa',
          ];
      }
      if (is_readable(__DIR__ . '/../includes/ssl_tulokset.php')) {
          require_once __DIR__ . '/../includes/ssl_tulokset.php';
          if (function_exists('sslTuloksetSallittu') && sslTuloksetSallittu($kilpailu)) {
              $hubLinkit[] = [
                  'href' => 'tulokset_ssl.php?kilpailu_id=' . (int) $kilpailu_id,
                  'label' => 'Lataa SSL-tulokset',
                  'primary' => false,
                  'title' => 'Lataa tulokset SSL-muodossa liitolle (10 € / kisa)',
              ];
          }
      }
      $ohjePalat = [];
      foreach ($hubLinkit as $hl) {
          $ohjePalat[] = mb_strtolower((string) $hl['label'], 'UTF-8');
      }
      ?>
      <?php foreach ($hubLinkit as $hl): ?>
        <a class="lb-hub-link<?= !empty($hl['primary']) ? ' lb-hub-link--primary' : '' ?>"
           href="<?= htmlspecialchars((string) $hl['href']) ?>"<?php
             if (!empty($hl['download'])) {
                 echo ' download';
             }
             if (!empty($hl['title'])) {
                 echo ' title="' . htmlspecialchars((string) $hl['title'], ENT_QUOTES, 'UTF-8') . '"';
             }
           ?>><?= htmlspecialchars((string) $hl['label']) ?></a>
      <?php endforeach; ?>
      <button type="button" class="lb-hub-link" data-kopioi-linkki
              data-url="<?= htmlspecialchars(
                  (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? 'jahonline.com')
                  . '/public/kilpailu.php?kilpailu_id=' . (int) $kilpailu_id,
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>">Kopioi linkki</button>
    </div>
    <?php if ($ohjePalat !== []): ?>
      <p class="lb-hub-ohje"><?= htmlspecialchars(implode(', ', $ohjePalat) . '.', ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($kilpailu['tila'] === 'luonnos'): ?>
      <p class="lb-notice">Kilpailu on vielä luonnosvaiheessa eikä ole julkinen.</p>
    <?php endif; ?>
    <details class="lb-hub-lisatiedot">
      <summary>Lisätietoja</summary>
      <p class="lb-hub-ohje">TV-näyttö vaatii erillisen kirjautumisen (TV-käyttäjä).</p>
      <?php if ($pirila): ?>
        <p class="lb-hub-ohje">Suunnistus / Pirilä: rastiväliajat avautuvat kilpailijan nimestä online-näkymässä.</p>
      <?php endif; ?>
    </details>
  </div>

  <?php if ($seuraLinkit !== []): ?>
    <?php seuraKisavalikkoHtml($kilpailu, true); ?>
  <?php endif; ?>
</div>
<script>
(function () {
  var btn = document.querySelector('[data-kopioi-linkki]');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-url') || window.location.href;
    var done = function () {
      var vanha = btn.textContent;
      btn.textContent = 'Kopioitu';
      setTimeout(function () { btn.textContent = vanha; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(function () {
        window.prompt('Kopioi linkki:', url);
      });
    } else {
      window.prompt('Kopioi linkki:', url);
    }
  });
})();
</script>

<?php
tulospalveluLoppu();
pageEnd();
?>
