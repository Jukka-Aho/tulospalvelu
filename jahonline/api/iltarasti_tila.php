<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../includes/iltarasti_esitys.php';

$kilpailuId = (int) ($_GET['kilpailu_id'] ?? 0);
if ($kilpailuId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => 0, 'message' => 'Kilpailu puuttuu'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailuId);
if (!$kilpailu || !kisaOnIltarasti($kilpailu)) {
    http_response_code(404);
    echo json_encode(['ok' => 0, 'message' => 'Tapahtumaa ei löydy'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tila = (string) ($kilpailu['tila'] ?? '');
$julkinen = function_exists('kilpailuJulkinen') && kilpailuJulkinen($kilpailu)
    && !in_array($tila, ['luonnos', 'arkisto'], true);
$hallinta = !empty($_SESSION['user']) && function_exists('voiHallitaKilpailua') && voiHallitaKilpailua($kilpailu);
if (!$julkinen && !$hallinta) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'message' => 'Ei oikeutta'], JSON_UNESCAPED_UNICODE);
    exit;
}

$luvut = iltarastiTilanneLuvut($pdo, $kilpailu);

echo json_encode([
    'ok' => 1,
    'maastossa' => (int) $luvut['maastossa'],
    'esitykset' => $hallinta ? (int) $luvut['esitykset'] : null,
], JSON_UNESCAPED_UNICODE);
