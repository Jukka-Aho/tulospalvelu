<?php

require_once __DIR__ . '/auth.php';
if (is_readable(__DIR__ . '/pirila_mode.php')) {
    require_once __DIR__ . '/pirila_mode.php';
}
if (is_readable(__DIR__ . '/kisa_luonne.php')) {
    require_once __DIR__ . '/kisa_luonne.php';
}
if (is_readable(__DIR__ . '/iltarasti.php')) {
    require_once __DIR__ . '/iltarasti.php';
}
if (is_readable(__DIR__ . '/kilpailu_aikavali.php')) {
    require_once __DIR__ . '/kilpailu_aikavali.php';
}

/**
 * Voiko nykyinen käyttäjä hallita kilpailua (muokkaus / valinta adminissa)?
 * Sallittu: järjestelmän admin, kilpailun pääkäyttäjä-omistaja,
 * tai seuran omistaja/admin kun kilpailulla on seura_id.
 */
function voiHallitaKilpailua(array $kilpailu): bool
{
    if (onAdmin()) {
        return true;
    }

    $owner = (int) ($kilpailu['paakayttaja_id'] ?? 0);
    $oma = (int) (paakayttajaId() ?? 0);
    if (onPaakayttaja() && $owner > 0 && $oma > 0 && $owner === $oma) {
        return true;
    }

    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    if ($seuraId > 0 && function_exists('onSeuraAdminSeuralle') && onSeuraAdminSeuralle($seuraId)) {
        return true;
    }
    if (function_exists('iltarastiVoiHallita') && iltarastiVoiHallita($kilpailu)) {
        return true;
    }
    if (
        $seuraId > 0
        && function_exists('tiliNaytaKisaValikko')
        && tiliNaytaKisaValikko('kaikki')
        && function_exists('haeSeuraKisaValikkoSeuraIdt')
        && in_array($seuraId, haeSeuraKisaValikkoSeuraIdt(), true)
    ) {
        return true;
    }

    return false;
}

/**
 * Voiko avata seuran kisan hallintaan (myös rajatuilla kilpailuvalikoilla)?
 */
function voiKayttaaSeuranKilpailua(array $kilpailu): bool
{
    if (voiHallitaKilpailua($kilpailu)) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    if ($seuraId < 1) {
        return false;
    }
    if (!function_exists('tiliKayttajaKisaValikot') || tiliKayttajaKisaValikot() === []) {
        return false;
    }
    if (function_exists('haeSeuraKisaValikkoSeuraIdt')) {
        return in_array($seuraId, haeSeuraKisaValikkoSeuraIdt(), true);
    }

    return (int) ($_SESSION['seura_id'] ?? 0) === $seuraId;
}

/**
 * Voiko käyttää ajanottoa tähän kilpailuun?
 */
function voiAjanottaaKilpailua(array $kilpailu): bool
{
    $valikkoAjanotto = function_exists('tiliNaytaKisaValikko') && tiliNaytaKisaValikko('ajanotto');
    if (!onAjanottaja() && !$valikkoAjanotto) {
        return false;
    }
    if (function_exists('pirilaSynkkaPaalla') ? pirilaSynkkaPaalla($kilpailu) : (($kilpailu['kisa_tyyppi'] ?? '') === 'pirila' || !empty($kilpailu['pirila_synkka']))) {
        return false;
    }
    if (onAdmin()) {
        return true;
    }
    $owner = (int) ($kilpailu['paakayttaja_id'] ?? 0);
    $oma = (int) (paakayttajaId() ?? 0);
    if ($owner > 0 && $oma > 0 && $owner === $oma) {
        return true;
    }
    // Seuran admin / omistaja saa ajanottaa seuran kisaa
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    if ($seuraId > 0 && function_exists('onSeuraAdminSeuralle') && onSeuraAdminSeuralle($seuraId)) {
        return true;
    }
    if ($seuraId > 0 && $valikkoAjanotto && function_exists('haeSeuraKisaValikkoSeuraIdt')
        && in_array($seuraId, haeSeuraKisaValikkoSeuraIdt(), true)
    ) {
        return true;
    }

    return false;
}

function kisaOmistusOikeus(array $kilpailu): bool
{
    if (onAdmin()) {
        return true;
    }
    $owner = (int) ($kilpailu['paakayttaja_id'] ?? 0);
    $oma = (int) (paakayttajaId() ?? 0);
    if ($owner > 0 && $oma > 0 && $owner === $oma) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);

    return $seuraId > 0 && function_exists('onSeuraAdminSeuralle') && onSeuraAdminSeuralle($seuraId);
}

/**
 * Voiko käyttää lähtöluentaa tähän kilpailuun?
 */
