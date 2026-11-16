<?php

function viestiSarjatVarmista(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $ok = true;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `sarjat` LIKE 'osuuksia'");
        if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE `sarjat` ADD COLUMN `osuuksia` INT NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `kilpailut` LIKE 'osuuksia'");
        if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE `kilpailut` ADD COLUMN `osuuksia` INT NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
    }
}

function viestiSarjaOsuuksiaArvo($raw): int
{
    $n = (int) $raw;
    if ($n < 1) {
        return 1;
    }
    if ($n > 30) {
        return 30;
    }

    return $n;
}

/**
 * @return list<array<string,mixed>>
 */
function viestiSarjatHae(PDO $pdo, int $kilpailuId): array
{
    if ($kilpailuId < 1) {
        return [];
    }
    viestiSarjatVarmista($pdo);
    try {
        $st = $pdo->prepare('
            SELECT id, nimi, osuuksia, jarjestys
            FROM sarjat
            WHERE kilpailu_id = ?
            ORDER BY jarjestys, nimi, id
        ');
        $st->execute([$kilpailuId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function viestiSarjatPaivitaKisaOsuudet(PDO $pdo, int $kilpailuId): void
{
    if ($kilpailuId < 1) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT COALESCE(MAX(osuuksia), 0) FROM sarjat WHERE kilpailu_id = ?');
        $st->execute([$kilpailuId]);
        $max = viestiSarjaOsuuksiaArvo($st->fetchColumn());
        $pdo->prepare('UPDATE kilpailut SET osuuksia = ? WHERE id = ?')->execute([$max, $kilpailuId]);
    } catch (Throwable $e) {
    }
}

/**
 * @param list<int|string> $idt
 * @param list<string> $nimet
 * @param list<int|string> $osuudet
 * @param list<int|string> $poistot
 */
function viestiSarjatTallenna(PDO $pdo, int $kilpailuId, array $idt, array $nimet, array $osuudet, array $poistot = []): void
{
    if ($kilpailuId < 1) {
        throw new RuntimeException('Kilpailu puuttuu.');
    }
    viestiSarjatVarmista($pdo);
    $n = max(count($idt), count($nimet), count($osuudet), count($poistot));
    $jarjestys = 0;
    for ($i = 0; $i < $n; $i++) {
        $id = (int) ($idt[$i] ?? 0);
        $nimi = trim((string) ($nimet[$i] ?? ''));
        $osuuksia = viestiSarjaOsuuksiaArvo($osuudet[$i] ?? 1);
        $poista = !empty($poistot[$i]) && (string) $poistot[$i] !== '0';
        if ($poista) {
            if ($id > 0) {
                viestiSarjaPoista($pdo, $kilpailuId, $id);
            }
            continue;
        }
        if ($nimi === '') {
            continue;
        }
        $jarjestys++;
        $nimi = mb_substr($nimi, 0, 80);
        if ($id > 0) {
            $st = $pdo->prepare('
                UPDATE sarjat SET nimi = ?, osuuksia = ?, jarjestys = ?
                WHERE id = ? AND kilpailu_id = ?
            ');
            $st->execute([$nimi, $osuuksia, $jarjestys, $id, $kilpailuId]);
        } else {
            try {
                $pdo->prepare('
                    INSERT INTO sarjat (kilpailu_id, nimi, osuuksia, jarjestys, nayta_ajat)
                    VALUES (?, ?, ?, ?, 1)
                ')->execute([$kilpailuId, $nimi, $osuuksia, $jarjestys]);
            } catch (Throwable $e) {
                $pdo->prepare('INSERT INTO sarjat (kilpailu_id, nimi, jarjestys) VALUES (?, ?, ?)')
                    ->execute([$kilpailuId, $nimi, $jarjestys]);
                $uusi = (int) $pdo->lastInsertId();
                if ($uusi > 0) {
                    $pdo->prepare('UPDATE sarjat SET osuuksia = ? WHERE id = ?')->execute([$osuuksia, $uusi]);
                }
            }
        }
    }
    viestiSarjatPaivitaKisaOsuudet($pdo, $kilpailuId);
}

function viestiSarjaPoista(PDO $pdo, int $kilpailuId, int $sarjaId): void
{
    if ($kilpailuId < 1 || $sarjaId < 1) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM joukkueet WHERE kilpailu_id = ? AND sarja_id = ?');
        $st->execute([$kilpailuId, $sarjaId]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException('Sarjaa ei voi poistaa, koska siinä on joukkueita.');
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM kilpailijat WHERE kilpailu_id = ? AND sarja_id = ?');
        $st->execute([$kilpailuId, $sarjaId]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException('Sarjaa ei voi poistaa, koska siinä on osanottajia.');
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
    }
    $pdo->prepare('DELETE FROM sarjat WHERE id = ? AND kilpailu_id = ?')->execute([$sarjaId, $kilpailuId]);
}

function viestiOsuusLkmSarjasta(PDO $pdo, int $kilpailuId, int $sarjaId = 0, int $havaittuMax = 0): int
{
    viestiSarjatVarmista($pdo);
    $n = 0;
    if ($sarjaId > 0) {
        try {
            $st = $pdo->prepare('SELECT COALESCE(osuuksia, 0) FROM sarjat WHERE id = ? AND kilpailu_id = ?');
            $st->execute([$sarjaId, $kilpailuId]);
            $n = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;
        }
    }
    if ($n < 1) {
        try {
            $st = $pdo->prepare('SELECT COALESCE(MAX(osuuksia), 0) FROM sarjat WHERE kilpailu_id = ?');
            $st->execute([$kilpailuId]);
            $n = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;
        }
    }
    if ($n < 1) {
        try {
            $st = $pdo->prepare('SELECT COALESCE(osuuksia, 0) FROM kilpailut WHERE id = ?');
            $st->execute([$kilpailuId]);
            $n = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;
        }
    }
    $n = max($n, $havaittuMax, 1);

    return min(30, $n);
}
