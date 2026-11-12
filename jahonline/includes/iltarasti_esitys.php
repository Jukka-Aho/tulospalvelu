<?php

/**
 * Iltarastien esitykset ja maastossa-luvut.
 */

function iltarastiTilanneLuvut(PDO $pdo, array $kilpailu): array
{
    $id = (int) ($kilpailu['id'] ?? 0);
    $maastossa = 0;
    $esitykset = 0;
    if ($id < 1) {
        return ['maastossa' => 0, 'esitykset' => 0];
    }
    $st = $pdo->prepare('SELECT keskhyl, status FROM kilpailijat WHERE kilpailu_id = ?');
    $st->execute([$id]);
    foreach ($st as $rivi) {
        $k = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');
        $stt = strtoupper(trim((string) ($rivi['status'] ?? '')));
        if ($k === 'E' || $stt === 'ESITYS') {
            $esitykset++;
        } elseif ($k === '' || $k === '-') {
            $maastossa++;
        }
    }

    return ['maastossa' => $maastossa, 'esitykset' => $esitykset];
}

function iltarastiEsitysNimi(array $h): string
{
    $nimi = trim((string) ($h['sukunimi'] ?? '') . ' ' . (string) ($h['etunimi'] ?? ''));
    if ($nimi === '') {
        $nimi = trim((string) ($h['nimi'] ?? ''));
    }

    return $nimi !== '' ? $nimi : 'Nimetön';
}

/**
 * @return list<array<string,mixed>>
 */
function iltarastiHaeEsitykset(PDO $pdo, array $kilpailu): array
{
    $id = (int) ($kilpailu['id'] ?? 0);
    if ($id < 1) {
        return [];
    }
    require_once __DIR__ . '/emit_lukija.php';
    if (!function_exists('leimaTarkistaRata')) {
        require_once __DIR__ . '/leimantarkastus.php';
    }
    $st = $pdo->prepare("
        SELECT k.*, s.nimi AS rata, a.aika_sec AS tulos_aika_sec
        FROM kilpailijat k
        LEFT JOIN sarjat s ON s.id = k.sarja_id
        LEFT JOIN ajat a ON a.kilpailija_id = k.id
        WHERE k.kilpailu_id = ?
          AND (
            UPPER(SUBSTRING(TRIM(COALESCE(k.keskhyl, '')), 1, 1)) = 'E'
            OR UPPER(TRIM(COALESCE(k.status, ''))) = 'ESITYS'
          )
        ORDER BY s.nimi, k.sukunimi, k.etunimi, k.nimi, k.id
    ");
    $st->execute([$id]);
    $rivit = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $radat = function_exists('emitLukijaRataLista') ? emitLukijaRataLista($pdo, $id) : [];
    $rataKartta = [];
    foreach ($radat as $r) {
        $rataKartta[(int) ($r['id'] ?? 0)] = $r;
    }
    $ulos = [];
    foreach ($rivit as $h) {
        $kid = (int) ($h['id'] ?? 0);
        $sarjaId = (int) ($h['sarja_id'] ?? 0);
        $rata = $rataKartta[$sarjaId] ?? ['id' => $sarjaId, 'nimi' => (string) ($h['rata'] ?? ''), 'koodit' => [], 'sammunut' => []];
        $leimat = emitLukijaHaeLeimatKannasta($pdo, $kid);
        $puuttuvat = [];
        $vaarat = [];
        $tarkOk = false;
        $koodit = is_array($rata['koodit'] ?? null) ? $rata['koodit'] : [];
        if ($koodit !== [] && function_exists('leimaTarkistaRata')) {
            $tark = leimaTarkistaRata($koodit, $leimat, is_array($rata['sammunut'] ?? null) ? $rata['sammunut'] : []);
            $tarkOk = !empty($tark['ok']);
            foreach ($tark['puuttuvat'] ?? [] as $koodi) {
                $puuttuvat[] = (int) $koodi;
            }
            $naytto = is_array($tark['naytto'] ?? null) ? $tark['naytto'] : [];
            foreach ($naytto['vaarat'] ?? [] as $v) {
                $koodi = (int) ($v['koodi'] ?? 0);
                if ($koodi > 0) {
                    $vaarat[] = $koodi;
                }
            }
        }
        $arvaus = null;
        if ($leimat && function_exists('iltarastiArvaaRata')) {
            $arvaus = iltarastiArvaaRata($pdo, $id, $sarjaId, $leimat);
        }
        $aikaSec = (int) ($h['tulos_aika_sec'] ?? $h['aika_sec'] ?? 0);
        $ulos[] = [
            'id' => $kid,
            'nimi' => iltarastiEsitysNimi($h),
            'seura' => trim((string) ($h['seura'] ?? '')),
            'rata' => (string) ($rata['nimi'] ?? $h['rata'] ?? ''),
            'sarja_id' => $sarjaId,
            'emit' => (int) ($h['emit_koodi'] ?? 0),
            'aika_sec' => $aikaSec,
            'aika_txt' => $aikaSec > 0 && function_exists('formatAikaSec') ? formatAikaSec($aikaSec) : '',
            'puuttuvat' => $puuttuvat,
            'vaarat' => $vaarat,
            'tark_ok' => $tarkOk,
            'arvaus' => $arvaus,
        ];
    }

    return $ulos;
}

function iltarastiEsitysKasittele(PDO $pdo, array $kilpailu, array $data): array
{
    $kid = (int) ($data['kilpailija_id'] ?? $data['id'] ?? 0);
    if ($kid < 1) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Henkilö puuttuu.'];
    }
    $st = $pdo->prepare('SELECT * FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
    $st->execute([$kid, (int) ($kilpailu['id'] ?? 0)]);
    $rivi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rivi) {
        return ['ok' => false, 'taso' => 'virhe', 'message' => 'Henkilöä ei löydy.'];
    }
    $action = (string) ($data['action'] ?? '');
    if ($action === 'vaihda_rata') {
        $vaihto = iltarastiVaihdaRata($pdo, $kilpailu, $rivi, (int) ($data['sarja_id'] ?? 0));
        if (empty($vaihto['ok'])) {
            return $vaihto + ['ok' => false];
        }
        $st->execute([$kid, (int) ($kilpailu['id'] ?? 0)]);
        $rivi = $st->fetch(PDO::FETCH_ASSOC) ?: $rivi;
        $tark = iltarastiTarkistaKilpailijaUudelleen($pdo, $kilpailu, $rivi);
        $st2 = $pdo->prepare('SELECT keskhyl FROM kilpailijat WHERE id = ?');
        $st2->execute([$kid]);
        $kesk = mb_strtoupper(mb_substr(trim((string) $st2->fetchColumn()), 0, 1), 'UTF-8');

        return [
            'ok' => true,
            'taso' => 'ok',
            'message' => (string) ($vaihto['message'] ?? 'Rata vaihdettu.')
                . (!empty($tark['message']) ? ' ' . $tark['message'] : ''),
            'keskhyl' => $kesk,
            'poista' => $kesk !== 'E',
            'luvut' => iltarastiTilanneLuvut($pdo, $kilpailu),
        ];
    }
    $paatos = (string) ($data['paatos'] ?? '');
    $tulos = iltarastiAsetaPaatos($pdo, $kilpailu, $rivi, $paatos);
    $tulos['poista'] = !empty($tulos['ok']);
    $tulos['luvut'] = iltarastiTilanneLuvut($pdo, $kilpailu);

    return $tulos;
}
