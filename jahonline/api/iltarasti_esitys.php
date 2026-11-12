<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/iltarasti.php';
require_once __DIR__ . '/../includes/iltarasti_esitys.php';

vaadiRooli(['admin', 'paakayttaja', 'ajanottaja', 'lahtopaikka', 'iltarasti']);

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$kilpailuId = (int) ($data['kilpailu_id'] ?? 0);
if ($kilpailuId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'taso' => 'virhe', 'message' => 'Kilpailu puuttuu'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailuId);
if (!$kilpailu || !kisaOnIltarasti($kilpailu) || !voiHallitaKilpailua($kilpailu)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'taso' => 'virhe', 'message' => 'Ei oikeutta'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!tarkistaCsrf((string) ($data['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'taso' => 'virhe', 'message' => 'Virheellinen pyyntö'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $vastaus = iltarastiEsitysKasittele($pdo, $kilpailu, $data);
    echo json_encode($vastaus, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'taso' => 'virhe', 'message' => 'Tallennus epäonnistui.'], JSON_UNESCAPED_UNICODE);
}
