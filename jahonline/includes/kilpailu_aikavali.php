<?php

/**
 * Adminin kilpailulistan suodatus: aikaväli (Helsinki, oletus ±14 pv) ja seura.
 */

function kilpailuAikavaliTanaan(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
}

/** @return array{alkaen:string,asti:string} */
function kilpailuAikavaliOletus(): array
{
    $tanaan = new DateTimeImmutable(kilpailuAikavaliTanaan(), new DateTimeZone('Europe/Helsinki'));

    return [
        'alkaen' => $tanaan->modify('-14 days')->format('Y-m-d'),
        'asti' => $tanaan->modify('+14 days')->format('Y-m-d'),
    ];
}

function kilpailuAikavaliPaivaOk(string $arvo): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arvo)) {
        return false;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $arvo);

    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $arvo;
}

function kilpailuSuodatinSeuraId(array $get): int
{
    $raw = trim((string) ($get['seura_id'] ?? ''));
    if ($raw === '-1') {
        return -1;
    }
    if ($raw !== '' && ctype_digit($raw)) {
        return (int) $raw;
    }

    return 0;
}

/**
 * @param array<string,mixed> $get
 * @return array{alkaen:string,asti:string,kaikki:bool,seura_id:int}
 */
function kilpailuParsiaikavali(array $get): array
{
    $oletus = kilpailuAikavaliOletus();
    $kaikki = !empty($get['kaikki']) && (string) $get['kaikki'] !== '0';
    $alkaen = trim((string) ($get['alkaen'] ?? ''));
    $asti = trim((string) ($get['asti'] ?? ''));
    $onAlkaen = kilpailuAikavaliPaivaOk($alkaen);
    $onAsti = kilpailuAikavaliPaivaOk($asti);
    if ($onAlkaen || $onAsti) {
        $kaikki = false;
        $alkaen = $onAlkaen ? $alkaen : $oletus['alkaen'];
        $asti = $onAsti ? $asti : $oletus['asti'];
    } else {
        $alkaen = $oletus['alkaen'];
        $asti = $oletus['asti'];
    }
    if ($alkaen > $asti) {
        [$alkaen, $asti] = [$asti, $alkaen];
    }

    return [
        'alkaen' => $alkaen,
        'asti' => $asti,
        'kaikki' => $kaikki,
        'seura_id' => kilpailuSuodatinSeuraId($get),
    ];
}

/**
 * @param array{alkaen?:string,asti?:string,kaikki?:bool,seura_id?:int}|null $vali
 * @return array{0:string,1:list<int|string>}
 */
function kilpailuAikavaliSql(string $alias, ?array $vali): array
{
    if (!is_array($vali)) {
        return ['', []];
    }
    $parts = [];
    $params = [];
    if (empty($vali['kaikki'])) {
        $alkaen = (string) ($vali['alkaen'] ?? '');
        $asti = (string) ($vali['asti'] ?? '');
        if (kilpailuAikavaliPaivaOk($alkaen) && kilpailuAikavaliPaivaOk($asti)) {
            $col = $alias . '.paiva';
            $parts[] = '(' . $col . ' IS NULL OR DATE(' . $col . ') BETWEEN ? AND ?)';
            $params[] = $alkaen;
            $params[] = $asti;
        }
    }
    $sid = (int) ($vali['seura_id'] ?? 0);
    if ($sid > 0) {
        $parts[] = $alias . '.seura_id = ?';
        $params[] = $sid;
    } elseif ($sid === -1) {
        $parts[] = $alias . '.seura_id IS NULL';
    }
    if ($parts === []) {
        return ['', []];
    }

    return [implode(' AND ', $parts), $params];
}

/**
 * Seurat kisasuodattimen valikkoon.
 *
 * @return list<array{id:int,nimi:string}>
 */
