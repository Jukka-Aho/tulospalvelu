<?php

/**
 * Adminin kilpailulistan aikaväli (Helsinki). Oletus ±14 päivää.
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

/**
 * @param array<string,mixed> $get
 * @return array{alkaen:string,asti:string,kaikki:bool}
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
    ];
}

/**
 * @param array{alkaen?:string,asti?:string,kaikki?:bool}|null $vali
 * @return array{0:string,1:list<string>}
 */
function kilpailuAikavaliSql(string $alias, ?array $vali): array
{
    if (!is_array($vali) || !empty($vali['kaikki'])) {
        return ['', []];
    }
    $alkaen = (string) ($vali['alkaen'] ?? '');
    $asti = (string) ($vali['asti'] ?? '');
    if (!kilpailuAikavaliPaivaOk($alkaen) || !kilpailuAikavaliPaivaOk($asti)) {
        return ['', []];
    }
    $col = $alias . '.paiva';

    return [
        '(' . $col . ' IS NULL OR DATE(' . $col . ') BETWEEN ? AND ?)',
        [$alkaen, $asti],
    ];
}

/**
 * @param array{alkaen:string,asti:string,kaikki:bool} $vali
 */
function kilpailuAikavaliLomake(string $action, array $vali): void
{
    $kaikki = !empty($vali['kaikki']);
    $erotin = str_contains($action, '?') ? '&' : '?';
    echo '<form method="get" class="kisa-aikavali" action="' . htmlspecialchars($action) . '">';
    echo '<p class="kisa-aikavali-otsikko">Näytä kilpailut aikaväliltä</p>';
    echo '<div class="kisa-aikavali-kentat">';
    echo '<label>Alkaen <input type="date" name="alkaen" value="'
        . htmlspecialchars((string) $vali['alkaen']) . '"></label>';
    echo '<label>Asti <input type="date" name="asti" value="'
        . htmlspecialchars((string) $vali['asti']) . '"></label>';
    echo '<button type="submit" class="btn-small">Näytä</button>';
    echo '<a class="btn-small-link" href="'
        . htmlspecialchars($action . $erotin . 'kaikki=1') . '">Kaikki</a>';
    echo '</div>';
    if ($kaikki) {
        echo '<p class="muted">Näytetään kaikki kilpailut ilman päivärajausta.</p>';
    } else {
        echo '<p class="muted">Oletus: 2 viikkoa ennen ja jälkeen tämän päivän.</p>';
    }
    echo '</form>';
    echo '<style>'
        . '.kisa-aikavali{margin:0 0 1rem;padding:.85rem 1rem;border:1px solid var(--border,#d8dee6);'
        . 'border-radius:10px;background:var(--card,#fff);max-width:44rem}'
        . '.kisa-aikavali-otsikko{margin:0 0 .55rem;font-weight:600}'
        . '.kisa-aikavali-kentat{display:flex;flex-wrap:wrap;gap:.65rem 1rem;align-items:flex-end}'
        . '.kisa-aikavali-kentat label{display:flex;flex-direction:column;gap:.2rem;font-size:.85rem}'
        . '.kisa-aikavali-kentat input[type=date]{padding:.35rem .5rem;min-height:2.1rem}'
        . '.kisa-aikavali .muted{margin:.55rem 0 0}'
        . '.active-kilpailu-seura{margin-top:4px;font-size:13px;font-weight:500}'
        . '.active-kilpailu-lisatieto{margin-top:2px;font-size:12px}'
        . '</style>';
}
