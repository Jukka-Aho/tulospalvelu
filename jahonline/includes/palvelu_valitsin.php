<?php

/**
 * Kirjautuneen käyttäjän palveluvalitsin (Iltarastit | Kilpailut | GPS-seuranta).
 */

function jahPalveluAktiivinen(?string $force = null): string
{
    if ($force === 'tulokset' || $force === 'gps' || $force === 'iltarastit') {
        return $force;
    }

    if ($force === 'julkinen') {
        return '';
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (str_starts_with($uri, '/gps')) {
        return 'gps';
    }
    if (str_contains($uri, 'omat_tapahtumat.php') || str_contains($uri, 'iltarastit.php') || str_contains($uri, '/iltarasti')) {
        return 'iltarastit';
    }
    $polku = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
    if ($polku === '/public/index.php' || $polku === '/public/' || $polku === '/public') {
        return '';
    }

    return 'tulokset';
}

/**
 * Palveluiden etusivut (oikeuksien mukaan).
 *
 * @return array{iltarastit:array{href:string,label:string},tulokset:array{href:string,label:string},gps:array{href:string,label:string}}
 */
function jahPalveluEtusivut(): array
{
    return [
        'iltarastit' => ['href' => '/public/omat_tapahtumat.php', 'label' => 'Omat tapahtumat'],
        'tulokset' => ['href' => '/public/omat_tapahtumat.php', 'label' => 'Omat tapahtumat'],
        'gps' => ['href' => '/gps/index.php', 'label' => 'GPS-seuranta'],
    ];
}

function jahNaytaOmatTapahtumat(?PDO $pdo = null): bool
{
    if (empty($_SESSION['user'])) {
        return false;
    }
    $oikeudet = jahPalveluOikeudet($pdo);
    if (!empty($oikeudet['iltarastit']) || !empty($oikeudet['tulokset'])) {
        return true;
    }

    return function_exists('voiKisahallintaan') && voiKisahallintaan();
}

/**
 * Voiko käyttäjä nähdä GPS / tulospalvelun valitsimessa.
 *
 * @return array{gps:bool,tulokset:bool,iltarastit:bool}
 */
function jahPalveluOikeudet(?PDO $pdo = null): array
{
    $gps = true;
    $tulokset = true;
    $iltarastit = true;

    if (!function_exists('tiliVoiPalvelun')) {
        $tili = __DIR__ . '/tili.php';
        if (is_readable($tili)) {
            require_once $tili;
        }
    }

    if (function_exists('tiliVoiPalvelun')) {
        $gps = tiliVoiPalvelun('gps');
        $tulokset = tiliVoiPalvelun('tulokset');
        $iltarastit = tiliVoiPalvelun('iltarastit');
    }

    if ($pdo instanceof PDO && function_exists('tiliVoi')) {
        if ($gps && !tiliVoi($pdo, 'gps')) {
            $gps = false;
        }
        if ($tulokset && !tiliVoi($pdo, 'tulospalvelu')) {
            $tulokset = false;
        }
    }

    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $gps = true;
        $tulokset = true;
        $iltarastit = true;
    }
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'iltarasti') {
        $iltarastit = true;
        $gps = false;
        $tulokset = false;
    }

    return ['gps' => $gps, 'tulokset' => $tulokset, 'iltarastit' => $iltarastit];
}

/**
 * Mitkä ohjeosiot näytetään tälle käyttäjälle.
 *
 * Kirjautumaton näkee nykyisen tuotteen (iltarastit).
 * Kirjautunut näkee seuralle avatut palvelut.
 *
 * @return array{iltarastit:bool,kilpailut:bool,gps:bool,tunnus:bool,seura_paa:bool}
 */