function kilpailuSuodatinSeurat(PDO $pdo): array
{
    try {
        $rivit = $pdo->query("
            SELECT id, nimi FROM seurat
            WHERE tila = 'aktiivinen'
               OR id IN (SELECT DISTINCT seura_id FROM kilpailut WHERE seura_id IS NOT NULL)
            ORDER BY nimi
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $rivit = $pdo->query('SELECT id, nimi FROM seurat ORDER BY nimi')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
    $out = [];
    foreach ($rivit as $r) {
        $id = (int) ($r['id'] ?? 0);
        $nimi = trim((string) ($r['nimi'] ?? ''));
        if ($id > 0 && $nimi !== '') {
            $out[] = ['id' => $id, 'nimi' => $nimi];
        }
    }

    return $out;
}

/**
 * @param array{alkaen:string,asti:string,kaikki:bool,seura_id?:int} $vali
 */
function kilpailuAikavaliKaikkiHref(string $action, array $vali): string
{
    $q = ['kaikki' => '1'];
    $sid = (int) ($vali['seura_id'] ?? 0);
    if ($sid !== 0) {
        $q['seura_id'] = (string) $sid;
    }
    $erotin = str_contains($action, '?') ? '&' : '?';

    return $action . $erotin . http_build_query($q);
}

/**
 * @param array{alkaen:string,asti:string,kaikki:bool,seura_id?:int} $vali
 * @param list<array{id:int,nimi:string}>|PDO|null $seuratTaiPdo
 */
function kilpailuAikavaliLomake(string $action, array $vali, $seuratTaiPdo = null): void
{
    $kaikki = !empty($vali['kaikki']);
    $seuraId = (int) ($vali['seura_id'] ?? 0);
    $seurat = [];
    if ($seuratTaiPdo instanceof PDO) {
        $seurat = kilpailuSuodatinSeurat($seuratTaiPdo);
    } elseif (is_array($seuratTaiPdo)) {
        $seurat = $seuratTaiPdo;
    }
    echo '<form method="get" class="kisa-aikavali" action="' . htmlspecialchars($action) . '">';
    echo '<p class="kisa-aikavali-otsikko">Näytä kilpailut</p>';
    echo '<div class="kisa-aikavali-kentat">';
    echo '<label>Alkaen <input type="date" name="alkaen" value="'
        . htmlspecialchars((string) $vali['alkaen']) . '"></label>';
    echo '<label>Asti <input type="date" name="asti" value="'
        . htmlspecialchars((string) $vali['asti']) . '"></label>';
    echo '<label>Seura <select name="seura_id" onchange="this.form.submit()">';
    echo '<option value="0"' . ($seuraId === 0 ? ' selected' : '') . '>Kaikki seurat</option>';
    echo '<option value="-1"' . ($seuraId === -1 ? ' selected' : '') . '>Ilman seuraa</option>';
    foreach ($seurat as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $sel = $seuraId === $sid ? ' selected' : '';
        echo '<option value="' . $sid . '"' . $sel . '>'
            . htmlspecialchars((string) ($s['nimi'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<button type="submit" class="btn-small">Näytä</button>';
    echo '<a class="btn-small-link" href="'
        . htmlspecialchars(kilpailuAikavaliKaikkiHref($action, $vali)) . '">Kaikki päivät</a>';
    echo '</div>';
    if ($kaikki && $seuraId === 0) {
        echo '<p class="muted">Näytetään kaikki kilpailut ilman päivä- tai seurarajausta.</p>';
    } elseif ($kaikki) {
        echo '<p class="muted">Näytetään seuran kaikki kilpailut ilman päivärajausta.</p>';
    } else {
        echo '<p class="muted">Oletus: 2 viikkoa ennen ja jälkeen tämän päivän. Voit rajata myös seuran mukaan.</p>';
    }
    echo '</form>';
    echo '<style>'
        . '.kisa-aikavali{margin:0 0 1rem;padding:.85rem 1rem;border:1px solid var(--border,#d8dee6);'
        . 'border-radius:10px;background:var(--card,#fff);max-width:52rem}'
        . '.kisa-aikavali-otsikko{margin:0 0 .55rem;font-weight:600}'
        . '.kisa-aikavali-kentat{display:flex;flex-wrap:wrap;gap:.65rem 1rem;align-items:flex-end}'
        . '.kisa-aikavali-kentat label{display:flex;flex-direction:column;gap:.2rem;font-size:.85rem}'
        . '.kisa-aikavali-kentat input[type=date],'
        . '.kisa-aikavali-kentat select{padding:.35rem .5rem;min-height:2.1rem}'
        . '.kisa-aikavali-kentat select{min-width:12rem;max-width:20rem}'
        . '.kisa-aikavali .muted{margin:.55rem 0 0}'
        . '.active-kilpailu-seura{margin-top:4px;font-size:13px;font-weight:500}'
        . '.active-kilpailu-lisatieto{margin-top:2px;font-size:12px}'
        . '</style>';
}
