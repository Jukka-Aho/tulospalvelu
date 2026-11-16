<?php

/**
 * Julkinen IOF XML 3.0 ResultList -lataus.
 * Käyttö: tulokset_iof.php?kilpailu_id=N
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/iof_xml.php';

$kilpailu_id = isset($_GET['kilpailu_id']) ? (int) $_GET['kilpailu_id'] : 0;
if ($kilpailu_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "kilpailu_id puuttuu\n";
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailu_id);
if (!$kilpailu) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kilpailua ei löydy\n";
    exit;
}

vaadiJulkinenNakyvyys($kilpailu);

// Julkinen lataus vain valmiista kisasta (hallinta voi viedä aina)
if ((string) ($kilpailu['tila'] ?? '') !== 'valmis') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "IOF XML -lataus on saatavilla vasta kun kilpailu on valmis.\n";
    exit;
}

iofXmlLahetaLataus($pdo, $kilpailu);