function jahOhjeetOsiot(?PDO $pdo = null): array
{
    $kirjautunut = !empty($_SESSION['user']);
    $admin = function_exists('onAdmin') && onAdmin();
    if ($kirjautunut) {
        $oikeudet = jahPalveluOikeudet($pdo);
    } else {
        $oikeudet = ['gps' => false, 'tulokset' => false, 'iltarastit' => true];
    }

    $seuraPaa = false;
    if ($kirjautunut) {
        if (function_exists('iltarastiOnPaakayttaja')) {
            $seuraPaa = iltarastiOnPaakayttaja();
        } else {
            $seuraPaa = $admin
                || (function_exists('onPaakayttaja') && onPaakayttaja())
                || in_array((string) ($_SESSION['seura_rooli'] ?? ''), ['omistaja', 'admin'], true);
        }
    }

    return [
        'iltarastit' => $admin || !empty($oikeudet['iltarastit']),
        'kilpailut' => $admin || !empty($oikeudet['tulokset']),
        'gps' => $admin || !empty($oikeudet['gps']),
        'tunnus' => $kirjautunut,
        'seura_paa' => $seuraPaa,
    ];
}

/**
 * Palvelulinkit hamburgeriin (Iltarastit / Kilpailut / GPS).
 */
function jahPalveluHamburgerOsio(?PDO $pdo = null, string $active = ''): void
{
    if (empty($_SESSION['user'])) {
        return;
    }

    $oikeudet = jahPalveluOikeudet($pdo);
    $sivut = jahPalveluEtusivut();
    $naytaOmat = !empty($oikeudet['iltarastit']) || !empty($oikeudet['tulokset']);
    if (!$naytaOmat && empty($oikeudet['gps'])) {
        return;
    }

    $aktiivinen = jahPalveluAktiivinen(null);
    if ($active === 'iltarastit' || $active === 'gps' || $active === 'omat_tapahtumat') {
        $aktiivinen = $active === 'omat_tapahtumat' ? 'iltarastit' : $active;
    } elseif ($active === 'index' && $aktiivinen === '') {
        $aktiivinen = 'tulokset';
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (str_contains($uri, 'omat_tapahtumat.php')) {
        $aktiivinen = 'iltarastit';
    }

    echo '<div class="admin-section">';
    echo '<div class="section-label">Palvelut</div>';
    echo '<nav class="admin-nav">';
    if ($naytaOmat) {
        $cls = in_array($aktiivinen, ['iltarastit', 'tulokset'], true) ? ' class="active"' : '';
        echo '<a href="' . htmlspecialchars($sivut['iltarastit']['href']) . '"' . $cls . '>'
            . htmlspecialchars($sivut['iltarastit']['label']) . '</a>';
    }
    if (!empty($oikeudet['gps'])) {
        $cls = $aktiivinen === 'gps' ? ' class="active"' : '';
        echo '<a href="' . htmlspecialchars($sivut['gps']['href']) . '"' . $cls . '>'
            . htmlspecialchars($sivut['gps']['label']) . '</a>';
    }
    echo '</nav></div>';
}

/**
 * Tulosta palveluvalitsin (vain kirjautuneille).
 */
function jahPalveluValitsin(?string $aktiivinen = null, ?PDO $pdo = null): void
{
    if (empty($_SESSION['user'])) {
        return;
    }

    $aktiivinen = jahPalveluAktiivinen($aktiivinen);
    $oikeudet = jahPalveluOikeudet($pdo);
    if (!$oikeudet['gps'] && !$oikeudet['tulokset'] && empty($oikeudet['iltarastit'])) {
        return;
    }
    $sivut = jahPalveluEtusivut();

    echo '<nav class="jah-palvelu-valitsin" aria-label="Valitse palvelu">';
    if (!empty($oikeudet['iltarastit']) || !empty($oikeudet['tulokset'])) {
        $cls = in_array($aktiivinen, ['iltarastit', 'tulokset'], true) ? ' is-active' : '';
        echo '<a class="jah-palvelu-btn' . $cls . '" href="' . htmlspecialchars($sivut['iltarastit']['href']) . '"'
            . ($cls !== '' ? ' aria-current="page"' : '')
            . '>' . htmlspecialchars($sivut['iltarastit']['label']) . '</a>';
    }
    if ($oikeudet['gps']) {
        $cls = $aktiivinen === 'gps' ? ' is-active' : '';
        echo '<a class="jah-palvelu-btn' . $cls . '" href="' . htmlspecialchars($sivut['gps']['href']) . '"'
            . ($aktiivinen === 'gps' ? ' aria-current="page"' : '')
            . '>' . htmlspecialchars($sivut['gps']['label']) . '</a>';
    }
    echo '</nav>';
}
