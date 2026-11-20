<?php

/**
 * Pirilän kellon ja palvelimen aikaero lähtökelloa varten.
 * ero_ms > 0: Pirilän kello on palvelinta edellä.
 */

function pirilaKelloVarmista(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pirila_kello (
            kilpailu_id INT NOT NULL PRIMARY KEY,
            ero_ms INT NOT NULL,
            lahde VARCHAR(20) NOT NULL,
            palvelin_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ok = true;
}

function pirilaKelloParsi(string $teksti): ?DateTime
{
    $teksti = trim($teksti);
    if ($teksti === '') {
        return null;
    }
    $tz = new DateTimeZone('Europe/Helsinki');
    foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'd.m.Y H:i:s', 'H:i:s', 'H:i'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $teksti, $tz);
        if ($dt instanceof DateTime) {
            $virheet = DateTime::getLastErrors();
            if (is_array($virheet) && (($virheet['warning_count'] ?? 0) > 0 || ($virheet['error_count'] ?? 0) > 0)) {
                continue;
            }
            if ($fmt === 'H:i:s' || $fmt === 'H:i') {
                $nyt = new DateTime('now', $tz);
                $dt->setDate((int) $nyt->format('Y'), (int) $nyt->format('m'), (int) $nyt->format('d'));
                if ($fmt === 'H:i') {
                    $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);
                }
            }
            return $dt;
        }
    }

    return null;
}

function pirilaKelloTila(PDO $pdo, int $kilpailuId): array
{
    pirilaKelloVarmista($pdo);
    $nytMs = (int) round(microtime(true) * 1000);
    $st = $pdo->prepare('SELECT ero_ms, lahde, palvelin_at FROM pirila_kello WHERE kilpailu_id = ?');
    $st->execute([$kilpailuId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return [
            'ok' => 1,
            'nyt_ms' => $nytMs,
            'ero_ms' => 0,
            'lahde' => 'palvelin',
            'ika_s' => null,
        ];
    }
    $ika = null;
    $ts = strtotime((string) $r['palvelin_at']);
    if ($ts !== false) {
        $ika = max(0, time() - $ts);
    }

    return [
        'ok' => 1,
        'nyt_ms' => $nytMs,
        'ero_ms' => (int) $r['ero_ms'],
        'lahde' => (string) $r['lahde'],
        'ika_s' => $ika,
    ];
}

function pirilaKelloTallenna(PDO $pdo, int $kilpailuId, string $kelloTeksti, string $lahde = 'pirila'): ?array
{
    if ($kilpailuId < 1) {
        return null;
    }
    $dt = pirilaKelloParsi($kelloTeksti);
    if (!$dt) {
        return null;
    }
    pirilaKelloVarmista($pdo);
    $pirilaMs = ((int) $dt->format('U')) * 1000;
    $nytMs = (int) round(microtime(true) * 1000);
    $ero = $pirilaMs - $nytMs;
    if ($ero > 86400000 || $ero < -86400000) {
        return null;
    }
    $lahde = $lahde === 'kasin' ? 'kasin' : 'pirila';
    $pdo->prepare('
        INSERT INTO pirila_kello (kilpailu_id, ero_ms, lahde, palvelin_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ero_ms = VALUES(ero_ms), lahde = VALUES(lahde), palvelin_at = VALUES(palvelin_at)
    ')->execute([$kilpailuId, $ero, $lahde, date('Y-m-d H:i:s')]);

    return pirilaKelloTila($pdo, $kilpailuId);
}

function pirilaKelloSiirra(PDO $pdo, int $kilpailuId, int $deltaMs): array
{
    $tila = pirilaKelloTila($pdo, $kilpailuId);
    $ero = (int) $tila['ero_ms'] + $deltaMs;
    if ($ero > 86400000) {
        $ero = 86400000;
    }
    if ($ero < -86400000) {
        $ero = -86400000;
    }
    pirilaKelloVarmista($pdo);
    $pdo->prepare('
        INSERT INTO pirila_kello (kilpailu_id, ero_ms, lahde, palvelin_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ero_ms = VALUES(ero_ms), lahde = VALUES(lahde), palvelin_at = VALUES(palvelin_at)
    ')->execute([$kilpailuId, $ero, 'kasin', date('Y-m-d H:i:s')]);

    return pirilaKelloTila($pdo, $kilpailuId);
}

function pirilaKelloNollaa(PDO $pdo, int $kilpailuId): array
{
    pirilaKelloVarmista($pdo);
    $pdo->prepare('DELETE FROM pirila_kello WHERE kilpailu_id = ?')->execute([$kilpailuId]);

    return pirilaKelloTila($pdo, $kilpailuId);
}