function voiLahtoluennataKilpailua(array $kilpailu): bool
{
    $valikkoLahto = function_exists('tiliNaytaKisaValikko') && tiliNaytaKisaValikko('lahtopaikka');
    if (!onLahtopaikka() && !$valikkoLahto) {
        return false;
    }
    $pirila = function_exists('pirilaSynkkaPaalla')
        ? pirilaSynkkaPaalla($kilpailu)
        : (($kilpailu['kisa_tyyppi'] ?? '') === 'pirila' || !empty($kilpailu['pirila_synkka']));
    $emit = function_exists('kisaNaytaValikko') && kisaNaytaValikko($kilpailu, 'emit');
    if (!$pirila && !$emit) {
        return false;
    }
    if (kisaOmistusOikeus($kilpailu)) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);

    return $seuraId > 0 && $valikkoLahto
        && function_exists('haeSeuraKisaValikkoSeuraIdt')
        && in_array($seuraId, haeSeuraKisaValikkoSeuraIdt(), true);
}

/**
 * Voiko käyttää lähtötoimintoja (lähtöluenta, nimenhuuto, lähtökello)?
 */
function voiLahtotoimiaKilpailua(array $kilpailu): bool
{
    if (function_exists('onLahtotoiminnat') && onLahtotoiminnat() && kisaOmistusOikeus($kilpailu)) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    if ($seuraId < 1 || !function_exists('tiliNaytaKisaValikko') || !tiliNaytaKisaValikko('lahtopaikka')) {
        return false;
    }
    if (function_exists('onSeuraAdminSeuralle') && onSeuraAdminSeuralle($seuraId)) {
        return true;
    }

    return function_exists('haeSeuraKisaValikkoSeuraIdt')
        && in_array($seuraId, haeSeuraKisaValikkoSeuraIdt(), true);
}

/**
 * Voiko avata ampumapaikan tähän kilpailuun?
 */
function voiAmpumapaikkaKilpailua(array $kilpailu): bool
{
    if (!function_exists('onAmpumapaikat') || !onAmpumapaikat()) {
        return false;
    }
    if (function_exists('kisaOnAmpumapaikat') && !kisaOnAmpumapaikat($kilpailu)) {
        return false;
    }

    return kisaOmistusOikeus($kilpailu);
}

/**
 * Voiko avata TV-näytön tähän kilpailuun?
 */
function voiKatsellaTvKilpailua(array $kilpailu): bool
{
    if (!onTv()) {
        return false;
    }
    if (onAdmin()) {
        return true;
    }
    $owner = (int) ($kilpailu['paakayttaja_id'] ?? 0);
    $oma = (int) (paakayttajaId() ?? 0);
    if ($owner > 0 && $oma > 0 && $owner === $oma) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    if ($seuraId > 0 && function_exists('onSeuraAdminSeuralle') && onSeuraAdminSeuralle($seuraId)) {
        return true;
    }

    return false;
}

function vaadiKilpailunHallinta(array $kilpailu): void
{
    if (!voiHallitaKilpailua($kilpailu)) {
        http_response_code(403);
        die('Ei oikeutta tähän kilpailuun');
    }
}

function vaadiSeuranKisaKaytto(array $kilpailu): void
{
    if (!voiKayttaaSeuranKilpailua($kilpailu)) {
        http_response_code(403);
        die('Ei oikeutta tähän kilpailuun');
    }
}

function vaadiKisaValikko(string $osio): void
{
    if (function_exists('tiliNaytaKisaValikko') && tiliNaytaKisaValikko($osio)) {
        return;
    }
    if (!function_exists('tiliNaytaKisaValikko')) {
        return;
    }
    http_response_code(403);
    die('Ei käyttöoikeutta tähän valikkoon');
}

function haeKilpailuKayttoon(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM kilpailut WHERE id = ?');
    $stmt->execute([$id]);
    $k = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$k || !voiKayttaaSeuranKilpailua($k)) {
        return null;
    }

    return $k;
}

function vaadiTvKilpailu(array $kilpailu): void
{
    if (!voiKatsellaTvKilpailua($kilpailu)) {
        http_response_code(403);
        die('Ei oikeutta TV-näyttöön tälle kilpailulle');
    }
}

/**
 * SQL-ehto + parametrit kilpailulistan rajaamiseen.
 * @return array{0:string,1:array}
 */
