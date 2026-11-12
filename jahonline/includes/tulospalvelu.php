<?php

/**
 * Julkisen tulospalvelun yhtenäinen kuori (sama teema kuin online-seuranta).
 */

require_once __DIR__ . '/helpers.php';

/** Cache-bust live_board.css / style.css -päivityksille. */
const TULOSPALVELU_ASSET_V = '20261112esitys';

/**
 * pageStart-optiot live-board -teemalle.
 *
 * @param string $extraClass Lisäluokka bodyyn (esim. seuranta-page)
 */
function tulospalveluPageOpts(string $extraClass = ''): array
{
    $class = trim('live-board-page tulospalvelu-page ' . $extraClass);
    $v = TULOSPALVELU_ASSET_V;

    return [
        'base' => '../',
        'class' => $class,
        'extraHead' => '<link rel="stylesheet" href="/assets/css/live_board.css?v=' . $v . '">'
            . '<link rel="stylesheet" href="/assets/css/seura_kisavalikko.css?v=1">'
            . '<link rel="manifest" href="/gps/manifest.php">'
            . '<meta name="theme-color" content="#0a0a0a">'
            . '<meta name="apple-mobile-web-app-capable" content="yes">'
            . '<meta name="apple-mobile-web-app-title" content="JAHOnline">'
            . '<link rel="apple-touch-icon" href="/gps/assets/icons/apple-touch-icon.png?v=5">',
    ];
}

/**
 * Yläpalkki: navigointi + kisanimi + sivun meta.
 *
 * @param array $kilpailu
 * @param array $linkit julkinenLinkit(...)
 * @param string $sivuOtsikko Lyhyt sivun nimi metariville (esim. Lähtölista)
 * @param array $opts [
 *   'aktiivinen' => kilpailu|seuranta|lahtolista|ilmoittautuneet|ilmoittautuminen|valiajat,
 *   'meta_html' => string|null,
 *   'haku' => bool,
 *   'extra_actions' => list<array{href?:string,label:string,active?:bool,button?:bool,attrs?:string}>,
 * ]
 */
