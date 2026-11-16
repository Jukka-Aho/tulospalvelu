<?php

/**
 * Yhtenäinen session-aloitus (cookie-asetukset aina samat).
 * Estää tilanteen, jossa csrf/helpers käynnistää sessionin ilman Secure/SameSite.
 */
function jahAloitaSessio(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

jahAloitaSessio();

$tiliPath = __DIR__ . '/tili.php';
if (is_readable($tiliPath)) {
    require_once $tiliPath;
}

/** Sallitut roolit. */
function sallitutRoolit(): array
{
    return ['admin', 'paakayttaja', 'ajanottaja', 'lahtopaikka', 'lahtotoiminnat', 'ampumapaikat', 'tv', 'iltarasti'];
}

/** Aliakäyttäjien roolit (ajanotto, lähtö, ampuma, TV). */
function kenttaKayttajaRoolit(): array
{
    return ['ajanottaja', 'lahtopaikka', 'lahtotoiminnat', 'ampumapaikat', 'tv', 'iltarasti'];
}

function kayttajaRoolitVarmista(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $haluttu = sallitutRoolit();
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'");
        $col = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        $type = (string) ($col['Type'] ?? '');
        $puuttuu = false;
        foreach ($haluttu as $r) {
            if (stripos($type, "'" . $r . "'") === false) {
                $puuttuu = true;
                break;
            }
        }
        if ($puuttuu) {
            $enum = implode(',', array_map(static fn ($r) => "'" . $r . "'", $haluttu));
            $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM($enum) NOT NULL DEFAULT 'ajanottaja'");
        }
    } catch (Throwable $e) {
    }
}

function kayttajaRooli(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

function kayttajaId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function nykyinenUserId(): int
{
    return (int) (kayttajaId() ?? 0);
}

function nykyinenSeuraId(): int
{
    return (int) ($_SESSION['seura_id'] ?? 0);
}

/** Pääkäyttäjän id: oma id admin/paakayttaja, muuten parent. */
function paakayttajaId(): ?int
{
    $rooli = kayttajaRooli();
    if ($rooli === 'admin' || $rooli === 'paakayttaja') {
        return kayttajaId();
    }

    return isset($_SESSION['paakayttaja_id']) ? (int) $_SESSION['paakayttaja_id'] : null;
}

function onAdmin(): bool
{
    return kayttajaRooli() === 'admin';
}

function onPaakayttaja(): bool
{
    $r = kayttajaRooli();

    return $r === 'admin' || $r === 'paakayttaja';
}

function onAjanottaja(): bool
{
    $r = kayttajaRooli();

    return in_array($r, ['admin', 'paakayttaja', 'ajanottaja'], true);
}

function onLahtopaikka(): bool
{
    $r = kayttajaRooli();

    return in_array($r, ['admin', 'paakayttaja', 'lahtopaikka', 'lahtotoiminnat'], true);
}

function onLahtotoiminnat(): bool
{
    $r = kayttajaRooli();

    return in_array($r, ['admin', 'paakayttaja', 'lahtopaikka', 'lahtotoiminnat'], true);
}

function onAmpumapaikat(): bool
{
    $r = kayttajaRooli();

    return in_array($r, ['admin', 'paakayttaja', 'ampumapaikat', 'ajanottaja'], true);
}

function onTv(): bool
{
    $r = kayttajaRooli();

    return in_array($r, ['admin', 'paakayttaja', 'tv'], true);
}

function onIltarasti(): bool
{
    $r = kayttajaRooli();

    return in_array($r, ['admin', 'paakayttaja', 'iltarasti'], true);
}

function vaadiKirjautuminen(): void
{
    if (!isset($_SESSION['user'])) {
        $kohde = $_SERVER['REQUEST_URI'] ?? '';
        $login = '/auth/login.php';
        if ($kohde !== '') {
            $login .= '?redirect=' . urlencode($kohde);
        }
        header('Location: ' . $login);
        exit;
    }

    if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        if (function_exists('tiliLataaKayttajaSessioon')) {
            tiliLataaKayttajaSessioon($GLOBALS['pdo']);
        }
        if (function_exists('tiliVaadiSahkoposti')) {
            tiliVaadiSahkoposti($GLOBALS['pdo']);
        }
    }
}

function vaadiAdmin(): void
{
    vaadiKirjautuminen();
    if (!onAdmin()) {
        http_response_code(403);
        die('Ei käyttöoikeutta');
    }
}

function vaadiPaakayttaja(): void
{
    vaadiKirjautuminen();
    if (!onPaakayttaja()) {
        http_response_code(403);
        die('Ei käyttöoikeutta');
    }
}

/**
 * Kilpailuhallinta: järjestelmän pääkäyttäjä TAI seuran omistaja/admin.
 */
