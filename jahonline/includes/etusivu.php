<?php

/**
 * Julkisen etusivun tapahtumalista.
 */

function etusivuKisaNaytaJulkisesti(array $k): bool
{
    $tila = (string) ($k['tila'] ?? '');
    if (!in_array($tila, ['kaynnissa', 'tulossa', 'ilmoittautuminen', 'valmis'], true)) {
        return false;
    }
    if (!empty($k['testikisa'])) {
        return false;
    }
    if (array_key_exists('julkinen', $k) && (int) $k['julkinen'] !== 1) {
        return false;
    }
    $nimi = mb_strtolower(trim((string) ($k['nimi'] ?? '')), 'UTF-8');
    if ($nimi === '') {
        return false;
    }
    if (preg_match('/jono-testi|testikisa|testijuoksu|^(jono-)?testi(\b|$)/u', $nimi)) {
        return false;
    }
    if ($tila === 'ilmoittautuminen' && empty($k['ilmoittautuminen_auki'])) {
        return false;
    }

    return true;
}

function etusivuKisaLive(array $k): bool
{
    return ($k['tila'] ?? '') === 'kaynnissa';
}

function etusivuKisaIlmoAuki(array $k): bool
{
    if (function_exists('ilmoittautuminenAuki')) {
        return ilmoittautuminenAuki($k);
    }

    $tila = (string) ($k['tila'] ?? '');
    if ($tila === 'valmis' || $tila === 'luonnos') {
        return false;
    }

    return !empty($k['ilmoittautuminen_auki']);
}

function etusivuKisaPaivaVertailu(array $a, array $b): int
{
    $da = (string) ($a['paiva'] ?? '');
    $db = (string) ($b['paiva'] ?? '');
    if ($da !== $db) {
        return $db <=> $da;
    }

    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
}

/**
 * Yksi aikajärjestetty lista: aina live ja ilmoittautuminen auki, plus N viimeisintä valmista.
 *
 * @param list<array> $kisat
 * @return list<array>
 */
function etusivuValitseLista(array $kisat, int $valmisRaja = 20): array
{
    $aina = [];
    $valmiit = [];
    foreach ($kisat as $k) {
        if (!etusivuKisaNaytaJulkisesti($k)) {
            continue;
        }
        $id = (int) ($k['id'] ?? 0);
        if (etusivuKisaLive($k) || etusivuKisaIlmoAuki($k)) {
            if ($id > 0) {
                $aina[$id] = $k;
            }
            continue;
        }
        if (($k['tila'] ?? '') === 'valmis') {
            $valmiit[] = $k;
        }
    }
    usort($valmiit, 'etusivuKisaPaivaVertailu');
    if ($valmisRaja >= 0) {
        $valmiit = array_slice($valmiit, 0, $valmisRaja);
    }
    $lista = $aina;
    foreach ($valmiit as $k) {
        $id = (int) ($k['id'] ?? 0);
        if ($id > 0 && !isset($lista[$id])) {
            $lista[$id] = $k;
        }
    }
    $lista = array_values($lista);
    usort($lista, 'etusivuKisaPaivaVertailu');

    return $lista;
}
