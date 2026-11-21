<?php

/**
 * USB-emiTag-lukija: sisäänluku ja leimantarkastus samalla sivulla.
 * Selain lukee sarjaportin (115200 USB). Tämä tiedosto tekee kirjauksen.
 */

require_once __DIR__ . '/../public/api/kilpailijat_bridge.php';
require_once __DIR__ . '/pirila_tapahtumat.php';
require_once __DIR__ . '/radat.php';
require_once __DIR__ . '/leimantarkastus.php';
require_once __DIR__ . '/emit_lukija_logiikka.php';
require_once __DIR__ . '/kisa_luonne.php';
require_once __DIR__ . '/iltarasti.php';
if (is_readable(__DIR__ . '/emit_henkilot.php')) {
    require_once __DIR__ . '/emit_henkilot.php';
}

function emitLukijaOikeus(array $kilpailu): bool
{
    if (function_exists('onAdmin') && onAdmin()) {
        return true;
    }
    $owner = (int) ($kilpailu['paakayttaja_id'] ?? 0);
    $oma = function_exists('paakayttajaId') ? (int) (paakayttajaId() ?? 0) : 0;
    if ($owner > 0 && $oma > 0 && $owner === $oma) {
        return true;
    }
    $seuraId = (int) ($kilpailu['seura_id'] ?? 0);
    if ($seuraId > 0 && function_exists('onSeuraAdminSeuralle') && onSeuraAdminSeuralle($seuraId)) {
        return true;
    }
    if (
        $seuraId > 0
        && function_exists('tiliNaytaKisaValikko')
        && tiliNaytaKisaValikko('lahtopaikka')
        && function_exists('haeSeuraKisaValikkoSeuraIdt')
        && in_array($seuraId, haeSeuraKisaValikkoSeuraIdt(), true)
    ) {
        return true;
    }
    if ($seuraId > 0 && function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)) {
        $omaSeura = function_exists('nykyinenSeuraId') ? nykyinenSeuraId() : (int) ($_SESSION['seura_id'] ?? 0);
        if ($omaSeura === $seuraId && function_exists('onIltarasti') && onIltarasti()) {
            return true;
        }
        if (function_exists('iltarastiVoiHallita') && iltarastiVoiHallita($kilpailu)) {
            return true;
        }
    }

    return false;
}

function emitLukijaLahde(int $kayttajaId): string
{
    return pirilaTapahtumaLahde('emit-' . $kayttajaId);
}

function emitLukijaOnViesti(array $kilpailu): bool
{
    return ($kilpailu['tyyppi'] ?? '') === 'viesti';
}

function emitLukijaHaeRiviSql(): string
{
    static $sql = null;
    if ($sql !== null) {
        return $sql;
    }
    $sql = '
        SELECT k.id, k.numero, k.nimi, k.etunimi, k.sukunimi, k.seura, k.status,
               k.lasna, k.keskhyl, k.sarja_id, k.osuus, k.emit_koodi, k.rata,
               k.pirila_lahto_at, k.laina, k.joukkue_id, s.nimi AS sarja, s.rata_nimi
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
    ';

    return $sql;
}

function emitLukijaHaeRiviSqlIlmanLainaa(): string
{
    return '
        SELECT k.id, k.numero, k.nimi, k.etunimi, k.sukunimi, k.seura, k.status,
               k.lasna, k.keskhyl, k.sarja_id, k.osuus, k.emit_koodi, k.rata,
               k.pirila_lahto_at, s.nimi AS sarja, s.rata_nimi
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
    ';
}

function emitLukijaHaeYksi(PDO $pdo, string $where, array $params): ?array
{
    static $ilmanLainaa = false;
    $sql = ($ilmanLainaa ? emitLukijaHaeRiviSqlIlmanLainaa() : emitLukijaHaeRiviSql())
        . ' ' . $where . ' ORDER BY k.osuus, k.id LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rivi = $st->fetch(PDO::FETCH_ASSOC);

        return $rivi ?: null;
    } catch (Throwable $e) {
        if ($ilmanLainaa) {
            throw $e;
        }
        $ilmanLainaa = true;

        return emitLukijaHaeYksi($pdo, $where, $params);
    }
}

function emitLukijaHaeEmit(PDO $pdo, int $kilpailuId, int $emit, bool $vainKaytossa = false): ?array
{
    if ($emit <= 0) {
        return null;
    }
    $where = 'WHERE k.kilpailu_id = ? AND (k.emit_koodi = ? OR k.emit_koodi2 = ?)';
    if ($vainKaytossa) {
        $where .= " AND (k.keskhyl IS NULL OR TRIM(k.keskhyl) = '' OR k.keskhyl = '-')";
    }

    return emitLukijaHaeYksi($pdo, $where, [$kilpailuId, $emit, $emit]);
}

function emitLukijaHaeId(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    return emitLukijaHaeYksi($pdo, 'WHERE k.id = ?', [$id]);
}

function emitLukijaHaeNumerolla(PDO $pdo, int $kilpailuId, int $numero): ?array
{
    if ($numero <= 0) {
        return null;
    }

    $rivi = emitLukijaHaeYksi(
        $pdo,
        'WHERE k.kilpailu_id = ? AND k.numero = ?',
        [$kilpailuId, $numero]
    );
    if ($rivi) {
        return $rivi;
    }

    return emitLukijaHaeYksi(
        $pdo,
        'WHERE k.kilpailu_id = ? AND k.joukkue_id IN (SELECT id FROM joukkueet WHERE kilpailu_id = ? AND numero = ?)',
        [$kilpailuId, $kilpailuId, $numero]
    );
}

/**
 * @param array<string,mixed> $rivi
 * @param array<string,mixed> $vastaus
 * @return array<string,mixed>
 */