function kilpailuOmistusEhto(string $alias = 'k'): array
{
    if (onAdmin()) {
        return ['1=1', []];
    }

    $parts = [];
    $params = [];

    $pid = paakayttajaId();
    if ($pid) {
        $parts[] = $alias . '.paakayttaja_id = ?';
        $params[] = (int) $pid;
    }

    if (function_exists('haeSeuraAdminSeuraIdt')) {
        $seuraIds = haeSeuraAdminSeuraIdt();
        if ($seuraIds !== []) {
            $ph = implode(',', array_fill(0, count($seuraIds), '?'));
            $parts[] = $alias . '.seura_id IN (' . $ph . ')';
            foreach ($seuraIds as $sid) {
                $params[] = (int) $sid;
            }
        }
    }
    if (function_exists('haeSeuraKisaValikkoSeuraIdt')) {
        $valikkoIds = haeSeuraKisaValikkoSeuraIdt();
        if ($valikkoIds !== []) {
            $ph = implode(',', array_fill(0, count($valikkoIds), '?'));
            $parts[] = $alias . '.seura_id IN (' . $ph . ')';
            foreach ($valikkoIds as $sid) {
                $params[] = (int) $sid;
            }
        }
    }

    if (function_exists('onIltarasti') && onIltarasti() && !onPaakayttaja()) {
        $sid = function_exists('nykyinenSeuraId') ? nykyinenSeuraId() : (int) ($_SESSION['seura_id'] ?? 0);
        if ($sid > 0) {
            $parts[] = '(' . $alias . '.seura_id = ? AND ' . $alias . '.luonne = \'iltarasti\')';
            $params[] = $sid;
        }
    }

    if ($parts === []) {
        return ['0=1', []];
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * Hae käyttäjän näkemät kilpailut.
 *
 * @param array{alkaen?:string,asti?:string,kaikki?:bool}|null $aikavali
 */
function haeKayttajanKilpailut(PDO $pdo, ?string $tilaJarjestys = null, ?array $aikavali = null): array
{
    [$ehto, $params] = kilpailuOmistusEhto('k');
    if (function_exists('kilpailuAikavaliSql')) {
        [$ae, $ap] = kilpailuAikavaliSql('k', $aikavali);
        if ($ae !== '') {
            $ehto = '(' . $ehto . ') AND ' . $ae;
            $params = array_merge($params, $ap);
        }
    }
    $order = $tilaJarjestys ?? 'k.paiva DESC, k.id DESC';
    try {
        $stmt = $pdo->prepare("
            SELECT k.*, s.nimi AS seura_nimi
            FROM kilpailut k
            LEFT JOIN seurat s ON s.id = k.seura_id
            WHERE $ehto
            ORDER BY $order
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("SELECT k.* FROM kilpailut k WHERE $ehto ORDER BY $order");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/** Seuran nimi kilpailulle (JOIN tai haku seurat-taulusta). */
function kilpailuSeuraNimi(PDO $pdo, array $kilpailu): string
{
    $nimi = trim((string) ($kilpailu['seura_nimi'] ?? ''));
    if ($nimi !== '') {
        return $nimi;
    }
    $sid = (int) ($kilpailu['seura_id'] ?? 0);
    if ($sid < 1) {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT nimi FROM seurat WHERE id = ?');
        $st->execute([$sid]);

        return trim((string) $st->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Hae kilpailu ja tarkista hallintaoikeus.
 */
function haeKilpailuHallintaan(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM kilpailut WHERE id = ?');
    $stmt->execute([$id]);
    $k = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$k || !voiHallitaKilpailua($k)) {
        return null;
    }

    return $k;
}

/**
 * Julkinen sivu: salli kaikille jos kilpailu on julkinen eikä luonnos,
 * muuten vain kirjautunut hallitsija (admin / omistava pääkäyttäjä / seuran admin).
 */
function vaadiJulkinenNakyvyys(array $kilpailu, string $redirect = 'index.php'): void
{
    $tila = (string) ($kilpailu['tila'] ?? '');
    if (kilpailuJulkinen($kilpailu) && !in_array($tila, ['luonnos', 'arkisto'], true)) {
        return;
    }
    if (!isset($_SESSION['user']) || !voiHallitaKilpailua($kilpailu)) {
        header('Location: ' . $redirect);
        exit;
    }
}

/** Onko kilpailu Pirilä-viesti (joukkue / osuudet)? */
function onkoViestiKilpailu(array $kilpailu): bool
{
    return ($kilpailu['tyyppi'] ?? '') === 'viesti';
}

/**
 * Onko kilpailu julkisesti näkyvissä?
 */
function kilpailuJulkinen(array $kilpailu): bool
{
    if (!empty($kilpailu['testikisa'])) {
        return false;
    }
    if (array_key_exists('julkinen', $kilpailu)) {
        return (int) $kilpailu['julkinen'] === 1;
    }

    return ($kilpailu['tila'] ?? '') !== 'luonnos';
}
