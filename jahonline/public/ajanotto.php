<?php

/**
 * Vanha MAALI-lomake ohjaa mobiiliajanottoon.
 * Kirjanmerkit ja vanhat linkit eivät saa avata CSRF-tonta ansaa.
 */
$kilpailu_id = (int) ($_GET['kilpailu_id'] ?? $_POST['kilpailu_id'] ?? 0);
$tila = (string) ($_GET['tila'] ?? '');
$q = [];
if ($kilpailu_id > 0) {
    $q['kilpailu_id'] = $kilpailu_id;
}
if (in_array($tila, ['ruudukko', 'nappi', 'numerot'], true)) {
    $q['tila'] = $tila;
}
$qs = $q === [] ? '' : ('?' . http_build_query($q));
header('Location: mobiili_ajanotto.php' . $qs, true, 301);
exit;
