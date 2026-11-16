<?php

function jahTeemaNappi(): void
{
    echo '<button type="button" class="theme-toggle" onclick="toggleTheme()" aria-label="Vaihda tumma teema">'
        . '<span class="theme-toggle-icon" aria-hidden="true">🌙</span>'
        . '<span class="theme-toggle-label">Tumma</span></button>';
}

function jahMainosMerkki(string $luokka = ''): void
{
    $cls = trim('jah-mainos ' . $luokka);
    echo '<aside class="' . htmlspecialchars($cls) . '" aria-label="JAHOnline">'
        . '<img class="jah-mainos-logo" src="/assets/img/logo.png?v=5" width="116" height="116" alt="">'
        . '<span class="jah-mainos-tekstit">'
        . '<span class="jah-mainos-nimi">JAHOnline</span>'
        . '<span class="jah-mainos-url">jahonline.com</span>'
        . '</span></aside>';
}

function pageStart($title, array $opts = [])
{
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/palvelu_valitsin.php';
    require_once __DIR__ . '/page_loader.php';

    $base = $opts['base'] ?? '../';
    $class = $opts['class'] ?? '';
    $admin = $opts['admin'] ?? false;
    $active = $opts['active'] ?? '';
    $pdo = $opts['pdo'] ?? ($GLOBALS['pdo'] ?? null);

    $GLOBALS['jah_page_admin'] = (bool) $admin;

    if ($admin) {
        $class = trim($class . ' admin-body');
    }
    $iltarastiKuori = !empty($opts['iltarastiKuori']);
    $kuoriOtsikko = trim((string) ($opts['otsikko'] ?? ''));
    $kuoriKilpailuId = 0;

    $kirjautunut = !empty($_SESSION['user']);
    if ($kirjautunut && $pdo instanceof PDO && function_exists('tiliVaadiSahkoposti')) {
        tiliVaadiSahkoposti($pdo);
    }
    $kayttajaNimi = $kirjautunut ? (string) $_SESSION['user'] : '';
    $kayttajaRooli = $kirjautunut ? rooliLabel((string) ($_SESSION['role'] ?? '')) : '';
    $palveluAktiivinen = (string) ($opts['palvelu'] ?? '');
    if ($palveluAktiivinen === '' && $admin && $pdo instanceof PDO && function_exists('haeValittuKilpailu') && function_exists('kisaOnIltarasti')) {
        $valittuKisa = haeValittuKilpailu($pdo);
        if ($valittuKisa && kisaOnIltarasti($valittuKisa)) {
            $palveluAktiivinen = 'iltarastit';
        }
    }
    if ($palveluAktiivinen === '' && function_exists('onIltarasti') && onIltarasti() && !(function_exists('voiKisahallintaan') && voiKisahallintaan())) {
        $palveluAktiivinen = 'iltarastit';
    }
    $embed = !empty($opts['embed']);
    if (
        !$iltarastiKuori
        && $kirjautunut
        && empty($opts['embed'])
        && (
            ($opts['palvelu'] ?? '') === 'iltarastit'
            || (
                function_exists('onIltarasti')
                && onIltarasti()
                && !(function_exists('voiKisahallintaan') && voiKisahallintaan())
            )
        )
    ) {
        $iltarastiKuori = true;
    }
    if ($iltarastiKuori) {
        $class = trim($class . ' iltarasti-kuori');
        $GLOBALS['jah_iltarasti_kuori'] = true;
        if ($pdo instanceof PDO && function_exists('haeValittuKilpailu')) {
            $vk = haeValittuKilpailu($pdo);
            if (is_array($vk) && (!function_exists('kisaOnIltarasti') || kisaOnIltarasti($vk))) {
                $kuoriKilpailuId = (int) ($vk['id'] ?? 0);
                if ($kuoriOtsikko === '' && $active !== 'iltarastit' && !empty($vk['nimi'])) {
                    $kuoriOtsikko = (string) $vk['nimi'];
                }
            }
        }
        if ($kuoriOtsikko === '') {
            $kuoriOtsikko = $title;
        }
    }
    $GLOBALS['jah_page_embed'] = $embed;
    $GLOBALS['jah_page_admin_layout'] = false;

    $naytaValikko = $admin || ($kirjautunut && (
        (function_exists('onAdmin') && onAdmin())
        || (function_exists('voiKisahallintaan') && voiKisahallintaan())
        || (function_exists('onLahtopaikka') && onLahtopaikka())
        || (function_exists('onIltarasti') && onIltarasti())
    ));
    if ($embed || (!empty($opts['lukija']) && !$iltarastiKuori)) {
        $naytaValikko = false;
    }
    if ($iltarastiKuori) {
        $naytaValikko = $kirjautunut;
        $admin = true;
        $GLOBALS['jah_page_admin'] = true;
    }
    $GLOBALS['jah_page_valikko'] = $naytaValikko;
    if ($naytaValikko) {
        $class = trim($class . ' jah-nav-drawer');
    }
    if (!headers_sent()) {
        header('Permissions-Policy: serial=*, usb=*, fullscreen=*, autoplay=*, publickey-credentials-get=*');
        header('Feature-Policy: serial *; usb *; fullscreen *; autoplay *');
        if ($iltarastiKuori) {
            header('Cache-Control: private, max-age=0, must-revalidate');
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
    }
    if ($iltarastiKuori) {
        require_once __DIR__ . '/iltarasti_kuori_kehys.php';
        if (
            !$embed
            && empty($opts['eiKuoriKehys'])
            && $active !== 'iltarastit'
            && $kuoriKilpailuId > 0
            && !jahKuoriOnUpotus()
        ) {
            jahTulostaKuoriKehys($kuoriOtsikko !== '' ? $kuoriOtsikko : $title, $opts, $kuoriKilpailuId);
            exit;
        }
    }

    echo '<!DOCTYPE html><html lang="fi"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">';
    echo '<title>' . htmlspecialchars($title) . ' - JAHOnline</title>';
    // Teema ennen CSS:ää — estää vaalean välähdyksen iframessa / sivulatauksessa
    echo '<script>(function(){try{'
        . 'var k="jahonline-theme",t=null;'
        . 'try{t=localStorage.getItem(k)}catch(e){}'
        . 'if(t!=="dark"&&t!=="light"){'
        . 'var m=/[?&]teema=(dark|light)/.exec(location.search);'
        . 't=m?m[1]:"light";'
        . '}'
        . 'document.documentElement.setAttribute("data-theme",t);'
        . '}catch(e){document.documentElement.setAttribute("data-theme","light")}})();</script>';
    echo '<script>(function(){try{var pwa=false;'
        . 'if(window.matchMedia){pwa=matchMedia("(display-mode: standalone)").matches||matchMedia("(display-mode: fullscreen)").matches;}'
        . 'if(navigator.standalone)pwa=true;'
        . 'if(pwa)document.documentElement.classList.add("jah-pwa");'
        . '}catch(e){}})();</script>';
    echo '<link rel="icon" href="/assets/img/favicon.png?v=5" type="image/png">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="/gps/assets/icons/apple-touch-icon.png?v=5">';
    echo '<link rel="icon" type="image/png" sizes="192x192" href="/gps/assets/icons/icon-192.png?v=5">';
    echo '<link rel="icon" type="image/png" sizes="512x512" href="/gps/assets/icons/icon-512.png?v=5">';
    if (!empty($opts['manifest'])) {
        echo '<link rel="manifest" href="' . htmlspecialchars((string) $opts['manifest']) . '">';
        echo '<meta name="theme-color" content="#0a0a0a">';
        echo '<meta name="mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black">';
        echo '<meta name="apple-mobile-web-app-title" content="'
            . htmlspecialchars((string) ($opts['pwaTitle'] ?? 'JAHOnline')) . '">';
    } elseif (!$embed) {
        echo '<link rel="manifest" href="/gps/manifest.php?v=6">';
        echo '<meta name="theme-color" content="#0a0a0a">';
        echo '<meta name="mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black">';
        echo '<meta name="apple-mobile-web-app-title" content="JAHOnline">';
    }
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/assets/css/maali_jono.css?v=20261114ylapalkki">';
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=20261116kisat">';
    echo '<link rel="stylesheet" href="/assets/css/jah_ruksi.css?v=20261114ruksi">';
    echo '<link rel="stylesheet" href="/assets/css/palvelu_valitsin.css?v=20261106mobiili">';
    echo '<link rel="stylesheet" href="/assets/css/seura_kisavalikko.css?v=20261019gold3">';
    if ($iltarastiKuori) {
        echo '<link rel="stylesheet" href="/assets/css/iltarasti_kuori.css?v=20261114pcusb2">';
    }
    echo '<script src="/assets/js/theme.js?v=20261114teema3"></script>';
    if (!$embed) {
        jahPageLoaderHead();
    }

    if (!empty($opts['extraHead'])) {
        echo $opts['extraHead'];
    }

    echo '</head><body class="' . htmlspecialchars($class) . ($embed ? ' jah-embed' : '') . '"'
        . ($kuoriKilpailuId > 0 ? ' data-jah-kilpailu="' . $kuoriKilpailuId . '"' : '')
        . '>';
    echo '<script>(function(){try{var t=document.documentElement.getAttribute("data-theme");'
        . 'if(t)document.body.setAttribute("data-theme",t)}catch(e){}})();</script>';
    if (!$embed && function_exists('jahPageLoaderMarkup')) {
        jahPageLoaderMarkup();
    }

    if ($embed) {
        echo '<div class="container container--embed">';

        return;
    }

    $valikkoNappi = static function () use ($naytaValikko): void {
        if (!$naytaValikko) {
            return;
        }
        echo '<button type="button" class="admin-menu-toggle" id="admin-menu-toggle" aria-expanded="false" aria-controls="admin-sidebar" aria-label="Avaa valikko">';
        echo '<span class="admin-menu-toggle-bars" aria-hidden="true"></span>';
        echo '<span class="admin-menu-toggle-label">Valikko</span>';
        echo '</button>';
    };

    if (!empty($opts['lukija']) && !$iltarastiKuori) {
        $poisHref = (string) ($opts['hallintaHref'] ?? '/admin/index.php');
        echo '<div class="site-topbar">';
        $poisTxt = (str_contains($poisHref, 'iltarastit') || $palveluAktiivinen === 'iltarastit')
            ? '← Iltarastit' : '← Kilpailun hallinta';
        if (!empty($opts['lukijaPoistuRuksi'])) {
            echo '<button type="button" class="lukija-poistu huuto-poistu jah-ruksi" id="huuto-poistu"'
                . ' data-href="' . htmlspecialchars($poisHref) . '"'
                . ' aria-haspopup="dialog" aria-controls="'
                . htmlspecialchars((string) ($opts['lukijaPoistuVarmistusId'] ?? 'huuto-poistu-varmistus')) . '"'
                . ' aria-label="Poistu" title="Poistu">'
                . (function_exists('jahRuksiSvg') ? jahRuksiSvg() : '×')
                . '</button>';
        } elseif (!empty($opts['lukijaPoistuVarmistus'])) {
            echo '<button type="button" class="lukija-poistu huuto-poistu jah-ruksi" id="lukija-poistu"'
                . ' data-href="' . htmlspecialchars($poisHref) . '"'
                . ' aria-haspopup="dialog" aria-controls="kello-poistu-varmistus"'
                . ' aria-label="Poistu" title="Poistu">'
                . (function_exists('jahRuksiSvg') ? jahRuksiSvg() : '×') . '</button>';
        } else {
            echo '<a class="lukija-poistu huuto-poistu jah-ruksi" href="'
                . htmlspecialchars($poisHref) . '" aria-label="Poistu" title="Poistu">'
                . (function_exists('jahRuksiSvg') ? jahRuksiSvg() : '×') . '</a>';
        }
        if (!empty($opts['lukijaLogo'])) {
            echo '<img class="lukija-logo" src="/assets/img/logo.png?v=5" width="116" height="116" alt="JAHOnline">';
        }
        if (!empty($opts['lukijaToimet'])) {
            echo '<div class="site-topbar-actions">' . $opts['lukijaToimet'] . '</div>';
        }
        echo '</div>';
        echo '<div class="container">';
        return;
    }

    echo '<div class="site-topbar' . ($admin ? ' site-topbar--admin' : '') . '">';
    echo '<div class="site-topbar-left">';
    $valikkoNappi();
    if ($iltarastiKuori) {
        echo '<span class="iltarasti-kuori-nimi">' . htmlspecialchars($kuoriOtsikko !== '' ? $kuoriOtsikko : $title) . '</span>';
        echo '</div>';
        echo '<div class="site-topbar-actions">';
        $tilaLuvut = ['maastossa' => 0, 'esitykset' => 0];
        $tilaHallinta = false;
        $vkTila = null;
        if ($kuoriKilpailuId > 0 && $pdo instanceof PDO) {
            if (!function_exists('iltarastiTilanneLuvut')) {
                require_once __DIR__ . '/iltarasti_esitys.php';
            }
            $tilaLuvut = iltarastiTilanneLuvut($pdo, ['id' => $kuoriKilpailuId]);
            if ($kirjautunut && function_exists('haeKilpailu') && function_exists('voiHallitaKilpailua')) {
                $vkTila = haeKilpailu($pdo, $kuoriKilpailuId);
                $tilaHallinta = $vkTila && voiHallitaKilpailua($vkTila);
            }
        }
        $maastoNro = (int) ($tilaLuvut['maastossa'] ?? 0);
        $esitysNro = (int) ($tilaLuvut['esitykset'] ?? 0);
        echo '<span class="iltarasti-tila-rivi" id="iltarasti-tila">';
        echo '<span class="iltarasti-yhteys-rivi">'
            . '<span class="iltarasti-yhteys-selite" aria-hidden="true">Palvelin</span>'
            . '<span class="iltarasti-yhteys is-online" id="iltarasti-yhteys"'
            . ' role="img" data-online="Palvelin ok" data-offline="Ei verkkoa"'
            . ' title="Palvelin ok" aria-label="Palvelin ok"></span></span>';
        echo ($tilaHallinta
            ? '<a href="/public/kilpailijat.php?kilpailu_id=' . (int) $kuoriKilpailuId . '"'
            : '<span')
            . ' class="iltarasti-tila-luku' . ($maastoNro ? '' : ' is-tyhja') . '" id="iltarasti-maastossa">'
            . 'Maastossa <span class="iltarasti-tila-nro">' . $maastoNro . '</span>'
            . ($tilaHallinta ? '</a>' : '</span>');
        if ($tilaHallinta) {
            echo '<a href="/public/iltarasti_esitykset.php?kilpailu_id=' . (int) $kuoriKilpailuId . '"'
                . ' class="iltarasti-tila-luku is-esitys' . ($esitysNro ? '' : ' is-tyhja') . '" id="iltarasti-esitykset">'
                . 'Esitys <span class="iltarasti-tila-nro">' . $esitysNro . '</span></a>';
            $rlLyhyt = ['tila' => '', 'lyhyt' => '', 'teksti' => ''];
            if (!empty($vkTila) && function_exists('rastilippuLahetysTilaLyhyt')) {
                $rlLyhyt = rastilippuLahetysTilaLyhyt($vkTila);
            }
            if (($rlLyhyt['tila'] ?? '') !== '') {
                $rlLuokka = 'is-rastilippu';
                if (($rlLyhyt['tila'] ?? '') === 'virhe') {
                    $rlLuokka .= ' is-virhe';
                } elseif (($rlLyhyt['tila'] ?? '') === 'ok') {
                    $rlLuokka .= ' is-ok';
                }
                echo '<a href="/public/iltarasti_asetukset.php?kilpailu_id=' . (int) $kuoriKilpailuId . '"'
                    . ' class="iltarasti-tila-luku ' . $rlLuokka . '" id="iltarasti-rastilippu"'
                    . ' title="' . htmlspecialchars((string) ($rlLyhyt['teksti'] ?? 'Rastilippu')) . '">'
                    . 'Rastilippu <span class="iltarasti-tila-nro">'
                    . htmlspecialchars((string) ($rlLyhyt['lyhyt'] ?? ''))
                    . '</span></a>';
            }
        }
        echo '</span>';
        jahTeemaNappi();
        echo '</div></div>';
        require_once __DIR__ . '/admin_nav.php';
        echo '<div class="admin-layout">';
        $GLOBALS['jah_page_admin_layout'] = true;
        adminShell($active, $pdo instanceof PDO ? $pdo : null);
        echo '<div class="admin-main">';
        echo '<div class="container admin-container">';

        return;
    }
    $brandHref = $admin ? '/admin/index.php' : '/public/index.php';
    if ($palveluAktiivinen === 'iltarastit' || (function_exists('onIltarasti') && onIltarasti() && !(function_exists('voiKisahallintaan') && voiKisahallintaan()))) {
        $brandHref = '/public/omat_tapahtumat.php';
    }
    echo '<a class="site-brand" href="' . htmlspecialchars($brandHref) . '">';
    echo '<img class="site-brand-logo" src="/assets/img/logo.png?v=5" width="116" height="116" alt="">';
    echo '<span class="site-brand-text">JAHOnline</span>';
    echo '</a>';
    if ($admin) {
        echo '<span class="site-topbar-sub">Hallinta</span>';
    }
    $etusivu = $palveluAktiivinen === 'julkinen';
    if ($kirjautunut && !$etusivu) {
        jahPalveluValitsin($palveluAktiivinen !== '' ? $palveluAktiivinen : null, $pdo instanceof PDO ? $pdo : null);
    }
    echo '</div>';
    echo '<div class="site-topbar-actions">';
    if ($kirjautunut) {
        require_once __DIR__ . '/seura_kisavalikko.php';
        $naytaOmat = !$admin && function_exists('jahNaytaOmatTapahtumat')
            && jahNaytaOmatTapahtumat($pdo instanceof PDO ? $pdo : null);
        if ($naytaOmat) {
            echo '<a class="site-topbar-btn site-topbar-btn--primary" href="/public/omat_tapahtumat.php">Omat tapahtumat</a>';
        }
        $tyokalu = seuraRooliTyokalu();
        if ($etusivu && !$naytaOmat && $tyokalu && !$admin) {
            echo '<a class="site-topbar-btn site-topbar-btn--primary" href="'
                . htmlspecialchars($tyokalu['href']) . '">'
                . htmlspecialchars($tyokalu['label']) . '</a>';
        }
        if (!$etusivu && $tyokalu && !$admin && (!$naytaOmat || ($tyokalu['href'] ?? '') !== '/admin/index.php')) {
            echo '<a class="site-topbar-btn site-topbar-btn--primary" href="'
                . htmlspecialchars($tyokalu['href']) . '">'
                . htmlspecialchars($tyokalu['label']) . '</a>';
        }
        // Hallinta-linkki myös muille sivuille (ei piilota admin-valikkoa)
        if (!$etusivu && !$admin && function_exists('voiKisahallintaan') && voiKisahallintaan()) {
            $hallintaHref = '/admin/index.php';
            if (!$tyokalu || ($tyokalu['href'] ?? '') !== $hallintaHref) {
                echo '<a class="site-topbar-btn site-topbar-btn--primary" href="'
                    . htmlspecialchars($hallintaHref) . '">Hallinta</a>';
            }
        }
        echo '<span class="site-topbar-user muted" title="' . htmlspecialchars($kayttajaRooli) . '">'
            . htmlspecialchars($kayttajaNimi) . '</span>';
        echo '<a class="site-topbar-btn" href="/auth/logout.php">Ulos</a>';
    } else {
        echo '<a class="site-topbar-btn site-topbar-btn--primary" href="/auth/login.php">Kirjaudu</a>';
    }
    jahTeemaNappi();
    echo '</div>';
    echo '</div>';

    if ($naytaValikko) {
        require_once __DIR__ . '/admin_nav.php';
        if ($admin) {
            echo '<div class="admin-layout">';
            $GLOBALS['jah_page_admin_layout'] = true;
            adminShell($active, $pdo instanceof PDO ? $pdo : null);
            echo '<div class="admin-main">';
            echo '<div class="container admin-container">';
        } else {
            adminShell($active, $pdo instanceof PDO ? $pdo : null);
            echo '<div class="container">';
        }
    } else {
        echo '<div class="container">';
    }
}

function pageEnd()
{
    $adminLayout = !empty($GLOBALS['jah_page_admin_layout']);
    $valikko = !empty($GLOBALS['jah_page_valikko']);
    $embed = !empty($GLOBALS['jah_page_embed']);

    echo '</div>'; // .container

    if ($adminLayout) {
        echo '</div>'; // .admin-main
        echo '</div>'; // .admin-layout
    }
    if ($valikko) {
        echo '<script src="/assets/js/admin_nav.js?v=20261016avaus"></script>';
    }
    if (!empty($GLOBALS['jah_iltarasti_kuori'])) {
        echo '<script src="/assets/js/iltarasti_kuori_kehys.js?v=20261114tausta"></script>';
    }

    if (!$embed) {
        echo '<script src="/assets/js/jah-pwa.js?v=20261116hamp"></script>';
        jahPageLoaderFoot();
    }
    echo '</body></html>';
}