function voiKisahallintaan(): bool
{
    if (onPaakayttaja()) {
        return true;
    }
    if (function_exists('haeSeuraAdminSeuraIdt') && haeSeuraAdminSeuraIdt() !== []) {
        return true;
    }
    if (function_exists('haeSeuraKisaValikkoSeuraIdt') && haeSeuraKisaValikkoSeuraIdt() !== []) {
        return true;
    }
    if (function_exists('tiliKayttajaKisaValikot') && tiliKayttajaKisaValikot() !== []) {
        return true;
    }
    return function_exists('onSeuraAdmin') && onSeuraAdmin();
}

function vaadiKisahallinta(): void
{
    vaadiKirjautuminen();
    if (voiKisahallintaan()) {
        return;
    }
    if (function_exists('onIltarasti') && onIltarasti()) {
        return;
    }
    http_response_code(403);
    die('Ei käyttöoikeutta (vaaditaan pääkäyttäjä tai seuran admin)');
}

function vaadiIltarasti(): void
{
    vaadiKirjautuminen();
    if (function_exists('iltarastiVoiPalvelun') && iltarastiVoiPalvelun()) {
        return;
    }
    if (onIltarasti() || onPaakayttaja()) {
        return;
    }
    http_response_code(403);
    die('Ei käyttöoikeutta (vaaditaan iltarastit)');
}

function vaadiRooli(array $roolit): void
{
    vaadiKirjautuminen();
    if (!in_array(kayttajaRooli(), $roolit, true) && !onAdmin()) {
        http_response_code(403);
        die('Ei käyttöoikeutta');
    }
}

function vaadiAjanottaja(): void
{
    vaadiKirjautuminen();
    if (onAjanottaja()) {
        return;
    }
    if (function_exists('tiliNaytaKisaValikko') && tiliNaytaKisaValikko('ajanotto')) {
        return;
    }
    http_response_code(403);
    die('Ei käyttöoikeutta (vaaditaan ajanottaja)');
}

function vaadiLahtopaikka(): void
{
    vaadiKirjautuminen();
    if (onLahtopaikka()) {
        return;
    }
    if (function_exists('tiliNaytaKisaValikko') && tiliNaytaKisaValikko('lahtopaikka')) {
        return;
    }
    http_response_code(403);
    die('Ei käyttöoikeutta (vaaditaan lähtöpaikka)');
}

function vaadiLahtotoiminnat(): void
{
    vaadiKirjautuminen();
    if (!onLahtotoiminnat()) {
        http_response_code(403);
        die('Ei käyttöoikeutta (vaaditaan lähtötoiminnat)');
    }
}

function vaadiAmpumapaikat(): void
{
    vaadiKirjautuminen();
    if (!onAmpumapaikat()) {
        http_response_code(403);
        die('Ei käyttöoikeutta (vaaditaan ampumapaikat)');
    }
}

function vaadiTv(): void
{
    vaadiKirjautuminen();
    if (!onTv()) {
        http_response_code(403);
        die('Ei käyttöoikeutta (vaaditaan TV-näyttö)');
    }
}

function rooliLabel(string $rooli): string
{
    return match ($rooli) {
        'admin' => 'Pääkäyttäjä (järjestelmä)',
        'paakayttaja' => 'Pääkäyttäjä',
        'ajanottaja' => 'Ajanottaja',
        'lahtopaikka' => 'Lähtöpaikka',
        'lahtotoiminnat' => 'Lähtötoiminnat',
        'ampumapaikat' => 'Ampumapaikat',
        'tv' => 'TV-näyttö',
        'iltarasti' => 'Iltarastit',
        default => $rooli,
    };
}

/** Kirjautumisen jälkeinen oletussivu roolin mukaan. */
function kirjautumisKohde(string $rooli): string
{
    if (function_exists('tiliKirjautumisOsoite')) {
        return tiliKirjautumisOsoite($rooli);
    }

    return match ($rooli) {
        'admin', 'paakayttaja' => '../admin/index.php',
        'ajanottaja' => '../public/ajanotto_valinta.php',
        'lahtopaikka', 'lahtotoiminnat' => '../public/lahto_valinta.php',
        'ampumapaikat' => '../public/ampuma_valinta.php',
        'tv' => '../public/tv_valinta.php',
        'iltarasti' => '/public/omat_tapahtumat.php',
        default => '../admin/index.php',
    };
}

function turvallinenRedirect(?string $polku): ?string
{
    if ($polku === null || $polku === '') {
        return null;
    }
    if ($polku[0] !== '/' || str_starts_with($polku, '//')) {
        return null;
    }

    return $polku;
}

/**
 * Aseta sessio kirjautuneelle käyttäjälle.
 */
function asetaKirjautuminen(array $user, ?PDO $pdo = null): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_nimi'] = $user['nimi'] ?? $user['username'];
    $_SESSION['paakayttaja_id'] = !empty($user['paakayttaja_id'])
        ? (int) $user['paakayttaja_id']
        : ((in_array($user['role'], ['admin', 'paakayttaja'], true)) ? (int) $user['id'] : null);

    $db = $pdo;
    if (!$db && !empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $db = $GLOBALS['pdo'];
    }
    if ($db && function_exists('tiliLataaKayttajaSessioon')) {
        tiliLataaKayttajaSessioon($db);
    }
}