function emitLukijaTaydennaViesti(PDO $pdo, array $kilpailu, ?array $rivi, array $vastaus): array
{
    if (!function_exists('onkoViestiKilpailu') || !onkoViestiKilpailu($kilpailu)) {
        return $vastaus;
    }
    $vastaus['viesti'] = true;
    $joukkueId = (int) ($vastaus['joukkue_id'] ?? ($rivi['joukkue_id'] ?? 0));
    $osuus = (int) ($vastaus['osuus'] ?? ($rivi['osuus'] ?? 0));
    $joukkue = null;
    if ($joukkueId > 0) {
        try {
            $st = $pdo->prepare('SELECT id, numero, seura, joukkue, sarja_id FROM joukkueet WHERE id = ?');
            $st->execute([$joukkueId]);
            $joukkue = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $joukkue = null;
        }
    }
    if (is_array($joukkue)) {
        $nro = $joukkue['numero'] ?? null;
        $vastaus['joukkue_id'] = (int) $joukkue['id'];
        $vastaus['joukkue_numero'] = $nro !== null && $nro !== '' ? (int) $nro : null;
        $vastaus['joukkue'] = trim((string) ($joukkue['joukkue'] ?? ''));
        $vastaus['joukkue_seura'] = trim((string) ($joukkue['seura'] ?? ''));
        if (trim((string) ($vastaus['seura'] ?? '')) === '') {
            $vastaus['seura'] = $vastaus['joukkue_seura'];
        }
        $naytto = emitLukijaViestiNaytto($vastaus + [
            'numero' => $vastaus['joukkue_numero'],
            'seura' => $vastaus['joukkue_seura'],
        ]);
        $vastaus['joukkue_nimi'] = $naytto['joukkue_nimi'];
        $vastaus['viesti_otsikko'] = $naytto['otsikko'];
        $vastaus['osuus_txt'] = $naytto['osuus_txt'];
        if ($vastaus['joukkue_numero'] && empty($vastaus['numero'])) {
            $vastaus['numero'] = $vastaus['joukkue_numero'];
        }
    }
    $osuusLkm = 0;
    $sarjaId = (int) ($joukkue['sarja_id'] ?? ($rivi['sarja_id'] ?? 0));
    if (function_exists('viestiOsuusLkm')) {
        $osuusLkm = viestiOsuusLkm($pdo, (int) $kilpailu['id'], $osuus, $sarjaId);
    }
    if ($joukkueId > 0) {
        try {
            $mx = $pdo->prepare('SELECT MAX(osuus) FROM kilpailijat WHERE joukkue_id = ?');
            $mx->execute([$joukkueId]);
            $osuusLkm = max($osuusLkm, (int) $mx->fetchColumn());
        } catch (Throwable $e) {
        }
    }
    $vastaus['osuus_lkm'] = max($osuusLkm, $osuus, 1);
    if ($osuus > 0 && $osuus < $vastaus['osuus_lkm'] && $joukkueId > 0) {
        try {
            $ns = $pdo->prepare('
                SELECT id, osuus, etunimi, sukunimi, nimi, emit_koodi
                FROM kilpailijat
                WHERE joukkue_id = ? AND osuus = ?
                ORDER BY id
                LIMIT 1
            ');
            $ns->execute([$joukkueId, $osuus + 1]);
            $seuraava = $ns->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $seuraava = null;
        }
        if ($seuraava) {
            $snimi = function_exists('kilpailijaNimiRivista')
                ? kilpailijaNimiRivista($seuraava)
                : trim(trim((string) ($seuraava['sukunimi'] ?? '')) . ' ' . trim((string) ($seuraava['etunimi'] ?? '')));
            $vastaus['seuraava_osuus'] = (int) ($seuraava['osuus'] ?? ($osuus + 1));
            $vastaus['seuraava_nimi'] = $snimi;
            $vastaus['seuraava_emit'] = (int) ($seuraava['emit_koodi'] ?? 0);
            $vastaus['seuraava_id'] = (int) ($seuraava['id'] ?? 0);
        }
        if (!empty($vastaus['tarkistus']) && (string) ($vastaus['taso'] ?? '') !== 'virhe') {
            $viesti = trim((string) ($vastaus['message'] ?? ''));
            $vaihto = 'Vaihto';
            if (!empty($vastaus['seuraava_nimi'])) {
                $vaihto .= '. Seuraava: ' . (int) $vastaus['seuraava_osuus'] . '. osuus '
                    . $vastaus['seuraava_nimi'];
            }
            if ($viesti === '' || stripos($viesti, 'vaihto') === false) {
                $vastaus['message'] = trim($vaihto . ($viesti !== '' ? '. ' . $viesti : ''));
            }
            $vastaus['vaihto'] = true;
        }
    } elseif ($osuus > 0 && $osuus >= $vastaus['osuus_lkm'] && !empty($vastaus['tarkistus'])) {
        $vastaus['joukkue_maali'] = true;
    }

    return $vastaus;
}

function emitLukijaSarja(PDO $pdo, int $kilpailuId, int $sarjaId): ?array
{
    if ($sarjaId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, nimi FROM sarjat WHERE id = ? AND kilpailu_id = ?');
    $st->execute([$sarjaId, $kilpailuId]);
    $rivi = $st->fetch(PDO::FETCH_ASSOC);

    return $rivi ?: null;
}

function emitLukijaSeuraavaNumero(PDO $pdo, int $kilpailuId): int
{
    if (function_exists('seuraavaKilpailijaNumero')) {
        return seuraavaKilpailijaNumero($pdo, $kilpailuId);
    }

    return 0;
}

function emitLukijaNimi(string $etu, string $suku, int $emit): array
{
    $etu = trim($etu);
    $suku = trim($suku);
    if ($etu === '' && $suku === '') {
        $suku = 'Emit ' . $emit;
    }
    if (function_exists('normalisoiKilpailijaNimet')) {
        return normalisoiKilpailijaNimet($etu, $suku);
    }
    $nimi = trim($etu . ' ' . $suku);

    return ['etunimi' => $etu, 'sukunimi' => $suku, 'nimi' => $nimi !== '' ? $nimi : $suku];
}

function emitLukijaKelloTeksti(int $sec): string
{
    if ($sec < 0) {
        $sec = 0;
    }
    $sec = $sec % 86400;
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;

    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function emitLukijaJuoksuSec(int $kello, ?int $nolla): ?int
{
    if ($nolla === null || $nolla < 0) {
        return null;
    }
    $delta = $kello - $nolla;
    if ($delta < -12 * 3600) {
        $delta += 86400;
    }
    if ($delta < 0) {
        $delta = 0;
    }

    return $delta;
}

/**
 * @param mixed $raw
 * @return list<array{koodi:int,kello_sec:int}>
 */
function emitLukijaPuhdistaLeimat($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $ulos = [];
    $ohita = [240, 243, 244, 250, 253];
    foreach ($raw as $leima) {
        if (!is_array($leima)) {
            continue;
        }
        $koodi = (int) ($leima['koodi'] ?? $leima['rasti_koodi'] ?? 0);
        $kelloRaaka = $leima['kello_sec'] ?? $leima['aika_sec'] ?? null;
        if (($kelloRaaka === null || $kelloRaaka === '') && !empty($leima['kello']) && is_string($leima['kello'])) {
            if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', trim($leima['kello']), $km)) {
                $kelloRaaka = ((int) $km[1] * 3600) + ((int) $km[2] * 60) + (int) $km[3];
            }
        }
        $kello = $kelloRaaka === null || $kelloRaaka === '' ? 0 : (int) $kelloRaaka;
        if ($koodi < 0 || $koodi > 255 || in_array($koodi, $ohita, true)) {
            continue;
        }
        if ($koodi > 239 && $koodi !== 248) {
            continue;
        }
        if ($kello < 0) {
            $kello = 0;
        }
        if ($kello > 8 * 86400) {
            continue;
        }
        if (function_exists('emitLukijaLeimaOnTyhjaMuisti') && emitLukijaLeimaOnTyhjaMuisti($kello)) {
            break;
        }
        if ($koodi === 0 && $kello === 0) {
            if ($ulos !== []) {
                break;
            }
            continue;
        }
        $ulos[] = ['koodi' => $koodi, 'kello_sec' => $kello];
        if (count($ulos) >= 400) {
            break;
        }
    }

    return $ulos;
}

function emitLukijaLokita(
    PDO $pdo,
    int $kilpailuId,
    ?int $kilpailijaId,
    int $emit,
    string $tulos,
    string $viesti,
    ?array $henkilo,
    int $kayttajaId
): void {
    try {
        $pdo->prepare('
            INSERT INTO lahtoluenta_tapahtumat
                (kilpailu_id, kilpailija_id, emit_koodi, numero, nimi, sarja, tulos, viesti, kayttaja_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $kilpailuId,
            $kilpailijaId,
            $emit,
            $henkilo['numero'] ?? null,
            $henkilo['nimi'] ?? null,
            $henkilo['sarja'] ?? null,
            $tulos,
            mb_substr($viesti, 0, 255),
            $kayttajaId > 0 ? $kayttajaId : null,
        ]);
        $laji = (string) ($henkilo['luku_laji'] ?? '');
        $sarake = [
            'lasna' => 'mobiili_lasna_at',
            'maali' => 'mobiili_maali_at',
            'lisatty' => 'mobiili_lisatty_at',
        ][$laji] ?? '';
        if ($kilpailijaId && $sarake !== '' && in_array($tulos, ['ok', 'varoitus'], true) && function_exists('kilpailijaVarmistaLukuAikaSarake')) {
            kilpailijaVarmistaLukuAikaSarake($pdo);
            $pdo->prepare('UPDATE kilpailijat SET `' . $sarake . '` = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $kilpailijaId]);
        }
    } catch (Throwable $e) {
    }
}

function emitLukijaKirjaaTapahtuma(PDO $pdo, int $kilpailuId, string $lahde, array $ev): void
{
    pirilaTapahtumaVarmista($pdo);
    $tyyppi = strtolower(trim((string) ($ev['tyyppi'] ?? '')));
    $pdo->prepare('
        INSERT INTO pirila_tapahtumat (kilpailu_id, lahde, tyyppi, payload)
        VALUES (?, ?, ?, ?)
    ')->execute([
        $kilpailuId,
        pirilaTapahtumaLahde($lahde),
        mb_substr($tyyppi, 0, 16),
        json_encode($ev, JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * @param array<string,mixed> $rivi
 * @return array<string,mixed>
 */
function emitLukijaHenkilo(array $rivi, int $emit): array
{
    $nimi = trim((string) ($rivi['nimi'] ?? ''));
    if ($nimi === '') {
        $nimi = trim(trim((string) ($rivi['etunimi'] ?? '')) . ' ' . trim((string) ($rivi['sukunimi'] ?? '')));
    }

    return [
        'kilpailija_id' => (int) $rivi['id'],
        'numero' => $rivi['numero'] !== null ? (int) $rivi['numero'] : null,
        'nimi' => $nimi,
        'etunimi' => trim((string) ($rivi['etunimi'] ?? '')),
        'sukunimi' => trim((string) ($rivi['sukunimi'] ?? '')),
        'seura' => trim((string) ($rivi['seura'] ?? '')),
        'sarja' => trim((string) ($rivi['sarja'] ?? '')),
        'sarja_id' => (int) ($rivi['sarja_id'] ?? 0),
        'osuus' => (int) ($rivi['osuus'] ?? 0),
        'joukkue_id' => (int) ($rivi['joukkue_id'] ?? 0),
        'emit' => $emit,
        'keskhyl' => (string) ($rivi['keskhyl'] ?? ''),
        'kilpailija_status' => (string) ($rivi['status'] ?? ''),
        'rata' => trim((string) ($rivi['rata'] ?? '')),
        'rata_nimi' => trim((string) ($rivi['rata_nimi'] ?? '')),
        'pirila_lahto_at' => (string) ($rivi['pirila_lahto_at'] ?? ''),
        'lasna' => (int) ($rivi['lasna'] ?? 0),
        'laina' => strtoupper(substr(trim((string) ($rivi['laina'] ?? '')), 0, 1)) === 'X',
        'jo_lahtenyt' => emitLukijaOnLahtenyt($rivi),
    ];
}

function emitLukijaVastaus(string $taso, string $viesti, array $extra = []): array
{
    return array_merge($extra, [
        'status' => $taso === 'virhe' ? 'error' : 'ok',
        'taso' => $taso,
        'message' => $viesti,
    ]);
}

function emitLukijaMerkitseLasna(PDO $pdo, array $kilpailu, array $rivi, int $kayttajaId, int $emit): array
{
    $kilpailuId = (int) $kilpailu['id'];
    $henkilo = emitLukijaHenkilo($rivi, $emit);
    $kirjain = mb_strtoupper(trim((string) ($rivi['keskhyl'] ?? '')), 'UTF-8');
    $status = strtoupper((string) ($rivi['status'] ?? ''));
    if (in_array($status, ['DNF', 'DSQ', 'MP'], true)) {
        return emitLukijaVastaus('varoitus', 'Kilpailijalla on jo tulos (' . $status . '). Läsnäoloa ei muutettu.', $henkilo);
    }

    $joLasna = ($kirjain === '-');
    $numero = $rivi['numero'] !== null ? (int) $rivi['numero'] : 0;
    $osuus = (int) ($rivi['osuus'] ?? 0);
    if ($numero > 0) {
        $rap = pirilaTapahtumaLisaa($pdo, $kilpailuId, emitLukijaLahde($kayttajaId), [[
            'tyyppi' => 'status',
            'numero' => $numero,
            'osuus' => $osuus,
            'keskhyl' => '-',
            'badge' => $emit,
            'emit_koodi' => $emit,
        ]]);
        if ((int) ($rap['ok'] ?? 0) < 1) {
            $pdo->prepare('UPDATE kilpailijat SET lasna = 1, keskhyl = ?, status = ? WHERE id = ?')
                ->execute(['-', 'OK', (int) $rivi['id']]);
        }
    } else {
        $pdo->prepare('UPDATE kilpailijat SET lasna = 1, keskhyl = ?, status = ? WHERE id = ?')
            ->execute(['-', 'OK', (int) $rivi['id']]);
    }

    $viesti = $joLasna ? 'Jo läsnä.' : 'Läsnä.';
    if ($numero <= 0) {
        $viesti = 'Läsnä merkitty. Kilpailunumero puuttuu, joten Pirilä ei saa riviä.';
    }
    $lahtoAt = emitLukijaKirjaaNollausLahto(
        $pdo,
        (int) $rivi['id'],
        (string) ($rivi['pirila_lahto_at'] ?? '')
    );
    if ($lahtoAt !== null) {
        $henkilo['pirila_lahto_at'] = $lahtoAt;
        $henkilo['jo_lahtenyt'] = true;
        $viesti .= ' Lähtö ' . substr($lahtoAt, 11, 8) . '.';
    }

    return emitLukijaVastaus($joLasna ? 'varoitus' : 'ok', $viesti, $henkilo + ['jo_lasna' => $joLasna, 'lahto_at' => $lahtoAt]);
}

function emitLukijaKirjaaNollausLahto(PDO $pdo, int $kid, ?string $olemassa): ?string
{
    $olemassa = trim((string) $olemassa);
    if ($olemassa !== '') {
        return $olemassa;
    }
    if ($kid <= 0) {
        return null;
    }
    $at = function_exists('paikallinenAikaNyt') ? paikallinenAikaNyt() : date('Y-m-d H:i:s');
    try {
        $pdo->prepare('
            UPDATE kilpailijat
            SET pirila_lahto_at = ?
            WHERE id = ?
              AND (pirila_lahto_at IS NULL OR TRIM(pirila_lahto_at) = \'\')
        ')->execute([$at, $kid]);
    } catch (Throwable $e) {
        return null;
    }

    return $at;
}

function emitLukijaLisaa(
    PDO $pdo,
    array $kilpailu,
    int $emit,
    array $sarja,
    array $nimet,
    ?int $numeroToive,
    string $seura,
    int $kayttajaId
): array {
    if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)
        && function_exists('iltarastiVarmistaHenkilo')) {
        try {
            $luotu = iltarastiVarmistaHenkilo(
                $pdo,
                $kilpailu,
                (int) $sarja['id'],
                (string) ($nimet['etunimi'] ?? ''),
                (string) ($nimet['sukunimi'] ?? ''),
                $seura,
                $emit,
                0
            );
            $pdo->prepare('UPDATE kilpailijat SET lasna = 1, keskhyl = ?, status = ? WHERE id = ?')
                ->execute(['-', 'OK', (int) $luotu['id']]);
            $henkilo = emitLukijaHenkilo($luotu, $emit);

            return emitLukijaVastaus(
                'ok',
                'Lisätty radalle ' . $henkilo['sarja'] . '.',
                $henkilo + ['luotu' => true]
            );
        } catch (Throwable $e) {
            return emitLukijaVastaus('virhe', $e->getMessage());
        }
    }
    $kilpailuId = (int) $kilpailu['id'];
    $viesti = emitLukijaOnViesti($kilpailu);
    $osuus = $viesti ? 1 : 0;
    $yritykset = 0;
    $luotu = null;
    while ($yritykset < 5) {
        $yritykset++;
        $numero = $numeroToive ?? emitLukijaSeuraavaNumero($pdo, $kilpailuId);
        $katto = function_exists('pirilaMaxKilpailunumero') ? pirilaMaxKilpailunumero() : 9979;
        if ($numero <= 0 || $numero > $katto) {
            if ($numeroToive !== null && $numeroToive > $katto) {
                return emitLukijaVastaus('virhe', 'Numero on liian suuri. Pirilä ottaa numerot 1–' . $katto . '.');
            }

            return emitLukijaVastaus('virhe', 'Vapaita kilpailunumeroita ei ole välillä 1–' . $katto . '.');
        }
        if (function_exists('kilpailijaNumeroVarattu') && kilpailijaNumeroVarattu($pdo, $kilpailuId, $numero)) {
            if ($numeroToive !== null) {
                return emitLukijaVastaus('virhe', 'Numero ' . $numero . ' on jo käytössä.');
            }
            continue;
        }
        $rap = pirilaTapahtumaLisaa($pdo, $kilpailuId, emitLukijaLahde($kayttajaId), [[
            'tyyppi' => 'kilpailija',
            'numero' => $numero,
            'osuus' => $osuus,
            'sukunimi' => $nimet['sukunimi'],
            'etunimi' => $nimet['etunimi'],
            'nimi' => $nimet['nimi'],
            'seura' => $seura,
            'sarja_nimi' => $sarja['nimi'],
            'badge' => $emit,
            'emit_koodi' => $emit,
            'lasna' => true,
            'keskhyl' => '-',
            'status' => 'OK',
        ]]);
        if ((int) ($rap['ok'] ?? 0) < 1) {
            if ($numeroToive !== null) {
                return emitLukijaVastaus('virhe', 'Lisäys ei onnistunut.');
            }
            continue;
        }
        $luotu = $emit > 0
            ? emitLukijaHaeEmit($pdo, $kilpailuId, $emit, function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu))
            : emitLukijaHaeNumerolla($pdo, $kilpailuId, $numero);
        break;
    }
    if (!$luotu) {
        return emitLukijaVastaus('virhe', 'Lisäys ei onnistunut.');
    }
    $pdo->prepare('UPDATE kilpailijat SET ilmoittautunut_at = COALESCE(ilmoittautunut_at, NOW()) WHERE id = ?')
        ->execute([(int) $luotu['id']]);
    $henkilo = emitLukijaHenkilo($luotu, $emit);

    $sana = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) ? 'radalle' : 'sarjaan';

    return emitLukijaVastaus('ok', 'Lisätty ' . $sana . ' ' . $henkilo['sarja'] . '.', $henkilo + ['luotu' => true]);
}

function emitLukijaSiirra(PDO $pdo, array $kilpailu, int $emit, array $sarja, int $kayttajaId): array
{
    $rivi = emitLukijaHaeEmit(
        $pdo,
        (int) $kilpailu['id'],
        $emit,
        function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)
    );
    if (!$rivi) {
        return emitLukijaVastaus('virhe', 'Korttia ei löydy kilpailusta.');
    }
    if ((int) $rivi['sarja_id'] === (int) $sarja['id']) {
        $henkilo = emitLukijaHenkilo($rivi, $emit);

        $sana = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) ? 'radalla' : 'sarjassa';

        return emitLukijaVastaus('varoitus', 'On jo ' . $sana . ' ' . $henkilo['sarja'] . '.', $henkilo);
    }
    $numero = $rivi['numero'] !== null ? (int) $rivi['numero'] : 0;
    if ($numero <= 0) {
        $pdo->prepare('UPDATE kilpailijat SET sarja_id = ? WHERE id = ?')
            ->execute([(int) $sarja['id'], (int) $rivi['id']]);
    } else {
        $nimet = emitLukijaNimi((string) ($rivi['etunimi'] ?? ''), (string) ($rivi['sukunimi'] ?? ''), $emit);
        $rap = pirilaTapahtumaLisaa($pdo, (int) $kilpailu['id'], emitLukijaLahde($kayttajaId), [[
            'tyyppi' => 'kilpailija',
            'numero' => $numero,
            'osuus' => (int) ($rivi['osuus'] ?? 0),
            'sukunimi' => $nimet['sukunimi'],
            'etunimi' => $nimet['etunimi'],
            'nimi' => $nimet['nimi'] !== '' ? $nimet['nimi'] : (string) $rivi['nimi'],
            'seura' => (string) ($rivi['seura'] ?? ''),
            'sarja_nimi' => $sarja['nimi'],
            'badge' => $emit,
            'lasna' => true,
            'keskhyl' => '-',
            'status' => 'OK',
        ]]);
        if ((int) ($rap['ok'] ?? 0) < 1) {
            $pdo->prepare('UPDATE kilpailijat SET sarja_id = ? WHERE id = ?')
                ->execute([(int) $sarja['id'], (int) $rivi['id']]);
        }
    }
    $uusi = emitLukijaHaeId($pdo, (int) $rivi['id']);
    $henkilo = emitLukijaHenkilo($uusi ?: $rivi, $emit);

    $sana = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) ? 'radalle' : 'sarjaan';

    return emitLukijaVastaus('ok', 'Siirretty ' . $sana . ' ' . $sarja['nimi'] . '.', $henkilo + ['luotu' => false]);
}

function emitLukijaPaivita(
    PDO $pdo,
    array $kilpailu,
    array $rivi,
    int $emit,
    array $sarja,
    string $etu,
    string $suku,
    string $seura,
    int $kayttajaId
): array {
    $etu = trim($etu);
    $suku = trim($suku);
    if ($etu === '' && $suku === '') {
        $etu = trim((string) ($rivi['etunimi'] ?? ''));
        $suku = trim((string) ($rivi['sukunimi'] ?? ''));
    }
    $nimet = emitLukijaNimi($etu, $suku, $emit);
    $numero = $rivi['numero'] !== null ? (int) $rivi['numero'] : 0;
    $osuus = (int) ($rivi['osuus'] ?? 0);
    $onnistui = false;
    if ($numero > 0) {
        $rap = pirilaTapahtumaLisaa($pdo, (int) $kilpailu['id'], emitLukijaLahde($kayttajaId), [[
            'tyyppi' => 'kilpailija',
            'numero' => $numero,
            'osuus' => $osuus,
            'sukunimi' => $nimet['sukunimi'],
            'etunimi' => $nimet['etunimi'],
            'nimi' => $nimet['nimi'],
            'seura' => $seura,
            'sarja_nimi' => $sarja['nimi'],
            'badge' => $emit,
            'lasna' => true,
            'keskhyl' => '-',
            'status' => 'OK',
        ]]);
        $onnistui = (int) ($rap['ok'] ?? 0) >= 1;
    }
    if (!$onnistui) {
        $pdo->prepare('
            UPDATE kilpailijat
            SET sarja_id = ?, nimi = ?, etunimi = ?, sukunimi = ?, seura = ?, lasna = 1, keskhyl = ?, status = ?
            WHERE id = ?
        ')->execute([
            (int) $sarja['id'],
            $nimet['nimi'],
            $nimet['etunimi'] !== '' ? $nimet['etunimi'] : null,
            $nimet['sukunimi'] !== '' ? $nimet['sukunimi'] : null,
            $seura !== '' ? $seura : null,
            '-',
            'OK',
            (int) $rivi['id'],
        ]);
    }
    $uusi = emitLukijaHaeId($pdo, (int) $rivi['id']);
    $henkilo = emitLukijaHenkilo($uusi ?: $rivi, $emit);

    return emitLukijaVastaus('ok', 'Kilpailija tallennettu.', $henkilo + ['luotu' => false, 'loytyi' => true]);
}

