<?php

/**
 * Tapahtuman katuosoite ja karttakoordinaatit (Nominatim).
 */

function kisaOsoiteVarmista(PDO $pdo): void
{
    if (!function_exists('tiliVarmistaSarake')) {
        return;
    }
    tiliVarmistaSarake($pdo, 'kilpailut', 'osoite', 'VARCHAR(255) NULL');
    tiliVarmistaSarake($pdo, 'kilpailut', 'lat', 'DECIMAL(9,6) NULL');
    tiliVarmistaSarake($pdo, 'kilpailut', 'lng', 'DECIMAL(9,6) NULL');
}

function kisaOsoiteSiisti(string $osoite): string
{
    $osoite = trim(preg_replace('/\s+/u', ' ', $osoite) ?? '');
    if ($osoite === '') {
        return '';
    }

    return mb_substr($osoite, 0, 255);
}

/**
 * @return array{lat:float,lng:float}|null
 */
function kisaOsoitePisteJsonista(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data) || $data === []) {
        return null;
    }
    $rivi = $data;
    if (isset($data[0]) && is_array($data[0])) {
        $rivi = $data[0];
    }
    if (!is_array($rivi)) {
        return null;
    }
    $lat = isset($rivi['lat']) ? (float) $rivi['lat'] : 0.0;
    $lng = isset($rivi['lon']) ? (float) $rivi['lon'] : (isset($rivi['lng']) ? (float) $rivi['lng'] : 0.0);
    if ($lat === 0.0 && $lng === 0.0) {
        return null;
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

function kisaOsoiteHaeUrl(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'JAHOnline/1.0 (https://jahonline.com)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $out = curl_exec($ch);
        curl_close($ch);

        return is_string($out) ? $out : '';
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 6,
            'header' => "Accept: application/json\r\n",
            'user_agent' => 'JAHOnline/1.0 (https://jahonline.com)',
        ],
    ]);
    $out = @file_get_contents($url, false, $ctx);

    return is_string($out) ? $out : '';
}

/**
 * @return array{lat:float,lng:float}|null
 */
function kisaOsoiteGeokoodaa(string $osoite): ?array
{
    $osoite = kisaOsoiteSiisti($osoite);
    if ($osoite === '') {
        return null;
    }
    $kyselyt = [$osoite];
    if (!preg_match('/suomi|finland|åland/iu', $osoite)) {
        $kyselyt[] = $osoite . ', Finland';
    }
    foreach ($kyselyt as $q) {
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q='
            . rawurlencode($q);
        $json = kisaOsoiteHaeUrl($url);
        $piste = kisaOsoitePisteJsonista($json);
        if ($piste !== null) {
            return $piste;
        }
    }

    return null;
}

function kisaOsoiteOnPiste(?array $k): bool
{
    if (!is_array($k)) {
        return false;
    }
    if (!isset($k['lat'], $k['lng']) || $k['lat'] === '' || $k['lng'] === '' || $k['lat'] === null || $k['lng'] === null) {
        return false;
    }
    $lat = (float) $k['lat'];
    $lng = (float) $k['lng'];

    return !($lat === 0.0 && $lng === 0.0);
}

/**
 * Tallentaa osoitteen ja geokoodaa tarvittaessa.
 *
 * @return bool true jos karttapiste on tallessa
 */
function kisaOsoiteTallenna(PDO $pdo, int $id, string $osoite, ?array $olemassa = null): bool
{
    if ($id < 1) {
        return false;
    }
    kisaOsoiteVarmista($pdo);
    $osoite = kisaOsoiteSiisti($osoite);
    $vanha = kisaOsoiteSiisti((string) ($olemassa['osoite'] ?? ''));
    $lat = null;
    $lng = null;
    if ($osoite === '') {
        $pdo->prepare('UPDATE kilpailut SET osoite = NULL, lat = NULL, lng = NULL WHERE id = ?')->execute([$id]);

        return false;
    }
    if ($osoite === $vanha && kisaOsoiteOnPiste($olemassa)) {
        $lat = (float) $olemassa['lat'];
        $lng = (float) $olemassa['lng'];
    } else {
        $piste = kisaOsoiteGeokoodaa($osoite);
        if ($piste !== null) {
            $lat = $piste['lat'];
            $lng = $piste['lng'];
        }
    }
    $pdo->prepare('UPDATE kilpailut SET osoite = ?, lat = ?, lng = ? WHERE id = ?')
        ->execute([$osoite, $lat, $lng, $id]);

    return $lat !== null && $lng !== null;
}

function kisaOsoiteKenttaHtml(string $arvo, string $vihje = ''): void
{
    if ($vihje === '') {
        $vihje = 'Katuosoite karttaa varten, esim. Urheilukatu 5, Helsinki.';
    }
    echo '<label>Osoite <input name="osoite" maxlength="255" placeholder="Katuosoite, postitoimipaikka" value="'
        . htmlspecialchars($arvo)
        . '"></label>';
    echo '<p class="muted form-hint">' . htmlspecialchars($vihje) . '</p>';
}