function tulospalveluYla(array $kilpailu, array $linkit, string $sivuOtsikko, array $opts = []): void
{
    require_once __DIR__ . '/kisa_liput.php';
    demoKisaIlmoitus($kilpailu);

    $aktiivinen = (string) ($opts['aktiivinen'] ?? '');
    $metaHtml = $opts['meta_html'] ?? null;
    $haku = !empty($opts['haku']);
    $extraActions = $opts['extra_actions'] ?? [];
    $kilpailuId = (int) ($kilpailu['id'] ?? 0);
    $live = function_exists('kilpailuOnLive')
        ? kilpailuOnLive($kilpailu)
        : (($kilpailu['tila'] ?? '') === 'kaynnissa');
    $seurantaLabel = function_exists('seurantaNavLabel')
        ? seurantaNavLabel($kilpailu)
        : ($live ? 'Online' : 'Tulokset');

    $seurantaHref = (string) ($linkit['seuranta'] ?? 'seuranta.php');
    // Rastiväliajat + Online-väliajat ylävalikkoon Pirilä-/suunnistuskisoissa
    $onPirilaNav = function_exists('pirilaSynkkaPaalla')
        ? pirilaSynkkaPaalla($kilpailu)
        : ((($kilpailu['kisa_tyyppi'] ?? '') === 'pirila') || !empty($kilpailu['pirila_synkka']));
    if ($onPirilaNav && $kilpailuId > 0) {
        // Tulokset-linkki aina nakyma=tulokset (ei viimeksi käytyyn näkymään)
        $seurantaHref = kilpailuKuoriUrl($kilpailuId, 'online', ['nakyma' => 'tulokset']);
    }

    $nav = [
        ['key' => 'kaikki', 'href' => 'index.php', 'label' => 'Kaikki kisat'],
        ['key' => 'kilpailu', 'href' => $linkit['kilpailu'] ?? 'kilpailu.php', 'label' => 'Kilpailu'],
        ['key' => 'seuranta', 'href' => $seurantaHref, 'label' => $seurantaLabel],
    ];

    if ($onPirilaNav && $kilpailuId > 0) {
        $onlineHref = kilpailuKuoriUrl($kilpailuId, 'online', ['nakyma' => 'online']);
        $nav[] = [
            'key' => 'online',
            'href' => $onlineHref,
            'label' => 'Online-väliajat',
        ];
        $valiaHref = kilpailuKuoriUrl($kilpailuId, 'online', ['nakyma' => 'valiajat']);
        $nav[] = [
            'key' => 'valiajat',
            'href' => $valiaHref,
            'label' => 'Rastiväliajat',
        ];
    }

    $nav[] = ['key' => 'lahtolista', 'href' => $linkit['lahtolista'] ?? 'lahtolista.php', 'label' => 'Lähtöajat'];

    $pdoNav = (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null;
    $naytaIlmoValikko = (function_exists('naytaIlmoittautuminenValikossa') && naytaIlmoittautuminenValikossa($kilpailu))
        || in_array($aktiivinen, ['ilmoittautuneet', 'ilmoittautuminen'], true);
    $naytaIlmoLomake = $pdoNav instanceof PDO && function_exists('naytaIlmoittautuminenLomakeValikossa')
        ? naytaIlmoittautuminenLomakeValikossa($pdoNav, $kilpailu)
        : $naytaIlmoValikko;
    if ($naytaIlmoValikko) {
        if ($naytaIlmoLomake || $aktiivinen === 'ilmoittautuminen') {
            $nav[] = [
                'key' => 'ilmoittautuminen',
                'href' => $linkit['ilmoittautuminen'] ?? 'ilmoittautuminen.php',
                'label' => 'Ilmoittautuminen',
            ];
        }
        $nav[] = [
            'key' => 'ilmoittautuneet',
            'href' => $linkit['ilmoittautuneet'] ?? 'ilmoittautuneet.php',
            'label' => 'Ilmoittautuneet',
        ];
    }

    // IOF XML vain valmiissa kisassa
    $iofHref = null;
    if ((string) ($kilpailu['tila'] ?? '') === 'valmis' && $kilpailuId > 0) {
        $iofHref = 'tulokset_iof.php?kilpailu_id=' . $kilpailuId;
        $nav[] = [
            'key' => 'iof',
            'href' => $iofHref,
            'label' => 'IOF XML',
            'download' => true,
            'title' => 'Lataa tulokset IOF XML 3.0 -muodossa',
        ];
    }

    echo '<div class="lb">';
    echo '<header class="lb-top">';
    echo '<nav class="lb-actions" aria-label="Tulospalvelun linkit">';

    foreach ($nav as $item) {
        $isActive = $aktiivinen !== '' && $item['key'] === $aktiivinen;
        $attrs = '';
        if (!empty($item['download'])) {
            $attrs .= ' download';
        }
        if (!empty($item['title'])) {
            $attrs .= ' title="' . htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') . '"';
        }
        echo '<a href="' . htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') . '"'
            . ($isActive ? ' class="is-active" aria-current="page"' : '')
            . $attrs
            . '>' . htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }

    foreach ($extraActions as $ex) {
        $label = (string) ($ex['label'] ?? '');
        if ($label === '') {
            continue;
        }
        $cls = !empty($ex['active']) ? ' class="is-active"' : '';
        $attrs = trim((string) ($ex['attrs'] ?? ''));
        if (!empty($ex['button'])) {
            echo '<button type="button"' . $cls
                . ($attrs !== '' ? ' ' . $attrs : '')
                . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
        } else {
            $href = (string) ($ex['href'] ?? '#');
            echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $cls
                . ($attrs !== '' ? ' ' . $attrs : '')
                . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
    }

    if ($haku && $kilpailuId > 0) {
        echo '<button type="button" data-tulos-haku data-kilpailu-id="'
            . $kilpailuId . '">Haku tuloksista</button>';
    }

    echo '</nav>';
    echo '<div class="lb-kisa">' . htmlspecialchars((string) ($kilpailu['nimi'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<p class="lb-meta">';
    echo htmlspecialchars($sivuOtsikko, ENT_QUOTES, 'UTF-8');
    if ($live) {
        echo ' · <span class="lb-live">LIVE</span>';
    } elseif (!empty($kilpailu['tila'])) {
        echo ' · ' . htmlspecialchars(kilpailuTilaLabel($kilpailu['tila']), ENT_QUOTES, 'UTF-8');
    }
    if ($metaHtml !== null && $metaHtml !== '') {
        echo ' · ' . $metaHtml;
    }
    echo '</p>';
    echo '</header>';
}

/** Sulje tulospalveluYla:n avaama .lb-div. */
function tulospalveluLoppu(): void
{
    echo '</div>'; // .lb
}