/**
 * @param list<array{koodi:int,kello_sec:int}> $leimat
 * @return list<array{jarjestys:int,koodi:int,kello:string,aika_sec:int}>
 */
function emitLukijaTallennaLeimat(
    PDO $pdo,
    array $kilpailu,
    array $rivi,
    array $leimat,
    ?int $nolla,
    int $kayttajaId,
    bool $korvaaAina = false
): array {
    pirilaTapahtumaVarmista($pdo);
    $kilpailuId = (int) $kilpailu['id'];
    $kid = (int) $rivi['id'];
    if (
        !$korvaaAina
        && $kid > 0
        && function_exists('emitLukijaUudetLeimatVainEtuliite')
        && function_exists('emitLukijaHaeLeimatKannasta')
    ) {
        $vanhat = emitLukijaHaeLeimatKannasta($pdo, $kid);
        if (emitLukijaUudetLeimatVainEtuliite($vanhat, $leimat)) {
            $naytto = [];
            foreach ($vanhat as $i => $vanha) {
                $kello = (int) ($vanha['kello_sec'] ?? $vanha['aika_sec'] ?? 0);
                $naytto[] = [
                    'jarjestys' => $i + 1,
                    'koodi' => (int) ($vanha['koodi'] ?? 0),
                    'kello' => emitLukijaKelloTeksti($kello),
                    'aika_sec' => (int) ($vanha['aika_sec'] ?? $kello),
                ];
            }

            return $naytto;
        }
    }
    if ($nolla === null) {
        $nolla = emitLukijaNollaLeimoista($leimat);
    }
    $rataRastit = [];
    $sarjaId = (int) ($rivi['sarja_id'] ?? 0);
    if ($sarjaId > 0 && function_exists('haeSarjanRastit')) {
        $rataRastit = haeSarjanRastit($pdo, $sarjaId);
    }
    $leimaPisteet = function_exists('emitLukijaLeimaPisteet')
        ? emitLukijaLeimaPisteet($rataRastit, $leimat)
        : [];
    if ($kid > 0 && function_exists('jahBridgeTallennaValiaika')) {
        try {
            $pdo->prepare("DELETE FROM valiajat WHERE kilpailija_id = ? AND laji = 'emit'")->execute([$kid]);
        } catch (Throwable $e) {
        }
    }
    $pdo->prepare('DELETE FROM emit_leimat WHERE kilpailija_id = ?')->execute([$kid]);
    $ins = $pdo->prepare('
        INSERT INTO emit_leimat (kilpailu_id, kilpailija_id, jarjestys, rasti_koodi, aika_sec)
        VALUES (?, ?, ?, ?, ?)
    ');
    $naytto = [];
    $loki = [];
    $jarjestys = 0;
    foreach ($leimat as $leima) {
        $jarjestys++;
        $kello = (int) $leima['kello_sec'];
        $juoksu = emitLukijaJuoksuSec($kello, $nolla);
        $tallenna = $juoksu !== null ? $juoksu : $kello;
        $koodi = (string) $leima['koodi'];
        $ins->execute([$kilpailuId, $kid, $jarjestys, $koodi, $tallenna]);
        $vaAika = $juoksu !== null ? $juoksu : $tallenna;
        if ($vaAika > 0 && function_exists('jahBridgeTallennaValiaika')) {
            $vaPiste = (int) ($leimaPisteet[$jarjestys - 1] ?? 0);
            if ($vaPiste > 0) {
                jahBridgeTallennaValiaika($pdo, $kid, $vaPiste, $vaAika, null, $koodi, 'emit');
            }
        }
        $naytto[] = [
            'jarjestys' => $jarjestys,
            'koodi' => (int) $koodi,
            'kello' => emitLukijaKelloTeksti($kello),
            'aika_sec' => $juoksu !== null ? $juoksu : $tallenna,
        ];
        $loki[] = [
            'jarjestys' => $jarjestys,
            'koodi' => (int) $koodi,
            'aika_sec' => $juoksu ?? 0,
            'kello_sec' => $kello,
        ];
    }
    $numero = $rivi['numero'] !== null ? (int) $rivi['numero'] : 0;
    if ($numero > 0 && $loki) {
        emitLukijaKirjaaTapahtuma($pdo, $kilpailuId, emitLukijaLahde($kayttajaId), [
            'tyyppi' => 'emit',
            'numero' => $numero,
            'osuus' => (int) ($rivi['osuus'] ?? 0),
            'leimat' => $loki,
        ]);
    }

    return $naytto;
}

/**
 * Käsin muokatut emit-leimat: korvaa kortin leimat, kirjoittaa lokin ja tarkistaa radan.
 *
 * @param list<array{koodi:int,kello_sec?:int,aika_sec?:int}> $leimat
 * @return array<string,mixed>
 */
function emitLukijaTallennaMuokatut(
    PDO $pdo,
    array $kilpailu,
    array $rivi,
    array $leimat,
    int $kayttajaId
): array {
    $puhdistetut = [];
    foreach ($leimat as $leima) {
        if (!is_array($leima)) {
            continue;
        }
        $koodi = (int) ($leima['koodi'] ?? 0);
        $kello = (int) ($leima['kello_sec'] ?? $leima['aika_sec'] ?? 0);
        if ($koodi < 0) {
            continue;
        }
        $puhdistetut[] = ['koodi' => $koodi, 'kello_sec' => $kello];
    }
    $naytto = emitLukijaTallennaLeimat($pdo, $kilpailu, $rivi, $puhdistetut, null, $kayttajaId, true);
    $numero = $rivi['numero'] !== null ? (int) $rivi['numero'] : 0;
    if ($numero > 0 && !$puhdistetut) {
        emitLukijaKirjaaTapahtuma($pdo, (int) $kilpailu['id'], emitLukijaLahde($kayttajaId), [
            'tyyppi' => 'emit',
            'numero' => $numero,
            'osuus' => (int) ($rivi['osuus'] ?? 0),
            'leimat' => [],
        ]);
    }
    $kid = (int) ($rivi['id'] ?? 0);
    if ($kid > 0 && function_exists('emitLukijaKirjoitaValiajatLeimoista')) {
        emitLukijaKirjoitaValiajatLeimoista($pdo, $rivi, $puhdistetut);
    }
    $tark = emitLukijaLiitaTarkistus($pdo, $kilpailu, $rivi, $puhdistetut, null, $kayttajaId, null, true);
    $viesti = count($naytto) === 0
        ? 'Leimat poistettu.'
        : (count($naytto) . ' leimaa tallennettu.');
    if (!empty($tark['tarkistus_viesti'])) {
        $viesti .= ' ' . $tark['tarkistus_viesti'];
    }
    $taso = (string) ($tark['taso_tarkistus'] ?? 'ok');
    if ($taso === 'virhe') {
        $taso = 'varoitus';
    }
    $henkilo = emitLukijaHenkilo($rivi, (int) ($rivi['emit_koodi'] ?? 0));

    return emitLukijaVastaus($taso, $viesti, $henkilo + $tark + ['leimat' => $naytto]);
}

/**
 * @param list<array<string,mixed>> $leimat
 * @return array<string,mixed>
 */
function emitLukijaLiitaTarkistus(
    PDO $pdo,
    array $kilpailu,
    array $rivi,
    array $leimat,
    ?int $nolla,
    int $kayttajaId,
    ?int $maaliNapautus = null,
    bool $tallenna = true
): array {
    if (!function_exists('leimaTarkistaKilpailija')) {
        return [];
    }
    $henkilo = emitLukijaHenkilo($rivi, (int) ($rivi['emit_koodi'] ?? 0));
    $henkilo['id'] = (int) $rivi['id'];
    $henkilo['numero'] = $rivi['numero'] !== null ? (int) $rivi['numero'] : 0;
    $henkilo['osuus'] = (int) ($rivi['osuus'] ?? 0);
    $henkilo['pirila_lahto_at'] = (string) ($rivi['pirila_lahto_at'] ?? $henkilo['pirila_lahto_at'] ?? '');
    $lahde = emitLukijaLahde($kayttajaId);
    try {
        $tulos = leimaTarkistaKilpailija(
            $pdo,
            $kilpailu,
            $henkilo,
            $leimat,
            $nolla,
            $maaliNapautus,
            $tallenna,
            $lahde
        );
    } catch (Throwable $e) {
        $tulos = [
            'ok' => false,
            'taso' => 'varoitus',
            'message' => 'Tarkistus epäonnistui.',
            'tarkistus' => null,
            'keskhyl' => null,
            'esitys' => false,
        ];
    }
    $ulos = [
        'tarkistus' => $tulos['tarkistus'] ?? null,
        'keskhyl' => $tulos['keskhyl'] ?? null,
        'aika_sec' => $tulos['aika_sec'] ?? null,
        'lahto_at' => $tulos['lahto_at'] ?? null,
        'tarkistus_viesti' => $tulos['message'] ?? '',
        'taso_tarkistus' => $tulos['taso'] ?? null,
        'esitys' => !empty($tulos['esitys']),
    ];
    if ($tallenna && $leimat && function_exists('iltarastiVarmistaEiMaastossaLeimoilla')) {
        $k = mb_strtoupper(mb_substr(trim((string) ($ulos['keskhyl'] ?? '')), 0, 1), 'UTF-8');
        if ($k === '' || $k === '-') {
            $varm = iltarastiVarmistaEiMaastossaLeimoilla($pdo, $kilpailu, $henkilo, $leimat);
            if (!empty($varm['keskhyl'])) {
                $ulos['keskhyl'] = $varm['keskhyl'];
                $ulos['esitys'] = !empty($varm['esitys']);
                if (!empty($varm['kirjoitettu'])) {
                    $ulos['taso_tarkistus'] = $ulos['taso_tarkistus'] === 'virhe' ? 'varoitus' : ($ulos['taso_tarkistus'] ?: 'varoitus');
                    $ulos['tarkistus_viesti'] = trim(
                        (string) ($ulos['tarkistus_viesti'] ?? '') . ' ' . (string) ($varm['message'] ?? 'Esitys. Leimat luettu.')
                    );
                }
            }
        }
    }
    if ($tallenna && function_exists('emitLukijaKirjoitaValiajatLeimoista')) {
        $kid = (int) ($rivi['id'] ?? 0);
        $lahde = $leimat;
        if ($lahde === [] && $kid > 0 && function_exists('emitLukijaHaeLeimatKannasta')) {
            $lahde = emitLukijaHaeLeimatKannasta($pdo, $kid);
        }
        if ($lahde) {
            emitLukijaKirjoitaValiajatLeimoista($pdo, $rivi, $lahde);
        }
    }

    return $ulos;
}

/**
 * @return list<array{id:int,nimi:string,koodit:list<int>}>
 */
function emitLukijaRataLista(PDO $pdo, int $kilpailuId): array
{
    if (function_exists('iltarastiSiivoaHaamuSarjat')) {
        try {
            iltarastiSiivoaHaamuSarjat($pdo, $kilpailuId);
        } catch (Throwable $e) {
        }
    }
    $st = $pdo->prepare('SELECT id, nimi FROM sarjat WHERE kilpailu_id = ? ORDER BY jarjestys, nimi, id');
    $st->execute([$kilpailuId]);
    $ulos = [];
    foreach ($st as $s) {
        $id = (int) $s['id'];
        $koodit = [];
        $sammunut = [];
        $rivit = [];
        if ($id > 0 && function_exists('haeSarjanRastitRadalta')) {
            $rivit = haeSarjanRastitRadalta($pdo, $id);
        }
        if ($rivit === [] && $id > 0 && function_exists('haeSarjanRastit')) {
            $rivit = haeSarjanRastit($pdo, $id);
        }
        foreach ($rivit as $r) {
            $n = (int) ($r['rasti_koodi'] ?? $r['koodi'] ?? 0);
            if ($n > 0) {
                $koodit[] = $n;
                $sammunut[] = !empty($r['sammunut']) ? 1 : 0;
            }
        }
        $rivi = ['id' => $id, 'nimi' => (string) $s['nimi'], 'koodit' => $koodit, 'sammunut' => $sammunut];
        $ulos[] = $rivi;
    }
    if ($ulos !== [] && function_exists('radatHaeKilpailuun')) {
        $radat = radatHaeKilpailuun($pdo, $kilpailuId);
        foreach ($ulos as &$rivi) {
            foreach ($radat as $rr) {
                if (strcasecmp((string) ($rr['tunnus'] ?? ''), (string) ($rivi['nimi'] ?? '')) === 0) {
                    $rivi['rataId'] = (int) ($rr['id'] ?? 0);
                    if (function_exists('radatPituusKmLomakkeeseen')) {
                        $rivi['pituus_km'] = radatPituusKmLomakkeeseen($rr['pituus_m'] ?? null);
                    }
                    break;
                }
            }
        }
        unset($rivi);
    }

    return $ulos;
}

/**
 * Esityksessä arvattava rata: tasan yksi toinen rata täsmää leimoihin.
 *
 * @param list<array{koodi?:int|string,kello_sec?:int,aika_sec?:int}> $leimat
 * @return array{id:int,nimi:string}|null
 */
function iltarastiArvaaRata(PDO $pdo, int $kilpailuId, int $nykySarjaId, array $leimat): ?array
{
    if ($kilpailuId < 1 || !function_exists('iltarastiArvaaRataLeimoista')) {
        return null;
    }
    $radat = [];
    foreach (emitLukijaRataLista($pdo, $kilpailuId) as $s) {
        $id = (int) ($s['id'] ?? 0);
        $radat[] = [
            'id' => $id,
            'nimi' => (string) ($s['nimi'] ?? ''),
            'koodit' => $s['koodit'] ?? [],
            'sammunut' => $s['sammunut'] ?? [],
        ];
    }

    return iltarastiArvaaRataLeimoista($radat, $leimat, $nykySarjaId);
}

/**
 * Kirjoita emit-väliajat uudelleen radan nykyisten rastien mukaan.
 *
 * @param list<array{koodi?:int|string,aika_sec?:int,kello_sec?:int}> $leimat
 */
function emitLukijaKirjoitaValiajatLeimoista(PDO $pdo, array $rivi, array $leimat): void
{
    $kid = (int) ($rivi['id'] ?? $rivi['kilpailija_id'] ?? 0);
    if ($kid < 1 || !function_exists('jahBridgeTallennaValiaika')) {
        return;
    }
    $rata = [];
    $sarjaId = (int) ($rivi['sarja_id'] ?? 0);
    if ($sarjaId > 0 && function_exists('haeSarjanRastit')) {
        $rata = haeSarjanRastit($pdo, $sarjaId);
    }
    $pisteet = function_exists('emitLukijaLeimaPisteet')
        ? emitLukijaLeimaPisteet($rata, $leimat)
        : [];
    $nolla = function_exists('emitLukijaNollaLeimoista')
        ? emitLukijaNollaLeimoista($leimat)
        : null;
    try {
        $pdo->prepare("DELETE FROM valiajat WHERE kilpailija_id = ? AND laji = 'emit'")->execute([$kid]);
    } catch (Throwable $e) {
    }
    foreach ($leimat as $i => $leima) {
        $p = (int) ($pisteet[$i] ?? 0);
        $raw = (int) ($leima['aika_sec'] ?? $leima['kello_sec'] ?? 0);
        $sec = $raw;
        if ($nolla !== null && function_exists('emitLukijaJuoksuSec')) {
            $juoksu = emitLukijaJuoksuSec($raw, $nolla);
            if ($juoksu !== null) {
                $sec = $juoksu;
            }
        }
        $koodi = (string) ($leima['koodi'] ?? '');
        if ($p > 0 && $sec > 0) {
            jahBridgeTallennaValiaika($pdo, $kid, $p, $sec, null, $koodi, 'emit');
        }
    }
}

function emitLukijaHaeLeimatKannasta(PDO $pdo, int $kilpailijaId): array
{
    $st = $pdo->prepare('
        SELECT jarjestys, rasti_koodi, aika_sec
        FROM emit_leimat
        WHERE kilpailija_id = ?
        ORDER BY jarjestys
    ');
    $st->execute([$kilpailijaId]);
    $ulos = [];
    foreach ($st as $row) {
        $ulos[] = [
            'koodi' => (int) $row['rasti_koodi'],
            'aika_sec' => (int) $row['aika_sec'],
            'kello_sec' => (int) $row['aika_sec'],
        ];
    }

    return $ulos;
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function emitLukijaKasittele(PDO $pdo, array $kilpailu, int $kayttajaId, array $data): array
{
    $moodi = (string) ($data['moodi'] ?? '');
    if (!in_array($moodi, ['lahto', 'lisaa', 'tallenna', 'hae', 'lue', 'maali', 'siirra', 'tarkista', 'ota_aika', 'paatos', 'vaihda_rata', 'maastoon', 'pois_maastosta'], true)) {
        return emitLukijaVastaus('virhe', 'Tuntematon tila.');
    }
    $emit = (int) ($data['emit'] ?? $data['badge'] ?? 0);
    $numeroHaku = (int) ($data['numero'] ?? 0);
    $iltarastiKisa = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu);
    if ($iltarastiKisa) {
        iltarastiVarmista($pdo);
        if (function_exists('emitHenkilotVarmista')) {
            emitHenkilotVarmista($pdo);
        }
    }
    $sallittuNolla = $iltarastiKisa && in_array($moodi, ['tallenna', 'lisaa', 'maastoon', 'pois_maastosta'], true);
    if (($emit <= 0 || $emit > 99999999) && !in_array($moodi, ['tarkista', 'ota_aika', 'maastoon', 'pois_maastosta'], true) && !$sallittuNolla) {
        return emitLukijaVastaus('virhe', 'Emit-numero ei kelpaa.');
    }

    $kilpailuId = (int) $kilpailu['id'];
    $automaattiLuku = function_exists('emitLukijaOnAutomaatti') && emitLukijaOnAutomaatti($data);
    $rivi = $emit > 0 ? emitLukijaHaeEmit($pdo, $kilpailuId, $emit, $iltarastiKisa) : null;
    $riviValmis = null;
    if (!$rivi && $iltarastiKisa && $emit > 0) {
        $riviValmis = emitLukijaHaeEmit($pdo, $kilpailuId, $emit, false);
        if ($riviValmis && $automaattiLuku && in_array($moodi, ['lue', 'maali'], true)) {
            $rivi = $riviValmis;
        }
    }
    if (!$rivi && in_array($moodi, ['tarkista', 'ota_aika'], true) && $numeroHaku > 0) {
        $rivi = emitLukijaHaeNumerolla($pdo, $kilpailuId, $numeroHaku);
        if ($rivi && $emit <= 0) {
            $emit = (int) ($rivi['emit_koodi'] ?? 0);
        }
    }
    if (($emit <= 0 || $emit > 99999999) && !$rivi && !in_array($moodi, ['maastoon', 'pois_maastosta'], true) && !$sallittuNolla) {
        return emitLukijaVastaus('virhe', 'Emit-numero ei kelpaa.');
    }
    $sarja = null;
    if ($moodi === 'hae') {
        if (!$rivi) {
            $vastaus = emitLukijaVastaus('ok', 'Uusi kortti.', [
                'emit' => $emit,
                'loytyi' => false,
                'kysy' => false,
            ]);
            if ($iltarastiKisa && function_exists('emitHenkilotLiita')) {
                $vastaus = emitHenkilotLiita($vastaus, $pdo, $emit, $kilpailu);
            }

            return $vastaus;
        }
        $vastaus = emitLukijaVastaus(
            'ok',
            'Kortti on jo kilpailussa.',
            emitLukijaHenkilo($rivi, $emit) + ['loytyi' => true]
        );
        if ($iltarastiKisa && function_exists('emitHenkilotLiita')) {
            $vastaus = emitHenkilotLiita($vastaus, $pdo, $emit, $kilpailu);
        }

        return emitLukijaTaydennaViesti($pdo, $kilpailu, $rivi, $vastaus);
    }

    if ($moodi === 'lue') {
        $leimatLue = emitLukijaPuhdistaLeimat($data['leimat'] ?? []);
        $oletus = (string) ($data['oletus_moodi'] ?? 'lahto');
        if (!in_array($oletus, ['lahto', 'maali'], true)) {
            $oletus = 'lahto';
        }
        $maastossa = $automaattiLuku || ($rivi && emitLukijaEmitKaytossa(
            array_key_exists('keskhyl', $rivi) ? (string) $rivi['keskhyl'] : null
        ));
        $toiminto = emitLukijaValitseToiminto(
            $rivi !== null,
            $rivi ? emitLukijaOnLahtenyt($rivi) : false,
            $oletus,
            $leimatLue,
            $iltarastiKisa,
            $maastossa
        );
        if ($toiminto === 'kysy') {
            $kysyViesti = $iltarastiKisa
                ? 'Uusi kortti. Luetaanko lähteväksi vai pois maastosta?'
                : (emitLukijaOnViesti($kilpailu)
                    ? 'Tuntematon kortti. Luetaanko 1. osuuden lähteväksi vai vaihtoon/maaliin?'
                    : 'Uusi kortti. Luetaanko lähteväksi vai maaliin?');
            if ($iltarastiKisa && $riviValmis) {
                $kysyViesti = 'Tämä kortti on jo luettu pois maastosta. Luetaanko lähteväksi vai pois maastosta?';
            }
            $vastaus = emitLukijaVastaus('ok', $kysyViesti, [
                'emit' => $emit,
                'loytyi' => false,
                'kysy' => true,
                'leimat' => $iltarastiKisa ? [] : $leimatLue,
            ]);
            if ($iltarastiKisa && function_exists('emitHenkilotLiita')) {
                $vastaus = emitHenkilotLiita($vastaus, $pdo, $emit, $kilpailu);
            }

            return $vastaus;
        }
        if ($toiminto === 'jo_lahdossa') {
            $henkilo = emitLukijaHenkilo($rivi, $emit);

            return emitLukijaTaydennaViesti($pdo, $kilpailu, $rivi, emitLukijaVastaus(
                'varoitus',
                emitLukijaOnViesti($kilpailu)
                    ? 'Jo lähdössä. Vaihda Vaihto / maali -tilaan, jos tämä on vaihto tai maali.'
                    : 'Jo lähdössä. Vaihda leimantarkastukseen, jos tämä on maali.',
                $henkilo + ['jo_lahtenyt' => true]
            ));
        }
        $moodi = $toiminto;
        $data['moodi'] = $moodi;
    }

    if ($moodi === 'lisaa' || $moodi === 'tallenna' || $moodi === 'siirra') {
        $sarja = emitLukijaSarja($pdo, $kilpailuId, (int) ($data['sarja_id'] ?? 0));
        if (!$sarja) {
            $sana = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) ? 'rata' : 'sarja';

            return emitLukijaVastaus('virhe', 'Valitse ' . $sana . '.');
        }
    }

    if ($moodi === 'siirra') {
        $vastaus = emitLukijaSiirra($pdo, $kilpailu, $emit, $sarja, $kayttajaId);
    } elseif ($moodi === 'lahto') {
        if (!$rivi) {
            $vastaus = emitLukijaVastaus('virhe', 'Ei ilmoittautuneena.', ['emit' => $emit]);
        } else {
            $vastaus = emitLukijaMerkitseLasna($pdo, $kilpailu, $rivi, $kayttajaId, $emit);
        }
    } elseif ($moodi === 'lisaa') {
        if ($rivi) {
            $henkilo = emitLukijaHenkilo($rivi, $emit);
            $sama = (int) $rivi['sarja_id'] === (int) $sarja['id'];
            $vastaus = emitLukijaVastaus(
                'varoitus',
                'On jo ' . (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) ? 'radalla' : 'sarjassa') . ' ' . $henkilo['sarja'] . '.',
                $henkilo + ['voi_siirtaa' => !$sama, 'luotu' => false]
            );
        } else {
            $nimet = emitLukijaNimi(
                (string) ($data['etunimi'] ?? ''),
                (string) ($data['sukunimi'] ?? ''),
                $emit
            );
            $seura = trim((string) ($data['seura'] ?? ''));
            $toive = isset($data['numero']) && $data['numero'] !== '' && $data['numero'] !== null
                ? (int) $data['numero']
                : null;
            if ($toive !== null && $toive <= 0) {
                return emitLukijaVastaus('virhe', 'Numero ei kelpaa.');
            }
            if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) && trim((string) ($data['sukunimi'] ?? '')) === '') {
                return emitLukijaVastaus('virhe', 'Anna vähintään sukunimi.');
            }
            $vastaus = emitLukijaLisaa($pdo, $kilpailu, $emit, $sarja, $nimet, $toive, $seura, $kayttajaId);
        }
    } elseif ($moodi === 'tallenna') {
        $etu = (string) ($data['etunimi'] ?? '');
        $suku = (string) ($data['sukunimi'] ?? '');
        $seura = trim((string) ($data['seura'] ?? ''));
        if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu) && trim($suku) === '' && !$rivi) {
            $vastaus = emitLukijaVastaus('virhe', 'Anna vähintään sukunimi.');
        } elseif ($rivi) {
            $vastaus = emitLukijaPaivita($pdo, $kilpailu, $rivi, $emit, $sarja, $etu, $suku, $seura, $kayttajaId);
        } else {
            $vastaus = emitLukijaLisaa(
                $pdo,
                $kilpailu,
                $emit,
                $sarja,
                emitLukijaNimi($etu, $suku, $emit),
                null,
                $seura,
                $kayttajaId
            );
        }
    } elseif ($moodi === 'paatos') {
        if (!$rivi) {
            $vastaus = emitLukijaVastaus('virhe', 'Korttia ei löydy kilpailusta.');
        } elseif (!function_exists('kisaOnIltarasti') || !kisaOnIltarasti($kilpailu)) {
            $vastaus = emitLukijaVastaus('virhe', 'Päätös on vain iltarasteille.');
        } else {
            $tulos = iltarastiAsetaPaatos($pdo, $kilpailu, $rivi, (string) ($data['paatos'] ?? $data['keskhyl'] ?? ''));
            $uusi = emitLukijaHaeId($pdo, (int) $rivi['id']) ?: $rivi;
            $henkilo = emitLukijaHenkilo($uusi, $emit);
            $vastaus = emitLukijaVastaus(
                !empty($tulos['ok']) ? 'ok' : 'virhe',
                (string) ($tulos['message'] ?? 'Päätös tallennettu.'),
                $henkilo + $tulos + ['radat' => emitLukijaRataLista($pdo, $kilpailuId)]
            );
        }
    } elseif ($moodi === 'vaihda_rata') {
        if (!$rivi) {
            $vastaus = emitLukijaVastaus('virhe', 'Korttia ei löydy kilpailusta.');
        } elseif (!function_exists('kisaOnIltarasti') || !kisaOnIltarasti($kilpailu)) {
            $vastaus = emitLukijaVastaus('virhe', 'Radan vaihto on vain iltarasteille.');
        } else {
            $vaihto = iltarastiVaihdaRata($pdo, $kilpailu, $rivi, (int) ($data['sarja_id'] ?? 0));
            if (empty($vaihto['ok'])) {
                $vastaus = emitLukijaVastaus('virhe', (string) ($vaihto['message'] ?? 'Radan vaihto epäonnistui.'));
            } else {
                $uusi = emitLukijaHaeId($pdo, (int) $rivi['id']) ?: $rivi;
                $nolla = isset($data['nolla_sec']) && $data['nolla_sec'] !== '' && $data['nolla_sec'] !== null
                    ? (int) $data['nolla_sec']
                    : null;
                $leimat = emitLukijaPuhdistaLeimat($data['leimat'] ?? []);
                if (!$leimat) {
                    $leimat = emitLukijaHaeLeimatKannasta($pdo, (int) $uusi['id']);
                }
                if (function_exists('pirilaTyhjennaSarjaRastitCache')) {
                    pirilaTyhjennaSarjaRastitCache();
                }
                if ($leimat && function_exists('emitLukijaKirjoitaValiajatLeimoista')) {
                    emitLukijaKirjoitaValiajatLeimoista($pdo, $uusi, $leimat);
                }
                $tark = emitLukijaLiitaTarkistus($pdo, $kilpailu, $uusi, $leimat, $nolla, $kayttajaId, null, true);
                $henkilo = emitLukijaHenkilo($uusi, $emit);
                $viesti = (string) ($vaihto['message'] ?? 'Rata vaihdettu.');
                if (!empty($tark['tarkistus_viesti'])) {
                    $viesti .= ' ' . $tark['tarkistus_viesti'];
                }
                $taso = (string) ($tark['taso_tarkistus'] ?? 'ok');
                $okRastit = !empty($tark['tarkistus']['ok']);
                $vastaus = emitLukijaVastaus(
                    $okRastit ? 'ok' : ($taso === 'virhe' ? 'virhe' : $taso),
                    $viesti,
                    $henkilo + $tark + $vaihto + [
                        'leimat' => $leimat,
                        'radat' => emitLukijaRataLista($pdo, $kilpailuId),
                        'esitys' => !$okRastit,
                    ]
                );
            }
        }
    } elseif ($moodi === 'maastoon' || $moodi === 'pois_maastosta') {
        if (!function_exists('kisaOnIltarasti') || !kisaOnIltarasti($kilpailu)) {
            $vastaus = emitLukijaVastaus('virhe', 'Nimellä kirjaus on vain iltarasteille.');
        } else {
            $ilmanAikaa = !empty($data['ilman_aikaa']) && $data['ilman_aikaa'] !== '0' && $data['ilman_aikaa'] !== 'false';
            if ($moodi === 'maastoon') {
                $tulos = iltarastiKirjaaMaastoon(
                    $pdo,
                    $kilpailu,
                    (int) ($data['sarja_id'] ?? 0),
                    (string) ($data['etunimi'] ?? ''),
                    (string) ($data['sukunimi'] ?? ''),
                    trim((string) ($data['seura'] ?? '')),
                    $emit > 0 ? $emit : 0,
                    (int) ($data['kilpailija_id'] ?? 0),
                    $ilmanAikaa
                );
            } else {
                $tulos = iltarastiKirjaaPois(
                    $pdo,
                    $kilpailu,
                    (int) ($data['sarja_id'] ?? 0),
                    (string) ($data['etunimi'] ?? ''),
                    (string) ($data['sukunimi'] ?? ''),
                    trim((string) ($data['seura'] ?? '')),
                    $emit > 0 ? $emit : 0,
                    (int) ($data['kilpailija_id'] ?? 0)
                );
            }
            $vastaus = emitLukijaVastaus(
                !empty($tulos['ok']) ? 'ok' : (string) ($tulos['taso'] ?? 'virhe'),
                (string) ($tulos['message'] ?? 'Kirjaus epäonnistui.'),
                $tulos + ['radat' => emitLukijaRataLista($pdo, $kilpailuId)]
            );
        }
    } elseif ($moodi === 'tarkista' || $moodi === 'ota_aika') {
        if (!$rivi) {
            $vastaus = emitLukijaVastaus('virhe', 'Korttia ei löydy kilpailusta.');
        } else {
            $nolla = isset($data['nolla_sec']) && $data['nolla_sec'] !== '' && $data['nolla_sec'] !== null
                ? (int) $data['nolla_sec']
                : null;
            if ($nolla !== null && ($nolla < 0 || $nolla > 8 * 86400)) {
                $nolla = null;
            }
            $leimat = emitLukijaPuhdistaLeimat($data['leimat'] ?? []);
            if (!$leimat) {
                $leimat = emitLukijaHaeLeimatKannasta($pdo, (int) $rivi['id']);
            }
            $napautus = null;
            if ($moodi === 'ota_aika') {
                $napautus = isset($data['maali_sec']) && $data['maali_sec'] !== '' && $data['maali_sec'] !== null
                    ? (int) $data['maali_sec']
                    : ((int) date('G') * 3600 + (int) date('i') * 60 + (int) date('s'));
            }
            $henkilo = emitLukijaHenkilo($rivi, $emit);
            $tark = emitLukijaLiitaTarkistus($pdo, $kilpailu, $rivi, $leimat, $nolla, $kayttajaId, $napautus, true);
            $viesti = (string) ($tark['tarkistus_viesti'] ?? 'Tarkistus tehty.');
            $taso = (string) ($tark['taso_tarkistus'] ?? 'ok');
            $vastaus = emitLukijaVastaus($taso === 'virhe' ? 'virhe' : $taso, $viesti, $henkilo + $tark + [
                'leimat' => $leimat,
            ]);
        }
    } else {
        if (
            !$rivi
            && $iltarastiKisa
            && function_exists('iltarastiVarmistaHenkilo')
            && trim((string) ($data['sukunimi'] ?? '')) !== ''
        ) {
            try {
                $luotu = iltarastiVarmistaHenkilo(
                    $pdo,
                    $kilpailu,
                    (int) ($data['sarja_id'] ?? 0),
                    (string) ($data['etunimi'] ?? ''),
                    (string) ($data['sukunimi'] ?? ''),
                    trim((string) ($data['seura'] ?? '')),
                    $emit,
                    (int) ($data['kilpailija_id'] ?? 0)
                );
                $rivi = emitLukijaHaeId($pdo, (int) ($luotu['id'] ?? 0)) ?: $luotu;
            } catch (Throwable $e) {
                return emitLukijaVastaus('virhe', $e->getMessage());
            }
        }
        if (!$rivi) {
            $vastaus = emitLukijaVastaus('virhe', 'Korttia ei löydy kilpailusta. Lisää hänet kilpailijaksi tai merkitse lähtöön.');
        } else {
            $nolla = isset($data['nolla_sec']) && $data['nolla_sec'] !== '' && $data['nolla_sec'] !== null
                ? (int) $data['nolla_sec']
                : null;
            if ($nolla !== null && ($nolla < 0 || $nolla > 8 * 86400)) {
                $nolla = null;
            }
            $leimat = emitLukijaPuhdistaLeimat($data['leimat'] ?? []);
            $naytto = [];
            if ($leimat) {
                $naytto = emitLukijaTallennaLeimat($pdo, $kilpailu, $rivi, $leimat, $nolla, $kayttajaId);
                $tallennetut = emitLukijaHaeLeimatKannasta($pdo, (int) $rivi['id']);
                if ($tallennetut) {
                    $leimat = $tallennetut;
                }
            } elseif ($iltarastiKisa) {
                $leimat = emitLukijaHaeLeimatKannasta($pdo, (int) $rivi['id']);
            }
            $henkilo = emitLukijaHenkilo($rivi, $emit);
            if (!$naytto && !$iltarastiKisa) {
                $vastaus = emitLukijaVastaus(
                    'varoitus',
                    'Kortti tunnistettu. Rastileimoja ei tullut. Lukijan koodi 250 tai 253 tuo leimat.',
                    $henkilo + ['leimat' => []]
                );
            } else {
                $tark = emitLukijaLiitaTarkistus($pdo, $kilpailu, $rivi, $leimat, $nolla, $kayttajaId, null, true);
                $viesti = $naytto
                    ? (count($naytto) . ' leimaa tallennettu.')
                    : ($leimat
                        ? (count($leimat) . ' leimaa kannasta.')
                        : 'Leimoja ei tullut kortilta.');
                if (!empty($tark['tarkistus_viesti'])) {
                    $viesti .= ' ' . $tark['tarkistus_viesti'];
                }
                $taso = (string) ($tark['taso_tarkistus'] ?? 'ok');
                if ($taso === 'virhe') {
                    $taso = 'varoitus';
                }
                $vastaus = emitLukijaVastaus(
                    $taso,
                    $viesti,
                    $henkilo + $tark + [
                        'leimat' => $naytto ?: $leimat,
                        'nolla' => $nolla !== null,
                    ]
                );
            }
        }
    }

    if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)) {
        $vastaus['iltarasti'] = true;
        $vastaus['radat'] = $vastaus['radat'] ?? emitLukijaRataLista($pdo, $kilpailuId);
        if (!empty($vastaus['tarkistus']['ok'])) {
            $vastaus['esitys'] = false;
        } elseif (!empty($vastaus['esitys']) || ((string) ($vastaus['keskhyl'] ?? '') === 'E')) {
            $vastaus['esitys'] = true;
        }
        $kidLaina = (int) ($vastaus['kilpailija_id'] ?? 0);
        if ($kidLaina > 0 && array_key_exists('laina', $data) && function_exists('iltarastiAsetaLaina')) {
            $onLaina = !empty($data['laina']) && $data['laina'] !== '0' && $data['laina'] !== 'false';
            iltarastiAsetaLaina($pdo, $kidLaina, $onLaina);
            $vastaus['laina'] = $onLaina;
        }
    }

    $taso = (string) ($vastaus['taso'] ?? 'virhe');
    $koodi = $taso === 'ok' ? 'ok' : ($taso === 'varoitus' ? 'varoitus' : 'ei_loytynyt');
    if ($taso === 'virhe' && !empty($vastaus['kilpailija_id'])) {
        $koodi = 'virhe';
    }
    if (($moodi === 'tallenna' || $moodi === 'lisaa') && !empty($vastaus['kilpailija_id'])) {
        $tarkoitus = (string) ($data['tarkoitus'] ?? '');
        $kidTallenna = (int) $vastaus['kilpailija_id'];
        if ($tarkoitus === 'lahto' || (!empty($vastaus['luotu']) && $tarkoitus !== 'maali')) {
            $lahtoAt = emitLukijaKirjaaNollausLahto(
                $pdo,
                $kidTallenna,
                (string) ($vastaus['pirila_lahto_at'] ?? '')
            );
            if ($lahtoAt !== null) {
                $vastaus['pirila_lahto_at'] = $lahtoAt;
                $vastaus['lahto_at'] = $lahtoAt;
                $vastaus['jo_lahtenyt'] = true;
                $vastaus['message'] = trim((string) ($vastaus['message'] ?? '') . ' Lähtö ' . substr($lahtoAt, 11, 8) . '.');
            }
        }
        $nolla = isset($data['nolla_sec']) && $data['nolla_sec'] !== '' && $data['nolla_sec'] !== null
            ? (int) $data['nolla_sec']
            : null;
        if ($nolla !== null && ($nolla < 0 || $nolla > 8 * 86400)) {
            $nolla = null;
        }
        $leimat = emitLukijaPuhdistaLeimat($data['leimat'] ?? []);
        $kirjaaMaali = $tarkoitus === 'maali' || ($tarkoitus !== 'lahto' && $leimat && !empty($vastaus['luotu']));
        if ($kirjaaMaali) {
            $leimaRivi = $emit > 0
                ? emitLukijaHaeEmit($pdo, $kilpailuId, $emit, $iltarastiKisa)
                : emitLukijaHaeId($pdo, $kidTallenna);
            if ($leimaRivi) {
                $naytto = [];
                if ($leimat) {
                    $naytto = emitLukijaTallennaLeimat($pdo, $kilpailu, $leimaRivi, $leimat, $nolla, $kayttajaId);
                    $tallennetut = emitLukijaHaeLeimatKannasta($pdo, (int) $leimaRivi['id']);
                    if ($tallennetut) {
                        $leimat = $tallennetut;
                    }
                    $vastaus['leimat'] = $naytto;
                    if ($naytto) {
                        $vastaus['message'] = trim((string) ($vastaus['message'] ?? ''))
                            . ' ' . count($naytto) . ' leimaa tallennettu.';
                    }
                } else {
                    $leimat = emitLukijaHaeLeimatKannasta($pdo, (int) $leimaRivi['id']);
                    if ($leimat) {
                        $vastaus['leimat'] = $leimat;
                    }
                }
                if ($tarkoitus === 'maali' || $naytto || $leimat) {
                    $tark = emitLukijaLiitaTarkistus($pdo, $kilpailu, $leimaRivi, $leimat, $nolla, $kayttajaId, null, true);
                    if ($tark) {
                        $vastaus = array_merge($vastaus, $tark);
                        if (!empty($tark['tarkistus_viesti'])) {
                            $vastaus['message'] = trim((string) $vastaus['message'] . ' ' . $tark['tarkistus_viesti']);
                        }
                    }
                }
            }
        }
    }
    $laji = '';
    if (!empty($vastaus['kilpailija_id']) && ($taso === 'ok' || $taso === 'varoitus')) {
        if ($moodi === 'lahto') {
            $laji = 'lasna';
        } elseif ($moodi === 'maali' || $moodi === 'tarkista' || $moodi === 'ota_aika') {
            $laji = 'maali';
        } elseif (!empty($vastaus['luotu'])) {
            $laji = 'lisatty';
        }
        if ($laji !== '') {
            $kello = date('H:i:s');
            $vastaus['luku_aika'] = $kello;
            $vastaus['luku_laji'] = $laji;
            $otsikko = $laji === 'maali' ? 'Luettu maaliaika' : ($laji === 'lisatty' ? 'Lisätty lukijalla' : 'Lukuaika');
            $viestiNyt = trim((string) ($vastaus['message'] ?? ''));
            if ($viestiNyt !== '') {
                $vastaus['message'] = $viestiNyt . ' ' . $otsikko . ' ' . $kello . '.';
            }
        }
    }
    if (function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu)) {
        if (!empty($vastaus['tarkistus']['ok'])) {
            $vastaus['esitys'] = false;
            unset($vastaus['arvaus']);
        } elseif (!empty($vastaus['esitys']) || ((string) ($vastaus['keskhyl'] ?? '') === 'E')) {
            $vastaus['esitys'] = true;
            $arvausLeimat = $vastaus['leimat'] ?? emitLukijaPuhdistaLeimat($data['leimat'] ?? []);
            if (is_array($arvausLeimat) && $arvausLeimat && function_exists('iltarastiArvaaRata')) {
                $nykySarja = (int) ($vastaus['sarja_id'] ?? 0);
                if ($nykySarja < 1 && is_array($rivi)) {
                    $nykySarja = (int) ($rivi['sarja_id'] ?? 0);
                }
                $arvaus = iltarastiArvaaRata(
                    $pdo,
                    $kilpailuId,
                    $nykySarja,
                    $arvausLeimat
                );
                if ($arvaus) {
                    $vastaus['arvaus'] = $arvaus;
                }
            }
        }
    }
    emitLukijaLokita(
        $pdo,
        $kilpailuId,
        isset($vastaus['kilpailija_id']) ? (int) $vastaus['kilpailija_id'] : null,
        $emit,
        $koodi,
        (string) ($vastaus['message'] ?? ''),
        $vastaus,
        $kayttajaId
    );
    if ($iltarastiKisa && in_array($taso, ['ok', 'varoitus'], true) && function_exists('rastilippuJonota')) {
        rastilippuJonota($pdo, $kilpailu);
    }
    if ($iltarastiKisa && $emit > 0 && in_array($taso, ['ok', 'varoitus'], true)
        && function_exists('emitHenkilotTallenna')) {
        $sukuT = trim((string) ($vastaus['sukunimi'] ?? ''));
        if ($sukuT !== '') {
            emitHenkilotTallenna(
                $pdo,
                $emit,
                (string) ($vastaus['etunimi'] ?? ''),
                $sukuT,
                (string) ($vastaus['seura'] ?? ''),
                (int) ($kilpailu['seura_id'] ?? 0),
                $kayttajaId
            );
        }
        if (empty($vastaus['ehdotukset']) && function_exists('emitHenkilotLiita')) {
            $vastaus = emitHenkilotLiita($vastaus, $pdo, $emit, $kilpailu);
        }
    }

    return emitLukijaTaydennaViesti($pdo, $kilpailu, $rivi, $vastaus);
}
