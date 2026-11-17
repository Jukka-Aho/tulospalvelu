<?php

/**
 * Yhtenäinen aikaformaatti (Pirilä):
 * - alle 60 s → "40"
 * - alle 60 min → "2:32" (ei etunollaa minuuteissa)
 * - ≥ 60 min → "1:52:10" (tunnit:minuutit:sekunnit)
 * - ≥ 24 h → "1d 23:59:59" (päivät + kellonaika vuorokauden sisällä)
 */
function formatAikaSec($sec): string
{
    if ($sec === null || $sec === '') {
        return '';
    }

    $sec = max(0, (int) $sec);
    if ($sec < 60) {
        return (string) $sec;
    }

    if ($sec >= 86400) {
        $d = intdiv($sec, 86400);
        $rest = $sec % 86400;
        $h = intdiv($rest, 3600);
        $m = intdiv($rest % 3600, 60);
        $s = $rest % 60;

        return sprintf('%dd %d:%02d:%02d', $d, $h, $m, $s);
    }

    if ($sec >= 3600) {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    $m = intdiv($sec, 60);
    $s = $sec % 60;

    return sprintf('%d:%02d', $m, $s);
}

/** Alias: sama kuin formatAikaSec. */
function formatAikaSecPirila($sec): string
{
    return formatAikaSec($sec);
}

/** Punainen ruksi (sulje / poistu). */
function jahRuksiSvg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
}

/**
 * Kellonaika siinä maassa, jossa kisa pidetään.
 * Palvelin on UTC:ssa; Suomi on oletus, kunnes kisalle on oma vyöhyke.
 */
function paikallinenAikaNyt(?string $maa = null): string
{
    $koodi = strtoupper(trim((string) $maa));
    $vyohyke = match ($koodi) {
        'SE', 'SWE', 'RUOTSI' => 'Europe/Stockholm',
        'NO', 'NOR', 'NORJA' => 'Europe/Oslo',
        'EE', 'EST', 'VIRO' => 'Europe/Tallinn',
        'LV', 'LVA', 'LATVIA' => 'Europe/Riga',
        'LT', 'LTU', 'LIETTUA' => 'Europe/Vilnius',
        'DK', 'DNK', 'TANSKA' => 'Europe/Copenhagen',
        'DE', 'DEU', 'SAKSA' => 'Europe/Berlin',
        'GB', 'UK', 'GBR', 'ENGLANTI' => 'Europe/London',
        default => 'Europe/Helsinki',
    };

    return (new DateTimeImmutable('now', new DateTimeZone($vyohyke)))->format('Y-m-d H:i:s');
}

/**
 * Ero keulaan: '+1:02:05' tai tyhjä (kärki / ei aikaa).
 */
function formatEroKeulaan($aikaSec, $karkiSec): string
{
    if ($aikaSec === null || $aikaSec === '' || $karkiSec === null || $karkiSec === '') {
        return '';
    }

    $ero = (int) $aikaSec - (int) $karkiSec;
    if ($ero <= 0) {
        return '';
    }

    return '+' . formatAikaSecPirila($ero);
}

/**
 * Sarjan kärkiaika (ensimmäinen maalissa, ajat järjestyksessä).
 */
function sarjanKarkiaika(array $maalissa): ?int
{
    foreach ($maalissa as $r) {
        $sec = $r['maali_aika_sec'] ?? $r['aika_sec'] ?? null;
        if ($sec !== null && $sec !== '') {
            return (int) $sec;
        }
    }

    return null;
}

/** Normalisoi sarjan matkan tallennukseen: vain numero (esim. "10", "21.1"). */
function normalisoiSarjaMatka(?string $matka): ?string
{
    $matka = trim((string) $matka);
    if ($matka === '') {
        return null;
    }
    // Salli pilkku tai piste desimaalina; poista km/m yms.
    $matka = str_ireplace(['km', 'kilometriä', 'kilometria', 'kilometri'], '', $matka);
    $matka = str_replace(',', '.', $matka);
    $matka = preg_replace('/[^0-9.]/', '', $matka) ?? '';
    $matka = trim($matka, '.');
    if ($matka === '' || !is_numeric($matka)) {
        return null;
    }
    $n = (float) $matka;
    if ($n <= 0) {
        return null;
    }
    // Säilytä järkevä tarkkuus (max 2 desimaalia), poista turhat nollat
    $fmt = rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');

    return $fmt !== '' ? $fmt : null;
}

/** Näyttömuoto matkalle: "10 km". */
function formatSarjaMatka(?string $matka): string
{
    $norm = normalisoiSarjaMatka($matka);
    if ($norm === null) {
        // Vanha vapaa teksti ilman numeroa — näytä sellaisenaan
        $raw = trim((string) $matka);
        return $raw;
    }

    return str_replace('.', ',', $norm) . ' km';
}

/** Vertailuavain matkalle (ryhmittelyyn), esim. "10". */
function sarjaMatkaAvain(?string $matka): string
{
    return (string) (normalisoiSarjaMatka($matka) ?? '');
}

/** Sarjaotsikko + matka, esim. "H21 · 10 km". */
function sarjaOtsikkoMatka(string $nimi, ?string $matka): string
{
    $fmt = formatSarjaMatka($matka);
    if ($fmt === '') {
        return $nimi;
    }

    return $nimi . ' · ' . $fmt;
}

/**
 * Sukunimen 3 ensimmäistä kirjainta (mobiili sticky-sarake).
 * Sukunimi-kenttä tai nimen ensimmäinen sana (Sukunimi Etunimi).
 */
function sukunimiLyhenne(?string $sukunimi, ?string $nimi = null): string
{
    $s = trim((string) $sukunimi);
    if ($s === '') {
        $nimi = trim((string) $nimi);
        if ($nimi !== '') {
            $osat = preg_split('/\s+/u', $nimi, 2) ?: [];
            $s = trim((string) ($osat[0] ?? ''));
        }
    }
    if ($s === '') {
        return '';
    }

    return mb_substr($s, 0, 3, 'UTF-8');
}

/**
 * Tuloslistojen näyttönimi: Sukunimi Etunimi (suomalainen kisakäytäntö).
 * Fallback: vanha nimi-kenttä.
 */
function yhdistaKilpailijaNimi(?string $etunimi, ?string $sukunimi, ?string $nimi = null): string
{
    $sukunimi = trim((string) $sukunimi);
    $etunimi = trim((string) $etunimi);

    if ($sukunimi !== '' || $etunimi !== '') {
        return trim($sukunimi . ' ' . $etunimi);
    }

    $nimi = trim((string) $nimi);

    return $nimi !== '' ? $nimi : 'Nimetön';
}

/** Näyttönimi rivistä (tuloslistat / TV / API). */
function kilpailijaNimiRivista(array $rivi): string
{
    return yhdistaKilpailijaNimi(
        $rivi['etunimi'] ?? null,
        $rivi['sukunimi'] ?? null,
        $rivi['nimi'] ?? null
    );
}

/**
 * Normalisoi etu-/sukunimi + synkkaa yhdistetty nimi-kenttä.
 * @return array{etunimi:?string,sukunimi:?string,nimi:string}
 */
function normalisoiKilpailijaNimet(string $etunimi, string $sukunimi): array
{
    $etunimi = trim($etunimi);
    $sukunimi = trim($sukunimi);

    return [
        'etunimi' => $etunimi !== '' ? $etunimi : null,
        'sukunimi' => $sukunimi !== '' ? $sukunimi : null,
        'nimi' => yhdistaKilpailijaNimi($etunimi, $sukunimi),
    ];
}

/** Jaa vanha yhden kentän nimi etu-/sukunimeksi (Etunimi Sukunimi). */
function jaaNimiKenttiin(string $nimi): array
{
    $nimi = trim($nimi);
    if ($nimi === '') {
        return ['etunimi' => '', 'sukunimi' => ''];
    }
    $osat = preg_split('/\s+/u', $nimi, 2);
    if (count($osat) === 1) {
        return ['etunimi' => '', 'sukunimi' => $osat[0]];
    }

    return ['etunimi' => $osat[0], 'sukunimi' => $osat[1]];
}

function generoiApiToken()
{
    return bin2hex(random_bytes(32));
}

function haeSarjanLahtoId(PDO $pdo, $sarja_id)
{
    $stmt = $pdo->prepare('SELECT lahto_id FROM sarjat WHERE id = ?');
    $stmt->execute([$sarja_id]);
    $sarja = $stmt->fetch(PDO::FETCH_ASSOC);

    return $sarja['lahto_id'] ?? null;
}

function laskeAikaSec(PDO $pdo, $sarja_id, $maaliaika, ?string $lahtoAt = null)
{
    $maaliNorm = parseDatetimeSec(is_string($maaliaika) ? $maaliaika : '');
    if ($maaliNorm === null) {
        $tsMaali = strtotime((string) $maaliaika);
    } else {
        $tsMaali = strtotime($maaliNorm);
    }

    $tsLahto = false;
    $henkilo = trim((string) $lahtoAt);
    if ($henkilo !== '') {
        $henkiloNorm = parseDatetimeSec($henkilo);
        $tsLahto = strtotime($henkiloNorm ?? $henkilo);
    }
    if ($tsLahto === false) {
        $lahto_id = haeSarjanLahtoId($pdo, $sarja_id);
        if (!$lahto_id) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT lahtoaika FROM lahdot WHERE id = ?');
        $stmt->execute([$lahto_id]);
        $lahto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lahto || !$lahto['lahtoaika']) {
            return null;
        }
        $tsLahto = strtotime($lahto['lahtoaika']);
    }

    if ($tsMaali === false || $tsLahto === false) {
        return null;
    }

    $sec = $tsMaali - $tsLahto;

    // Negatiivinen: kokeile +1 pv (yleinen päivämääräheitto leimassa vs. lähtö).
    if ($sec < 0) {
        $secPlus = $sec + 86400;
        if ($secPlus >= 0 && $secPlus < 24 * 3600) {
            return (int) $secPlus;
        }

        return null;
    }

    return (int) $sec;
}

/**
 * Online-tuloksen aika: netto (aika_sec) tai maaliajan kellonaika jos netto puuttuu.
 */
function formatLiveTulosAika(array $rivi, bool $pirila = false): string
{
    $sec = $rivi['maali_aika_sec'] ?? $rivi['aika_sec'] ?? null;
    if ($sec !== null && $sec !== '') {
        return $pirila ? formatAikaSecPirila($sec) : formatAikaSec($sec);
    }

    $maali = trim((string) ($rivi['maaliaika'] ?? ''));
    if ($maali === '') {
        return '';
    }

    if (preg_match('/(\d{2}:\d{2}:\d{2})/', $maali, $m)) {
        return $m[1];
    }
    if (preg_match('/(\d{2}:\d{2})/', $maali, $m)) {
        return $m[1];
    }

    return $maali;
}

/**
 * Muunna datetime-local / ISO / teksti → 'Y-m-d H:i:s' (Europe/Helsinki).
 */
function parseDatetimeSec(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    // ISO UTC (mobiili toISOString): 2026-07-17T10:38:00.000Z
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    $value = str_replace('T', ' ', $value);

    // Poista millisekunnit jos on
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\d+/', $value, $m)) {
        $value = $m[1];
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

/** Arvo datetime-local -kenttään (sekunnit mukana). */
function datetimeLocalSec(?string $dbValue): string
{
    if ($dbValue === null || $dbValue === '') {
        return '';
    }

    $ts = strtotime($dbValue);

    if ($ts === false) {
        return '';
    }

    return date('Y-m-d\TH:i:s', $ts);
}

/**
 * Jaa DATETIME kenttiin (pvm + tt:mm:ss). Selainten datetime-local näyttää usein vain minuutit.
 *
 * @return array{pvm:string,h:string,m:string,s:string,local:string}
 */
function datetimeOsat(?string $dbValue): array
{
    $local = datetimeLocalSec($dbValue);
    $out = ['pvm' => '', 'h' => '', 'm' => '', 's' => '', 'local' => $local];
    if ($local !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})$/', $local, $lm)) {
        $out['pvm'] = $lm[1];
        $out['h'] = $lm[2];
        $out['m'] = $lm[3];
        $out['s'] = $lm[4];
    } elseif ($local !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/', $local, $lm)) {
        $out['pvm'] = $lm[1];
        $out['h'] = $lm[2];
        $out['m'] = $lm[3];
        $out['s'] = '00';
        $out['local'] = $lm[1] . 'T' . $lm[2] . ':' . $lm[3] . ':00';
    }

    return $out;
}

/**
 * Muunna 'H:MM:SS' / 'MM:SS' / sekunnit → int sekunnit.
 */
function parseAikaSecSyote(?string $value): ?int
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d+$/', $value)) {
        return (int) $value;
    }

    if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d)$/', $value, $m)) {
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }

    if (preg_match('/^(\d+):([0-5]?\d)$/', $value, $m)) {
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    return null;
}

/**
 * Muodosta maaliaika (kellonaika) lähdöstä + netto sekunteina.
 */
function muodostaMaaliaikaLahtoPlusNetto(?string $lahtoAt, ?int $aikaSec): ?string
{
    if ($lahtoAt === null || $lahtoAt === '' || $aikaSec === null || $aikaSec < 0) {
        return null;
    }

    $ts = strtotime($lahtoAt);

    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts + $aikaSec);
}

/**
 * Tallenna kilpailijan ajat.
 *
 * Normaali kilpailu: maaliaika = kellonaika maalissa → netto lasketaan lähdöstä.
 * Pirilä-kilpailu: netto (aika_sec) tulee Pirilästä → maaliaika = lähtö + netto.
 */
function tallennaKilpailijanAjat(
    PDO $pdo,
    int $kilpailija_id,
    int $sarja_id,
    ?string $lahtoAt = null,
    ?string $maaliAt = null,
    ?int $aikaSec = null,
    bool $poistaMaali = false,
    bool $paivitaLahto = false,
    bool $kaytaKasinetto = false,
    bool $pirilaTila = false
): void {
    if ($paivitaLahto) {
        $upd = $pdo->prepare('UPDATE kilpailijat SET pirila_lahto_at = ? WHERE id = ?');
        $upd->execute([$lahtoAt, $kilpailija_id]);
    }

    if ($poistaMaali) {
        $meta = $pdo->prepare('
            SELECT k.kilpailu_id, k.numero, a.id AS ajat_id, a.maaliaika
            FROM kilpailijat k
            LEFT JOIN ajat a ON a.kilpailija_id = k.id
            WHERE k.id = ?
            LIMIT 1
        ');
        $meta->execute([$kilpailija_id]);
        $info = $meta->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdo->prepare('DELETE FROM ajat WHERE kilpailija_id = ?')->execute([$kilpailija_id]);

        $maaliJonoFile = __DIR__ . '/maali_jono.php';
        if (is_readable($maaliJonoFile)) {
            require_once $maaliJonoFile;
            if (function_exists('maaliJonoPoistaKilpailijanMaali') && !empty($info['kilpailu_id'])) {
                maaliJonoPoistaKilpailijanMaali(
                    $pdo,
                    (int) $info['kilpailu_id'],
                    $kilpailija_id,
                    !empty($info['ajat_id']) ? (int) $info['ajat_id'] : null,
                    isset($info['numero']) && $info['numero'] !== null && $info['numero'] !== ''
                        ? (int) $info['numero']
                        : null
                );
            }
        }

        $auditFile = __DIR__ . '/ajanotto_audit.php';
        if (is_readable($auditFile) && !empty($info['kilpailu_id'])) {
            require_once $auditFile;
            ajanottoAuditKirjaa($pdo, (int) $info['kilpailu_id'], 'aika_poisto', [
                'kohde' => 'ajat',
                'kohde_id' => !empty($info['ajat_id']) ? (int) $info['ajat_id'] : null,
                'numero' => isset($info['numero']) ? (int) $info['numero'] : null,
                'kilpailija_id' => $kilpailija_id,
                'vanha' => $info['maaliaika'] ?? null,
            ]);
        }

        return;
    }

    if ($maaliAt !== null) {
        $norm = parseDatetimeSec($maaliAt);
        if ($norm !== null) {
            $maaliAt = $norm;
        }
    }

    if ($maaliAt === null && $aikaSec === null) {
        return;
    }

    $lahtoRef = $lahtoAt ?: haeSarjanLahtoaika($pdo, $sarja_id);

    if ($pirilaTila) {
        // Pirilä: netto on ensisijainen. Maaliaika = kellonaika (lähtö + netto).
        if ($kaytaKasinetto && $aikaSec !== null) {
            $maaliAt = muodostaMaaliaikaLahtoPlusNetto($lahtoRef, $aikaSec) ?? $maaliAt;
        } elseif ($aikaSec !== null) {
            $maaliAt = muodostaMaaliaikaLahtoPlusNetto($lahtoRef, $aikaSec) ?? $maaliAt;
        } elseif ($maaliAt !== null) {
            // Älä laske nettoa maaliajasta – säilytä Pirilän netto.
            $stmt = $pdo->prepare('SELECT aika_sec FROM ajat WHERE kilpailija_id = ?');
            $stmt->execute([$kilpailija_id]);
            $existing = $stmt->fetchColumn();
            $aikaSec = $existing !== false && $existing !== null ? (int) $existing : null;
        }
    } else {
        // Normaali: maaliaika = ajanottohetki → netto lasketaan lähdöstä.
        if ($maaliAt === null && $aikaSec !== null) {
            $maaliAt = muodostaMaaliaikaLahtoPlusNetto($lahtoRef, $aikaSec) ?? date('Y-m-d H:i:s');
        }

        if (!$kaytaKasinetto || $aikaSec === null) {
            $laskettu = laskeAikaSec($pdo, $sarja_id, $maaliAt);

            if ($laskettu !== null) {
                $aikaSec = $laskettu;
            } elseif ($lahtoRef !== null && $maaliAt !== null) {
                $sec = strtotime($maaliAt) - strtotime($lahtoRef);
                $aikaSec = $sec >= 0 ? (int) $sec : null;
            }
        }
    }

    $chk = $pdo->prepare('SELECT id FROM ajat WHERE kilpailija_id = ?');
    $chk->execute([$kilpailija_id]);
    $olemassaId = $chk->fetchColumn();

    if ($olemassaId) {
        $ajatId = (int) $olemassaId;
        $pdo->prepare('UPDATE ajat SET maaliaika = ?, aika_sec = ? WHERE id = ?')
            ->execute([$maaliAt, $aikaSec, $ajatId]);
        $tapahtuma = 'aika_paivitys';
    } else {
        $pdo->prepare('INSERT INTO ajat (kilpailija_id, maaliaika, aika_sec) VALUES (?, ?, ?)')
            ->execute([$kilpailija_id, $maaliAt, $aikaSec]);
        $ajatId = (int) $pdo->lastInsertId();
        $tapahtuma = 'aika_lisays';
    }

    $meta = $pdo->prepare('SELECT kilpailu_id, numero FROM kilpailijat WHERE id = ?');
    $meta->execute([$kilpailija_id]);
    $info = $meta->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($info['kilpailu_id'])) {
        $kid = (int) $info['kilpailu_id'];
        $maaliJonoFile = __DIR__ . '/maali_jono.php';
        if ($maaliAt !== null && is_readable($maaliJonoFile)) {
            require_once $maaliJonoFile;
            if (function_exists('maaliJonoPaivitaLeimaAjasta')) {
                maaliJonoPaivitaLeimaAjasta($pdo, $kid, $ajatId, $maaliAt);
            }
        }
        $auditFile = __DIR__ . '/ajanotto_audit.php';
        if (is_readable($auditFile)) {
            require_once $auditFile;
            ajanottoAuditKirjaa($pdo, $kid, $tapahtuma, [
                'kohde' => 'ajat',
                'numero' => isset($info['numero']) ? (int) $info['numero'] : null,
                'kilpailija_id' => $kilpailija_id,
                'uusi' => $maaliAt,
                'lisatieto' => $aikaSec !== null ? ('aika_sec=' . $aikaSec) : null,
            ]);
        }
    }
}

/**
 * Laske uudelleen kaikkien kilpailijoiden nettoajat sarjan lähdöstä.
 */
function uudelleenLaskeSarjanAjat(PDO $pdo, int $kilpailu_id): int
{
    $kisa = $pdo->prepare('SELECT kisa_tyyppi, pirila_synkka FROM kilpailut WHERE id = ?');
    $kisa->execute([$kilpailu_id]);
    $k = $kisa->fetch(PDO::FETCH_ASSOC) ?: [];

    // Pirilä-kilpailuissa aika_sec tulee suoraan Pirilästä – älä ylikirjoita lähdöllä
    if (($k['kisa_tyyppi'] ?? '') === 'pirila' || !empty($k['pirila_synkka'])) {
        return 0;
    }

    $stmt = $pdo->prepare('
        SELECT a.id, a.maaliaika, k.sarja_id, k.pirila_lahto_at
        FROM ajat a
        JOIN kilpailijat k ON k.id = a.kilpailija_id
        WHERE k.kilpailu_id = ?
    ');
    $stmt->execute([$kilpailu_id]);
    $upd = $pdo->prepare('UPDATE ajat SET aika_sec = ? WHERE id = ?');
    $n = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $henkilo = trim((string) ($r['pirila_lahto_at'] ?? ''));
        $sec = laskeAikaSec(
            $pdo,
            (int) $r['sarja_id'],
            $r['maaliaika'],
            $henkilo !== '' ? $henkilo : null
        );
        $upd->execute([$sec, $r['id']]);
        $n++;
    }

    return $n;
}

/**
 * Sarjan lähtöaika (lahdot.lahtoaika) tai null.
 */
function haeSarjanLahtoaika(PDO $pdo, int $sarja_id): ?string
{
    $stmt = $pdo->prepare('
        SELECT l.lahtoaika
        FROM sarjat s
        JOIN lahdot l ON l.id = s.lahto_id
        WHERE s.id = ?
        LIMIT 1
    ');
    $stmt->execute([$sarja_id]);
    $v = $stmt->fetchColumn();

    return $v !== false && $v !== null && $v !== '' ? (string) $v : null;
}

function csrfToken()
{
    if (!function_exists('jahAloitaSessio')) {
        require_once __DIR__ . '/auth.php';
    } else {
        jahAloitaSessio();
    }

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function tarkistaCsrf($token)
{
    return isset($_SESSION['csrf'])
        && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

function csrfKentta()
{
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

    return '<input type="hidden" name="csrf" value="' . $token . '">';
}

function varmistaApiToken(PDO $pdo, $kilpailu_id, $token)
{
    if (empty($kilpailu_id) || empty($token)) {
        return false;
    }

    $stmt = $pdo->prepare('
        SELECT id
        FROM kilpailut
        WHERE id = ?
        AND api_token = ?
        LIMIT 1
    ');
    $stmt->execute([$kilpailu_id, $token]);

    return (bool) $stmt->fetch();
}

function haeKilpailu(PDO $pdo, $kilpailu_id)
{
    $stmt = $pdo->prepare('SELECT * FROM kilpailut WHERE id = ?');
    $stmt->execute([$kilpailu_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function julkinenLinkit($kilpailu_id, $public = false)
{
    $id = (int) $kilpailu_id;
    $q = '?kilpailu_id=' . $id;
    $prefix = '/public/';
    $kuori = '/public/kilpailu.php' . $q;

    return [
        'kilpailu' => $kuori,
        'ajanotto' => $prefix . 'mobiili_ajanotto.php' . $q,
        'mobiili' => $prefix . 'mobiili_ajanotto.php' . $q,
        'nappi' => $prefix . 'mobiili_ajanotto.php' . $q . '&tila=nappi',
        'numerot' => $prefix . 'mobiili_ajanotto.php' . $q . '&tila=numerot',
        'kouru' => $prefix . 'mobiili_ajanotto.php' . $q . '&tila=numerot',
        'lahtoluennat' => $prefix . 'lahtoluennat.php' . $q,
        'tulokset' => $kuori . '&osio=online',
        // Online avataan kilpailukuoreen (yksi sivu + oikeusvalikot); suora seuranta vain embed/live.
        'seuranta' => $kuori . '&osio=online',
        'seuranta_suora' => $prefix . 'seuranta.php' . $q,
        'valiajat' => $prefix . 'valiajat.php' . $q,
        'tv' => $prefix . 'tv.php' . $q,
        'live' => $prefix . 'live.php' . $q,
        'ilmoittautuminen' => $prefix . 'ilmoittautuminen.php' . $q,
        'ilmoittautuneet' => $prefix . 'ilmoittautuneet.php' . $q,
        'paikanpaa_ilmo' => $prefix . 'paikanpaa_ilmo.php' . $q,
        'paikanpaa' => '/admin/paikanpaa.php',
        'arvonta' => $prefix . 'arvonta.php' . $q,
        'lahtolista' => $prefix . 'lahtolista.php' . $q,
        'ampuma' => $prefix . 'ampumapaikka.php' . $q,
        'emit_lukija' => $prefix . 'emit_lukija.php' . $q,
        'viesti_lukija' => $prefix . 'viesti_lukija.php' . $q,
        'leimantarkastus' => $prefix . 'leimantarkastus.php' . $q,
        'automaatti' => $prefix . 'automaatti.php' . $q,
        'mobiili_kilpailija' => $prefix . 'mobiili_kilpailija.php' . $q,
        'emit_leimat' => $prefix . 'emit_leimat.php' . $q,
        'nimenhuuto' => $prefix . 'nimenhuuto.php' . $q,
        'lahtokello' => $prefix . 'lahtokello.php' . $q,
    ];
}

/**
 * Kilpailukuoren URL (hub tai online-osio).
 *
 * @param array<string, scalar|null> $extra Lisäquery (esim. sarja, matka)
 */
function kilpailuKuoriUrl(int $kilpailu_id, string $osio = '', array $extra = [], bool $public = true): string
{
    $params = array_merge(['kilpailu_id' => (int) $kilpailu_id], $extra);
    $osio = trim($osio);
    if ($osio !== '' && $osio !== 'hub') {
        $params['osio'] = $osio;
    }
    $base = $public ? 'kilpailu.php' : '../public/kilpailu.php';

    return $base . '?' . http_build_query($params);
}

/** Onko ilmoittautuminen merkitty käyttöön (lippu), riippumatta tilasta. */
function ilmoittautuminenKaytossa(array $kilpailu): bool
{
    return !empty($kilpailu['ilmoittautuminen_auki']);
}

/**
 * Onko ilmoittautuminen aikataulun mukaan auki juuri nyt (lippu/tila + ajat).
 * Ei sulje pois kaynnissa/valmis — kutsuja päättää.
 */
function ilmoittautuminenAikatauluAuki(array $kilpailu): bool
{
    if (!ilmoittautuminenKaytossa($kilpailu)) {
        return false;
    }

    $nyt = time();
    $alkaa = !empty($kilpailu['ilmo_alkaa']) ? strtotime((string) $kilpailu['ilmo_alkaa']) : null;
    $paattyy = !empty($kilpailu['ilmo_paattyy']) ? strtotime((string) $kilpailu['ilmo_paattyy']) : null;

    if ($alkaa !== null && $alkaa !== false && $nyt < $alkaa) {
        return false;
    }
    if ($paattyy !== null && $paattyy !== false && $nyt > $paattyy) {
        return false;
    }

    return true;
}

/** Onko ilmoittautumisaika päättynyt (lippu päällä + päättymisaika mennyt). */
function ilmoittautuminenAikaOhi(array $kilpailu): bool
{
    if (!ilmoittautuminenKaytossa($kilpailu)) {
        return false;
    }

    $paattyy = !empty($kilpailu['ilmo_paattyy']) ? strtotime((string) $kilpailu['ilmo_paattyy']) : null;
    if ($paattyy === null || $paattyy === false) {
        return false;
    }

    return time() > $paattyy;
}

/** Onko julkinen ilmoittautumislomake auki tälle kilpailulle. */
function ilmoittautuminenAuki(array $kilpailu): bool
{
    if (($kilpailu['tila'] ?? '') === 'valmis' || ($kilpailu['tila'] ?? '') === 'luonnos') {
        return false;
    }

    return ilmoittautuminenAikatauluAuki($kilpailu);
}

/**
 * Ehdotettu tila ilmoittautumisasetusten perusteella, tai null jos ei muuteta.
 * Ei koske: kaynnissa, valmis (manuaaliset).
 */
function kilpailuEhdotettuTilaIlmosta(array $kilpailu): ?string
{
    $tila = (string) ($kilpailu['tila'] ?? '');
    if (in_array($tila, ['kaynnissa', 'valmis'], true)) {
        return null;
    }

    if (ilmoittautuminenAikatauluAuki($kilpailu)) {
        return 'ilmoittautuminen';
    }

    if (ilmoittautuminenAikaOhi($kilpailu)) {
        return 'tulossa';
    }

    return null;
}

/**
 * Synkaa kilpailujen tila ilmoittautumisen mukaan (ilmo auki → ilmoittautuminen, ohi → tulossa).
 *
 * @return int päivitettyjen rivien määrä
 */
function kilpailuSynkkaaTilaIlmosta(PDO $pdo): int
{
    $paivitetty = 0;
    try {
        $rows = $pdo->query("
            SELECT id, tila, kisa_tyyppi, ilmoittautuminen_auki, ilmo_alkaa, ilmo_paattyy
            FROM kilpailut
            WHERE tila NOT IN ('kaynnissa', 'valmis', 'arkisto')
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 0;
    }

    $stmt = $pdo->prepare('UPDATE kilpailut SET tila = ? WHERE id = ? AND tila = ?');
    foreach ($rows as $row) {
        $ehdotus = kilpailuEhdotettuTilaIlmosta($row);
        if ($ehdotus === null || $ehdotus === ($row['tila'] ?? '')) {
            continue;
        }
        try {
            $stmt->execute([$ehdotus, (int) $row['id'], $row['tila']]);
            $paivitetty += $stmt->rowCount();
        } catch (Throwable $e) {
            // enum puuttuu tms.
        }
    }

    return $paivitetty;
}

/**
 * Tilaksi ilmoittautuminen kun ilmo auki, tulossa kun aika ohi — ellei lukittu tila.
 */
function kilpailuTilaIlmoAsetuksista(string $valittuTila, array $ilmoKentat, string $kisaTyyppi = 'normaali'): string
{
    if (in_array($valittuTila, ['kaynnissa', 'valmis', 'arkisto'], true)) {
        return $valittuTila;
    }

    $mock = [
        'tila' => $valittuTila,
        'kisa_tyyppi' => $kisaTyyppi,
        'ilmoittautuminen_auki' => $ilmoKentat['auki'] ?? 0,
        'ilmo_alkaa' => $ilmoKentat['alkaa'] ?? null,
        'ilmo_paattyy' => $ilmoKentat['paattyy'] ?? null,
    ];
    $ehdotus = kilpailuEhdotettuTilaIlmosta($mock);

    return $ehdotus ?? $valittuTila;
}

/**
 * Näytetäänkö ilmoittautumislinkit valikoissa (ilmo käytössä).
 * Aikaväli voi olla suljettu — linkki silti näkyy.
 * Pirilä-kisassa linkki näkyy, kun ilmoittautuminen on laitettu päälle.
 */
function naytaIlmoittautuminenValikossa(array $kilpailu): bool
{
    return !empty($kilpailu['ilmoittautuminen_auki']);
}

/** Onko kilpailulla ilmoittautumiseen kelpaavia sarjoja (ei vakantteja). */
function kilpailullaOnSarjoja(PDO $pdo, int $kilpailuId): bool
{
    if ($kilpailuId < 1) {
        return false;
    }

    return haeSarjatIlmoittautumiseen($pdo, $kilpailuId) !== [];
}

/**
 * Sarjojen lukumäärä kilpailuittain (vakantit pois).
 *
 * @param list<int|string> $kilpailuIds
 * @return array<int,int>
 */
function kilpailujenSarjaLkm(PDO $pdo, array $kilpailuIds): array
{
    $ids = [];
    foreach ($kilpailuIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT kilpailu_id, COUNT(*) AS lkm
            FROM sarjat
            WHERE kilpailu_id IN ($ph)
              AND UPPER(TRIM(nimi)) NOT IN ('VAKANTIT', 'VAKANTTI')
            GROUP BY kilpailu_id
        ");
        $stmt->execute(array_values($ids));
    } catch (Throwable $e) {
        return array_fill_keys(array_values($ids), 0);
    }
    $out = array_fill_keys(array_values($ids), 0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['kilpailu_id']] = (int) $r['lkm'];
    }

    return $out;
}

/** Julkinen ilmoittautumislomake: aikataulu auki ja vähintään yksi sarja. */
function ilmoittautuminenJulkinenAuki(PDO $pdo, array $kilpailu): bool
{
    if (!ilmoittautuminenAuki($kilpailu)) {
        return false;
    }

    return kilpailullaOnSarjoja($pdo, (int) ($kilpailu['id'] ?? 0));
}

/** Näytetäänkö julkinen ilmoittautumislomakkeen linkki (sarjat vaaditaan). */
function naytaIlmoittautuminenLomakeValikossa(PDO $pdo, array $kilpailu): bool
{
    if (!naytaIlmoittautuminenValikossa($kilpailu)) {
        return false;
    }

    return kilpailullaOnSarjoja($pdo, (int) ($kilpailu['id'] ?? 0));
}

/** Kisapäivä YYYY-MM-DD, tai tyhjä. */
function kisapaivaYmd(array $kilpailu): string
{
    $p = substr(trim((string) ($kilpailu['paiva'] ?? '')), 0, 10);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) ? $p : '';
}

/** Onko lähdön datetime eri kalenteripäivänä kuin kilpailun päivä. */
function lahtoAikaPoikkeaaKisapaivasta(array $kilpailu, ?string $lahtoaika): bool
{
    $kisa = kisapaivaYmd($kilpailu);
    $lahto = substr(trim((string) $lahtoaika), 0, 10);

    return $kisa !== '' && $lahto !== '' && $kisa !== $lahto;
}

/** Ilmoittautumismaksu euroina, tai null jos maksuton. */
function ilmoMaksuEur(array $kilpailu): ?float
{
    return ilmoMaksuEurKentasta($kilpailu['ilmo_maksu_eur'] ?? null);
}

/** Paikan päällä ilmoittautumisen hinta, erillinen verkkomaksusta. Tyhjä = maksuton. */
function paikanpaaMaksuEur(array $kilpailu): ?float
{
    return ilmoMaksuEurKentasta($kilpailu['paikanpaa_maksu_eur'] ?? null);
}

function ilmoMaksuEurKentasta($raw): ?float
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $eur = (float) $raw;

    return $eur > 0 ? $eur : null;
}

function formatEuro(?float $eur): string
{
    if ($eur === null) {
        return '';
    }

    return number_format($eur, 2, ',', ' ') . ' €';
}

function maksuTilaLabel(string $tila): string
{
    return match ($tila) {
        'odottaa' => 'Odottaa maksua',
        'maksettu' => 'Maksettu',
        default => 'Ei maksua',
    };
}

/** datetime-local → MySQL DATETIME tai null */
function parseDatetimeLocal(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    $ts = strtotime($value);

    return ($ts !== false) ? date('Y-m-d H:i:s', $ts) : null;
}

function datetimeLocalValue(?string $dbValue): string
{
    if (!$dbValue) {
        return '';
    }
    $ts = strtotime($dbValue);

    return $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
}

/** Parsii euro-syötteen (10 / 10,00 / 10.00). */
function parseEuroSyote(?string $value): ?float
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace([' ', '€'], '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        return null;
    }
    $eur = round((float) $value, 2);

    return $eur >= 0 ? $eur : null;
}

/**
 * Pirilän MAXKILPNO on 9999. Numerot 9980–9999 jäävät vakanteille,
 * joten automaattinen numero pysyy välillä 1–9979.
 */
function pirilaMaxKilpailunumero(): int
{
    return 9979;
}

/** Seuraava vapaa numero välillä 1–9979. 0 jos vapaita ei ole. */
function seuraavaKilpailijaNumero(PDO $pdo, int $kilpailuId): int
{
    $max = pirilaMaxKilpailunumero();
    $stmt = $pdo->prepare('SELECT numero FROM kilpailijat WHERE kilpailu_id = ? AND numero BETWEEN 1 AND ?');
    $stmt->execute([$kilpailuId, $max]);
    $kaytossa = [];
    $huippu = 0;
    while (($n = $stmt->fetchColumn()) !== false) {
        $i = (int) $n;
        $kaytossa[$i] = true;
        if ($i > $huippu) {
            $huippu = $i;
        }
    }
    $ehdokas = $huippu + 1;
    if ($ehdokas >= 1 && $ehdokas <= $max && !isset($kaytossa[$ehdokas])) {
        return $ehdokas;
    }
    for ($i = 1; $i <= $max; $i++) {
        if (!isset($kaytossa[$i])) {
            return $i;
        }
    }

    return 0;
}

/** Anna juoksevat numerot kaikille joilla numero puuttuu. Palauttaa päivitettyjen rivien määrän. */
function numeroiPuuttuvatKilpailijat(PDO $pdo, int $kilpailuId): int
{
    if ($kilpailuId < 1) {
        return 0;
    }
    $st = $pdo->prepare('
        SELECT id, sarja_id FROM kilpailijat
        WHERE kilpailu_id = ?
          AND (numero IS NULL OR numero < 1)
        ORDER BY id
    ');
    $st->execute([$kilpailuId]);
    $rivit = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rivit === []) {
        return 0;
    }
    $upd = $pdo->prepare('UPDATE kilpailijat SET numero = ? WHERE id = ? AND kilpailu_id = ?');
    $n = 0;
    foreach ($rivit as $rivi) {
        $id = (int) ($rivi['id'] ?? 0);
        $sarjaId = (int) ($rivi['sarja_id'] ?? 0);
        $num = 0;
        $valit = ($sarjaId > 0 && function_exists('sarjaHaeNumeroValit'))
            ? sarjaHaeNumeroValit($pdo, $sarjaId)
            : [];
        if ($valit !== [] && function_exists('seuraavaSarjanNumero')) {
            $num = seuraavaSarjanNumero($pdo, $kilpailuId, $sarjaId);
            if ($num < 1) {
                continue;
            }
        } elseif ($sarjaId > 0 && function_exists('seuraavaSarjanNumero')) {
            $num = seuraavaSarjanNumero($pdo, $kilpailuId, $sarjaId);
        }
        if ($num < 1) {
            $num = seuraavaKilpailijaNumero($pdo, $kilpailuId);
        }
        if ($num < 1) {
            continue;
        }
        $upd->execute([$num, $id, $kilpailuId]);
        $n++;
    }

    return $n;
}

/** Näyttö: numero tai em dash jos ei vielä annettu. */
function formatKilpailijaNumero($numero): string
{
    if ($numero === null || $numero === '' || (int) $numero <= 0) {
        return '—';
    }

    return (string) (int) $numero;
}

/** Kilpailijalistan läsnäolosarake. Kirjain on Pirilän keskhyl. */
function kilpailijaLasnaTeksti(array $k): string
{
    $kirjain = mb_strtoupper(trim((string) ($k['keskhyl'] ?? '')), 'UTF-8');
    $kirjain = $kirjain === '' ? '' : mb_substr($kirjain, 0, 1);
    $nimet = [
        '-' => 'Läsnä',
        'N' => 'Ilmoittautunut',
        'T' => 'Tarkistettu',
        'E' => (!empty($k['luonne']) && $k['luonne'] === 'iltarasti') || !empty($k['iltarasti'])
            ? 'Esitys'
            : 'Ei lähtenyt',
        'K' => 'Keskeyttänyt',
        'H' => 'Hylätty',
        'V' => 'Vakantti',
        'P' => 'Poissa',
        'B' => 'Varalla',
    ];
    if ($kirjain !== '' && isset($nimet[$kirjain])) {
        return $nimet[$kirjain];
    }
    if (!empty($k['lasna'])) {
        return 'Läsnä';
    }

    return '—';
}

/** Mobiililukijan läsnä-, maali- ja lisäysajat henkilöllä (Europe/Helsinki). */
function kilpailijaVarmistaLukuAikaSarake(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    foreach (['mobiili_luettu_at', 'mobiili_lasna_at', 'mobiili_maali_at', 'mobiili_lisatty_at'] as $sarake) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'kilpailijat'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$sarake]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `kilpailijat` ADD COLUMN `' . $sarake . '` DATETIME NULL');
        }
    }
    $ok = true;
}

function kilpailijaKelloTeksti(?string $datetime): string
{
    $aika = trim((string) $datetime);
    if (strlen($aika) < 19) {
        return '';
    }

    return substr($aika, 11, 8);
}

function kilpailijaLukuAikaTeksti(array $k): string
{
    $aika = kilpailijaKelloTeksti($k['mobiili_lasna_at'] ?? null);

    return $aika !== '' ? $aika : '—';
}

/**
 * Lukijalla tallennetut rastileimat.
 *
 * @return list<array{jarjestys:int,koodi:string,kello:string}>
 */
function kilpailijaEmitLeimat(PDO $pdo, int $kilpailijaId): array
{
    if ($kilpailijaId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT jarjestys, rasti_koodi, aika_sec
        FROM emit_leimat
        WHERE kilpailija_id = ?
        ORDER BY jarjestys
    ');
    $stmt->execute([$kilpailijaId]);
    $ulos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rivi) {
        $sec = (int) ($rivi['aika_sec'] ?? 0);
        if ($sec < 0) {
            $sec = 0;
        }
        $sec %= 86400;
        $ulos[] = [
            'jarjestys' => (int) ($rivi['jarjestys'] ?? 0),
            'koodi' => (string) ($rivi['rasti_koodi'] ?? ''),
            'kello' => sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60),
        ];
    }

    return $ulos;
}

/** UTC-kellonaika SQL:stä Suomen aikaan, muoto j.n. H:i:s. */
function utcHetkiHelsinkiTeksti(?string $utc): string
{
    $utc = trim((string) $utc);
    if (strlen($utc) < 19) {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($utc, 0, 19), new DateTimeZone('UTC'));
    if (!$dt) {
        return '';
    }

    return $dt->setTimezone(new DateTimeZone('Europe/Helsinki'))->format('j.n. H:i:s');
}

/**
 * Mobiililukijan luvut tälle henkilölle, uusin ensin.
 *
 * @return list<array{aika:string,viesti:string}>
 */
function kilpailijaLukuajat(PDO $pdo, int $kilpailijaId, int $raja = 20): array
{
    if ($kilpailijaId <= 0) {
        return [];
    }
    $raja = max(1, min(50, $raja));
    $stmt = $pdo->prepare('
        SELECT luotu, viesti
        FROM lahtoluenta_tapahtumat
        WHERE kilpailija_id = ? AND tulos IN (\'ok\', \'varoitus\')
        ORDER BY id DESC
        LIMIT ' . $raja . '
    ');
    $stmt->execute([$kilpailijaId]);
    $ulos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rivi) {
        $aika = utcHetkiHelsinkiTeksti((string) ($rivi['luotu'] ?? ''));
        if ($aika === '') {
            continue;
        }
        $ulos[] = [
            'aika' => $aika,
            'viesti' => trim((string) ($rivi['viesti'] ?? '')),
        ];
    }

    return $ulos;
}

/**
 * Ampumasakot taulukkona. "21" ja "2/1" ovat sama tieto: yksi luku per ampumakerta.
 *
 * @return list<string>
 */
function sakkoOsat(string $raaka): array
{
    $raaka = trim($raaka);
    if ($raaka === '') {
        return [];
    }
    $palat = str_contains($raaka, '/') ? explode('/', $raaka) : str_split($raaka);
    $ulos = [];
    foreach ($palat as $pala) {
        $pala = trim((string) $pala);
        if ($pala === '' || $pala === '-') {
            $ulos[] = '-';
            continue;
        }
        if (preg_match('/^[0-9]+$/', $pala)) {
            $ulos[] = (string) (int) $pala;
        }
    }
    while ($ulos !== [] && end($ulos) === '-') {
        array_pop($ulos);
    }

    return $ulos;
}

function sakkoLiita(array $osat): string
{
    while ($osat !== [] && end($osat) === '-') {
        array_pop($osat);
    }
    if ($osat === []) {
        return '';
    }

    return implode('/', $osat);
}

function sakkoNaytto(string $raaka): string
{
    return sakkoLiita(sakkoOsat($raaka));
}

/** Ampumasakot listassa, esim. 1/2/2/3. */
function kilpailijaSakkoTeksti(array $k): string
{
    $sakko = sakkoNaytto((string) ($k['ampsakot'] ?? ''));

    return $sakko === '' ? '—' : $sakko;
}

/** Admin/lomake: tyhjä → null, muuten positiivinen int. */
function parseKilpailijaNumeroSyote($value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $n = (int) $value;

    return $n > 0 ? $n : null;
}

/** Onko kilpailunumero jo käytössä samassa kilpailussa (valinnainen excludeId päivityksessä)? */
function kilpailijaNumeroVarattu(PDO $pdo, int $kilpailuId, int $numero, ?int $excludeId = null): bool
{
    if ($numero < 1 || $kilpailuId < 1) {
        return false;
    }
    if ($excludeId) {
        $stmt = $pdo->prepare('
            SELECT id FROM kilpailijat
            WHERE kilpailu_id = ? AND numero = ? AND id != ?
            LIMIT 1
        ');
        $stmt->execute([$kilpailuId, $numero, $excludeId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT id FROM kilpailijat
            WHERE kilpailu_id = ? AND numero = ?
            LIMIT 1
        ');
        $stmt->execute([$kilpailuId, $numero]);
    }

    return (bool) $stmt->fetchColumn();
}

/** Sarjat ilmoittautumislomakkeelle (jarjestys, nimi). */
function haeSarjatIlmoittautumiseen(PDO $pdo, int $kilpailuId): array
{
    $stmt = $pdo->prepare('
        SELECT id, nimi, matka
        FROM sarjat
        WHERE kilpailu_id = ?
          AND UPPER(TRIM(nimi)) NOT IN (\'VAKANTIT\', \'VAKANTTI\')
        ORDER BY jarjestys ASC, nimi ASC
    ');
    $stmt->execute([$kilpailuId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ilmoittautuneet / osallistujat julkiseen listaan, ryhmiteltynä sarjoittain.
 * @return array<string, array{matka:?string, rivit:array}>
 */
function haeIlmoittautuneetRyhmiteltyna(PDO $pdo, int $kilpailuId): array
{
    $kilpailu = haeKilpailu($pdo, $kilpailuId);
    if ($kilpailu && ($kilpailu['tyyppi'] ?? '') === 'viesti') {
        return haeIlmoittautuneetViestiRyhmiteltyna($pdo, $kilpailuId);
    }

    $stmt = $pdo->prepare('
        SELECT
            k.id,
            k.numero,
            k.etunimi,
            k.sukunimi,
            k.nimi,
            k.seura,
            k.ilmoittautunut_at,
            s.nimi AS sarja,
            s.matka AS sarja_matka,
            s.jarjestys AS sarja_jarjestys
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
        WHERE k.kilpailu_id = ?
          AND k.status = \'OK\'
          AND COALESCE(k.osuus, 0) = 0
          AND UPPER(TRIM(s.nimi)) NOT IN (\'VAKANTIT\', \'VAKANTTI\')
        ORDER BY s.jarjestys ASC, s.nimi ASC, k.sukunimi ASC, k.etunimi ASC, k.id ASC
    ');
    $stmt->execute([$kilpailuId]);

    $ryhmat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sarja = $r['sarja'];
        if (onVakanttiSarja((string) $sarja)) {
            continue;
        }
        if (!isset($ryhmat[$sarja])) {
            $ryhmat[$sarja] = [
                'matka' => $r['sarja_matka'] ?? null,
                'rivit' => [],
            ];
        }
        $ryhmat[$sarja]['rivit'][] = $r;
    }

    return $ryhmat;
}

/**
 * Viestikilpailun ilmoittautuneet / osallistujat joukkueittain.
 * @return array<string, array{matka:?string, rivit:array}>
 */
function haeIlmoittautuneetViestiRyhmiteltyna(PDO $pdo, int $kilpailuId): array
{
    $stmt = $pdo->prepare('
        SELECT
            j.id,
            j.numero,
            j.seura,
            j.joukkue,
            s.nimi AS sarja,
            s.matka AS sarja_matka,
            s.jarjestys AS sarja_jarjestys
        FROM joukkueet j
        JOIN sarjat s ON s.id = j.sarja_id
        WHERE j.kilpailu_id = ?
        ORDER BY s.jarjestys ASC, s.nimi ASC, j.numero ASC, j.id ASC
    ');
    $stmt->execute([$kilpailuId]);

    $ryhmat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sarja = $r['sarja'];
        if (!isset($ryhmat[$sarja])) {
            $ryhmat[$sarja] = [
                'matka' => $r['sarja_matka'] ?? null,
                'rivit' => [],
            ];
        }
        $nimi = trim(($r['seura'] ?? '') . (isset($r['joukkue']) && $r['joukkue'] !== '' && $r['joukkue'] !== null
            ? ' ' . $r['joukkue']
            : ''));
        $ryhmat[$sarja]['rivit'][] = [
            'id' => (int) $r['id'],
            'numero' => $r['numero'],
            'nimi' => $nimi !== '' ? $nimi : ('Joukkue ' . (int) $r['numero']),
            'etunimi' => null,
            'sukunimi' => null,
            'seura' => $r['seura'] ?? '',
            'ilmoittautunut_at' => null,
            'sarja' => $sarja,
            'sarja_matka' => $r['sarja_matka'] ?? null,
            '_viesti' => true,
        ];
    }

    return $ryhmat;
}

/** Kellonaika DATETIME-kentästä (H:i tai H:i:s). Tyhjä jos ei kelvollista lähtöä. */
function formatLahtoaikaKello(?string $datetime): string
{
    $datetime = trim((string) $datetime);
    if ($datetime === '' || strlen($datetime) < 16) {
        return '';
    }
    $aika = substr($datetime, 11);
    if ($aika === '00:00:00' || $aika === '00:00' || str_starts_with($aika, '00:00:')) {
        return '';
    }
    // Poista turhat sekunnit :00
    if (preg_match('/^(\d{2}:\d{2}):00$/', $aika, $m)) {
        return $m[1];
    }

    return substr($aika, 0, 8);
}

/**
 * TV / lähtöalue: kilpailijat joiden lähtö on seuraavan $minuutteja minuutin sisällä.
 * @return list<array{numero:int|string,nimi:string,seura:string,sarja:string,lahtoaika:string,lahto_kello:string,jaljella:string,sek_jaljella:int,henkilokohtainen:bool}>
 */
function haeTvLahtevat(PDO $pdo, int $kilpailuId, int $minuutteja = 10): array
{
    $minuutteja = max(1, min(180, $minuutteja));
    $kilpailu = haeKilpailu($pdo, $kilpailuId);
    if (!$kilpailu) {
        return [];
    }

    $onViesti = onkoViestiKilpailu($kilpailu);
    $rivit = haeLahtolistaRivit($pdo, $kilpailuId);
    $now = time();
    $from = $now - 45; // näytä vielä hetki lähdön jälkeen
    $to = $now + ($minuutteja * 60);

    // Jo maaliin tulleet jätetään pois (henkilökohtainen id)
    $maalissa = [];
    try {
        if ($onViesti) {
            $st = $pdo->prepare('
                SELECT a.kilpailija_id
                FROM ajat a
                JOIN kilpailijat k ON k.id = a.kilpailija_id
                JOIN joukkueet j ON j.id = k.joukkue_id
                WHERE j.kilpailu_id = ?
            ');
        } else {
            $st = $pdo->prepare('
                SELECT a.kilpailija_id
                FROM ajat a
                JOIN kilpailijat k ON k.id = a.kilpailija_id
                WHERE k.kilpailu_id = ?
            ');
        }
        $st->execute([$kilpailuId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $maalissa[(int) $id] = true;
        }
    } catch (Throwable $e) {
        $maalissa = [];
    }

    $out = [];
    foreach ($rivit as $r) {
        if (strtoupper((string) ($r['status'] ?? 'OK')) !== 'OK') {
            continue;
        }
        $kid = (int) ($r['id'] ?? 0);
        if ($kid > 0 && isset($maalissa[$kid])) {
            continue;
        }

        $osuus = (int) ($r['osuus'] ?? 0);
        $henkilo = !empty($r['lahtoaika_henkilokohtainen']);
        // Viesti: sarjalähtö vain 1. osuudelle (myöhemmät eivät "lähde" samalla kellolla)
        if ($onViesti && !$henkilo && $osuus > 1) {
            continue;
        }

        $dt = $r['lahtoaika_naytto'] ?? null;
        if ($dt === null || $dt === '') {
            continue;
        }
        $kello = formatLahtoaikaKello((string) $dt);
        if ($kello === '') {
            continue;
        }
        $ts = strtotime((string) $dt);
        if ($ts === false || $ts < $from || $ts > $to) {
            continue;
        }

        $sek = $ts - $now;
        if ($sek <= 0) {
            $jaljella = 'NYT';
        } elseif ($sek < 60) {
            $jaljella = $sek . ' s';
        } else {
            $jaljella = sprintf('%d:%02d', intdiv($sek, 60), $sek % 60);
        }

        if ($onViesti) {
            $joukkueNimi = trim(($r['seura'] ?? '') . (!empty($r['joukkue']) ? ' ' . $r['joukkue'] : ''));
            $osuusNimi = kilpailijaNimiRivista($r);
            $nimi = $joukkueNimi !== '' ? $joukkueNimi : ('Joukkue ' . (int) ($r['numero'] ?? 0));
            $seura = $osuus > 0
                ? ($osuus . '. ' . $osuusNimi)
                : (string) ($r['seura'] ?? '');
        } else {
            $nimi = (string) ($r['nimi'] ?? kilpailijaNimiRivista($r));
            $seura = (string) ($r['seura'] ?? '');
        }

        $out[] = [
            'numero' => $r['numero'] ?? '',
            'nimi' => $nimi,
            'seura' => $seura,
            'sarja' => (string) ($r['sarja'] ?? ''),
            'lahtoaika' => (string) $dt,
            'lahto_kello' => $kello,
            'jaljella' => $jaljella,
            'sek_jaljella' => $sek,
            'henkilokohtainen' => $henkilo,
        ];
    }

    usort($out, static function ($a, $b) {
        $sa = (int) ($a['sek_jaljella'] ?? 0);
        $sb = (int) ($b['sek_jaljella'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        return ((int) ($a['numero'] ?? 0)) <=> ((int) ($b['numero'] ?? 0));
    });

    if (count($out) > 60) {
        $out = array_slice($out, 0, 60);
    }

    return $out;
}

/** Onko sarja Pirilän VAKANTIT (piilotetaan julkisista listoista). */
function onVakanttiSarja(?string $nimi): bool
{
    $n = mb_strtoupper(trim((string) $nimi), 'UTF-8');

    return $n === 'VAKANTIT' || $n === 'VAKANTTI';
}

/** Lähtölista: kellonaika tai status jos ei lähtöä (DNS/DNF/DSQ). */
function formatLahtoaikaTaiStatus(?string $datetime, ?string $status = 'OK'): string
{
    $kello = formatLahtoaikaKello($datetime);
    if ($kello !== '') {
        return $kello;
    }

    $status = trim((string) $status);
    if ($status !== '' && $status !== 'OK') {
        return statusLabel($status);
    }

    return '';
}

/** Lähtölista: näytä lähtöaika tai DNS — ei DNF/DSQ-koodeja. */
function formatLahtolistaLahto(?string $datetime, ?string $status = null): string
{
    $kello = formatLahtoaikaKello($datetime);
    if ($kello !== '') {
        return $kello;
    }

    $status = trim((string) $status);
    if ($status === 'DNS') {
        return 'DNS';
    }

    return '—';
}

/**
 * Rastiväliajat: näytä M jos rastin aika = maaliaika (maalirasti).
 */
function valiajatRastiKoodiNaytto(?string $rastiKoodi, ?int $aikaSec, ?int $maaliAikaSec): string
{
    if ($maaliAikaSec !== null && $aikaSec !== null && abs($aikaSec - $maaliAikaSec) <= 1) {
        return 'M';
    }

    $koodi = trim((string) $rastiKoodi);

    return $koodi;
}

/** Emit-numero(t) lähtölistaan. */
function formatEmitNumerot($emit1, $emit2 = null): string
{
    $e1 = ($emit1 !== null && $emit1 !== '') ? (int) $emit1 : 0;
    $e2 = ($emit2 !== null && $emit2 !== '') ? (int) $emit2 : 0;
    $palat = [];

    if ($e1 > 0) {
        $palat[] = (string) $e1;
    }
    if ($e2 > 0 && $e2 !== $e1) {
        $palat[] = (string) $e2;
    }

    return implode(' / ', $palat);
}

/** Varmista kilpailijat.lasna (emit-leimauksen / läsnäolon tila). */
function sarjaVarmistaLahtopaikkaSarake(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'sarjat'
          AND COLUMN_NAME = 'lahtopaikka'
    ");
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `sarjat` ADD COLUMN `lahtopaikka` SMALLINT NULL DEFAULT NULL');
    }
    $ok = true;
}

/** Sarjoille merkityt lähtöpaikat (Pirilän lno), nousevassa järjestyksessä. */
function kilpailunLahtopaikat(PDO $pdo, int $kilpailuId): array
{
    sarjaVarmistaLahtopaikkaSarake($pdo);
    $stmt = $pdo->prepare('
        SELECT DISTINCT lahtopaikka
        FROM sarjat
        WHERE kilpailu_id = ? AND lahtopaikka IS NOT NULL AND lahtopaikka > 0
        ORDER BY lahtopaikka
    ');
    $stmt->execute([$kilpailuId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $out[] = (int) $n;
    }

    return $out;
}

function kilpailijaVarmistaLasnaSarake(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'kilpailijat'
          AND COLUMN_NAME = 'lasna'
    ");
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `kilpailijat` ADD COLUMN `lasna` TINYINT(1) NULL DEFAULT NULL');
    }
    $ok = true;
}

/** Emit-numero nimen perään (julkinen lähtölista). */
function lahtolistaEmitMetaHtml(array $r, bool $naytaEmit): string
{
    if (!$naytaEmit) {
        return '';
    }
    $emitTxt = formatEmitNumerot($r['emit_koodi'] ?? null, $r['emit_koodi2'] ?? null);
    if ($emitTxt === '') {
        return '';
    }

    return '<span class="lahto-emit-peraan">' . htmlspecialchars($emitTxt, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * @return list<array>
 */
function haeLahtolistaRivit(PDO $pdo, int $kilpailuId): array
{
    kilpailijaVarmistaLasnaSarake($pdo);
    sarjaVarmistaLahtopaikkaSarake($pdo);
    $kilpailu = haeKilpailu($pdo, $kilpailuId);
    if ($kilpailu && ($kilpailu['tyyppi'] ?? '') === 'viesti') {
        return haeLahtolistaViestiRivit($pdo, $kilpailuId);
    }

    $stmt = $pdo->prepare('
        SELECT
            k.id,
            k.numero,
            k.etunimi,
            k.sukunimi,
            k.nimi,
            k.seura,
            k.status,
            k.lasna,
            k.rata,
            k.pirila_lahto_at,
            k.emit_koodi,
            k.emit_koodi2,
            s.id AS sarja_id,
            s.nimi AS sarja,
            s.matka AS sarja_matka,
            s.lahtopaikka,
            s.jarjestys AS sarja_jarjestys,
            l.lahtoaika AS sarja_lahtoaika,
            l.nimi AS lahto_nimi
        FROM kilpailijat k
        JOIN sarjat s ON s.id = k.sarja_id
        LEFT JOIN lahdot l ON l.id = s.lahto_id
        WHERE k.kilpailu_id = ?
          AND k.status IN (\'OK\', \'DNS\', \'DNF\', \'DSQ\')
          AND COALESCE(k.osuus, 0) = 0
          AND UPPER(TRIM(s.nimi)) NOT IN (\'VAKANTIT\', \'VAKANTTI\')
        ORDER BY
            s.jarjestys ASC,
            s.nimi ASC,
            CASE WHEN k.pirila_lahto_at IS NULL THEN 1 ELSE 0 END ASC,
            k.pirila_lahto_at ASC,
            CASE WHEN l.lahtoaika IS NULL THEN 1 ELSE 0 END ASC,
            l.lahtoaika ASC,
            CASE WHEN k.numero IS NULL THEN 1 ELSE 0 END,
            k.numero ASC,
            k.sukunimi ASC,
            k.etunimi ASC,
            k.id ASC
    ');
    $stmt->execute([$kilpailuId]);
    $rivit = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rivit as &$r) {
        $status = (string) ($r['status'] ?? 'OK');
        $henkilo = trim((string) ($r['pirila_lahto_at'] ?? ''));
        if ($henkilo !== '' && formatLahtoaikaKello($henkilo) === '') {
            $henkilo = '';
        }
        $sarja = trim((string) ($r['sarja_lahtoaika'] ?? ''));
        if ($sarja !== '' && formatLahtoaikaKello($sarja) === '') {
            $sarja = '';
        }
        if ($henkilo !== '') {
            $r['lahtoaika_naytto'] = $henkilo;
        } elseif ($sarja !== '') {
            $r['lahtoaika_naytto'] = $sarja;
        } else {
            $r['lahtoaika_naytto'] = null;
        }
        $r['lahtoaika_henkilokohtainen'] = $henkilo !== '';
        $r['lasna'] = isset($r['lasna']) && $r['lasna'] !== null ? (int) $r['lasna'] : 0;
        $r['nimi'] = kilpailijaNimiRivista($r);
    }
    unset($r);

    return $rivit;
}

/**
 * Viestikilpailun lähtölista: jäsenet seuran alla (joukkue + osuus), emit mukana.
 * @return list<array>
 */
function haeLahtolistaViestiRivit(PDO $pdo, int $kilpailuId): array
{
    kilpailijaVarmistaLasnaSarake($pdo);
    $stmt = $pdo->prepare('
        SELECT
            k.id,
            j.id AS joukkue_id,
            j.numero,
            k.osuus,
            k.etunimi,
            k.sukunimi,
            k.nimi,
            k.status,
            k.lasna,
            k.rata,
            k.pirila_lahto_at,
            k.emit_koodi,
            k.emit_koodi2,
            j.seura,
            j.joukkue,
            s.id AS sarja_id,
            s.nimi AS sarja,
            s.matka AS sarja_matka,
            s.lahtopaikka,
            s.jarjestys AS sarja_jarjestys,
            l.lahtoaika AS sarja_lahtoaika,
            l.nimi AS lahto_nimi
        FROM kilpailijat k
        JOIN joukkueet j ON j.id = k.joukkue_id
        JOIN sarjat s ON s.id = j.sarja_id
        LEFT JOIN lahdot l ON l.id = s.lahto_id
        WHERE j.kilpailu_id = ?
          AND k.status IN (\'OK\', \'DNS\', \'DNF\', \'DSQ\')
          AND UPPER(TRIM(s.nimi)) NOT IN (\'VAKANTIT\', \'VAKANTTI\')
        ORDER BY
            s.jarjestys ASC,
            s.nimi ASC,
            j.seura ASC,
            CASE WHEN j.numero IS NULL THEN 1 ELSE 0 END,
            j.numero ASC,
            k.osuus ASC,
            k.id ASC
    ');
    $stmt->execute([$kilpailuId]);
    $jasenet = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rivit = [];
    foreach ($jasenet as $k) {
        $henkilo = trim((string) ($k['pirila_lahto_at'] ?? ''));
        if ($henkilo !== '' && formatLahtoaikaKello($henkilo) === '') {
            $henkilo = '';
        }
        $sarja = trim((string) ($k['sarja_lahtoaika'] ?? ''));
        if ($sarja !== '' && formatLahtoaikaKello($sarja) === '') {
            $sarja = '';
        }
        $osuus = (int) ($k['osuus'] ?? 0);
        $henkiloNimi = kilpailijaNimiRivista($k);
        $joukkueTunnus = trim((string) ($k['joukkue'] ?? ''));
        $seura = trim((string) ($k['seura'] ?? ''));
        $joukkueNimi = trim($seura . ($joukkueTunnus !== '' ? ' ' . $joukkueTunnus : ''));
        if ($joukkueNimi === '') {
            $joukkueNimi = 'Joukkue ' . (int) ($k['numero'] ?? 0);
        }
        $rivit[] = [
            'id' => (int) $k['id'],
            'joukkue_id' => (int) $k['joukkue_id'],
            'numero' => $k['numero'],
            'osuus' => $osuus > 0 ? $osuus : null,
            'etunimi' => $k['etunimi'] ?? null,
            'sukunimi' => $k['sukunimi'] ?? null,
            'nimi' => $henkiloNimi !== '' ? $henkiloNimi : ('Osuus ' . max($osuus, 1)),
            'seura' => $seura,
            'joukkue' => $joukkueTunnus !== '' ? $joukkueTunnus : null,
            'joukkue_nimi' => $joukkueNimi,
            'status' => in_array((string) ($k['status'] ?? 'OK'), ['OK', 'DNS', 'DNF', 'DSQ'], true)
                ? (string) $k['status']
                : 'OK',
            'lasna' => isset($k['lasna']) && $k['lasna'] !== null ? (int) $k['lasna'] : 0,
            'rata' => $k['rata'] ?? null,
            'pirila_lahto_at' => $henkilo !== '' ? $henkilo : null,
            'emit_koodi' => $k['emit_koodi'] ?? null,
            'emit_koodi2' => $k['emit_koodi2'] ?? null,
            'sarja_id' => (int) $k['sarja_id'],
            'sarja' => $k['sarja'],
            'sarja_matka' => $k['sarja_matka'] ?? null,
            'lahtopaikka' => isset($k['lahtopaikka']) && $k['lahtopaikka'] !== null ? (int) $k['lahtopaikka'] : null,
            'sarja_jarjestys' => $k['sarja_jarjestys'] ?? null,
            'sarja_lahtoaika' => $k['sarja_lahtoaika'] ?? null,
            'lahto_nimi' => $k['lahto_nimi'] ?? null,
            'lahtoaika_naytto' => $henkilo !== '' ? $henkilo : ($sarja !== '' ? $sarja : null),
            'lahtoaika_henkilokohtainen' => $henkilo !== '',
            '_viesti' => true,
        ];
    }

    return $rivit;
}

/**
 * Ryhmittele viestin lähtölistarivit joukkueittain (numero + nimi + osuusjäsenet).
 *
 * @param list<array> $rivit
 * @return list<array{numero:mixed,joukkue_nimi:string,seura:string,rivit:list<array>}>
 */
function lahtolistaViestiJoukkueAliryhmat(array $rivit): array
{
    $joukkueet = [];
    foreach ($rivit as $r) {
        $jid = (int) ($r['joukkue_id'] ?? 0);
        $key = $jid > 0
            ? 'j' . $jid
            : 'n' . (string) ($r['numero'] ?? '') . '|' . seuraAvain((string) ($r['seura'] ?? ''));
        if (!isset($joukkueet[$key])) {
            $joukkueet[$key] = [
                'numero' => $r['numero'] ?? null,
                'joukkue_nimi' => (string) ($r['joukkue_nimi'] ?? $r['seura'] ?? 'Joukkue'),
                'seura' => trim((string) ($r['seura'] ?? '')),
                'rivit' => [],
            ];
        }
        $joukkueet[$key]['rivit'][] = $r;
    }

    return array_values($joukkueet);
}

/**
 * Sarjan lähtökellonaika: ensin lahdot/Pirilä-sarjalähtö, muuten ensimmäinen henkilökohtainen.
 */
function lahtolistaSarjaLahtoKello(array $rivit, ?string $sarjaLahtoaika = null): string
{
    $kello = formatLahtoaikaKello($sarjaLahtoaika);
    if ($kello !== '') {
        return $kello;
    }

    $earliest = null;
    foreach ($rivit as $r) {
        foreach ([$r['sarja_lahtoaika'] ?? null, $r['pirila_lahto_at'] ?? null, $r['lahtoaika_naytto'] ?? null] as $dt) {
            $k = formatLahtoaikaKello(is_string($dt) ? $dt : null);
            if ($k === '') {
                continue;
            }
            $ts = strtotime((string) $dt);
            if ($ts === false) {
                continue;
            }
            if ($earliest === null || $ts < $earliest['ts']) {
                $earliest = ['ts' => $ts, 'kello' => $k];
            }
        }
    }

    return $earliest['kello'] ?? '';
}

/**
 * Jaa rivit seuroittain (aakkosjärjestys, tyhjä seura loppuun). Säilyttää järjestyksen seuran sisällä.
 *
 * @param list<array> $rivit
 * @return list<array{seura:string, rivit:list<array>}>
 */
function lahtolistaSeuraAliryhmat(array $rivit, string $seuraSuodatin = ''): array
{
    $seuraRyhmat = [];
    foreach ($rivit as $r) {
        if (!riviVastaaSeuraan($r, $seuraSuodatin)) {
            continue;
        }
        $seura = trim((string) ($r['seura'] ?? ''));
        $key = $seura !== '' ? seuraAvain($seura) : '__ei_seuraa__';
        if (!isset($seuraRyhmat[$key])) {
            $seuraRyhmat[$key] = [
                'seura' => $seura,
                'rivit' => [],
            ];
        }
        $seuraRyhmat[$key]['rivit'][] = $r;
    }

    uasort($seuraRyhmat, static function (array $a, array $b): int {
        $ea = $a['seura'] === '';
        $eb = $b['seura'] === '';
        if ($ea !== $eb) {
            return $ea ? 1 : -1;
        }
        $cmp = strcasecmp($a['seura'], $b['seura']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return 0;
    });

    foreach ($seuraRyhmat as &$g) {
        usort($g['rivit'], static function (array $a, array $b): int {
            $ta = lahtolistaRivinLahtoTs($a);
            $tb = lahtolistaRivinLahtoTs($b);
            $aNull = $ta === null;
            $bNull = $tb === null;
            if ($aNull !== $bNull) {
                return $aNull ? 1 : -1;
            }
            if ($ta !== null && $tb !== null && $ta !== $tb) {
                return $ta <=> $tb;
            }
            $na = $a['numero'] ?? null;
            $nb = $b['numero'] ?? null;
            if (($na === null) !== ($nb === null)) {
                return $na === null ? 1 : -1;
            }
            if ($na !== null && $nb !== null && (int) $na !== (int) $nb) {
                return (int) $na <=> (int) $nb;
            }
            $oa = (int) ($a['osuus'] ?? 0);
            $ob = (int) ($b['osuus'] ?? 0);
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcasecmp((string) ($a['sukunimi'] ?? $a['nimi'] ?? ''), (string) ($b['sukunimi'] ?? $b['nimi'] ?? ''));
        });
    }
    unset($g);

    return array_values($seuraRyhmat);
}

/**
 * Lähtöajan timestamp riviltä (henkilökohtainen tai sarja). Null jos ei kelvollista.
 */
function lahtolistaRivinLahtoTs(array $r): ?int
{
    foreach ([$r['lahtoaika_naytto'] ?? null, $r['pirila_lahto_at'] ?? null, $r['sarja_lahtoaika'] ?? null] as $dt) {
        if (!is_string($dt) || formatLahtoaikaKello($dt) === '') {
            continue;
        }
        $ts = strtotime($dt);

        return $ts === false ? null : $ts;
    }

    return null;
}

/**
 * Ryhmittele lähtölista sarjoittain tai seuroittain.
 * @param list<array> $rivit
 * @return array<string, array{otsikko:string, meta:?string, rivit:array}>
 */
function ryhmitteleLahtolista(array $rivit, string $nakyma): array
{
    $nakyma = $nakyma === 'seura' ? 'seura' : 'sarja';
    $ryhmat = [];

    if ($nakyma === 'sarja') {
        foreach ($rivit as $r) {
            if (onVakanttiSarja((string) ($r['sarja'] ?? ''))) {
                continue;
            }
            $key = (string) ($r['sarja_id'] ?? $r['sarja']);
            if (!isset($ryhmat[$key])) {
                $ryhmat[$key] = [
                    'otsikko' => sarjaOtsikkoMatka((string) $r['sarja'], $r['sarja_matka'] ?? null),
                    'sarja' => (string) $r['sarja'],
                    'sarja_id' => (int) ($r['sarja_id'] ?? 0),
                    'sarja_matka' => $r['sarja_matka'] ?? null,
                    'sarja_lahtoaika' => $r['sarja_lahtoaika'] ?? null,
                    'sarja_lahto_kello' => '',
                    'seura' => null,
                    'meta' => !empty($r['lahto_nimi']) ? trim((string) $r['lahto_nimi']) : null,
                    'rivit' => [],
                ];
            }
            $ryhmat[$key]['rivit'][] = $r;
        }

        foreach ($ryhmat as &$ryhma) {
            $ryhma['sarja_lahto_kello'] = lahtolistaSarjaLahtoKello(
                $ryhma['rivit'],
                isset($ryhma['sarja_lahtoaika']) ? (string) $ryhma['sarja_lahtoaika'] : null
            );
            // Sarjoittain: lähtöaika-järjestys (ei seuran aakkosjärjestys).
            usort($ryhma['rivit'], static function (array $a, array $b): int {
                $ta = lahtolistaRivinLahtoTs($a);
                $tb = lahtolistaRivinLahtoTs($b);
                $aNull = $ta === null;
                $bNull = $tb === null;
                if ($aNull !== $bNull) {
                    return $aNull ? 1 : -1;
                }
                if ($ta !== null && $tb !== null && $ta !== $tb) {
                    return $ta <=> $tb;
                }
                $na = $a['numero'] ?? null;
                $nb = $b['numero'] ?? null;
                if (($na === null) !== ($nb === null)) {
                    return $na === null ? 1 : -1;
                }
                if ($na !== null && $nb !== null && (int) $na !== (int) $nb) {
                    return (int) $na <=> (int) $nb;
                }

                return strcasecmp((string) ($a['sukunimi'] ?? $a['nimi'] ?? ''), (string) ($b['sukunimi'] ?? $b['nimi'] ?? ''));
            });
            $ryhma['seura_ryhmat'] = lahtolistaSeuraAliryhmat($ryhma['rivit']);
        }
        unset($ryhma);

        return $ryhmat;
    }

    // Seuroittain: aakkosjärjestys, tyhjä seura loppuun
    usort($rivit, static function (array $a, array $b): int {
        $sa = trim((string) ($a['seura'] ?? ''));
        $sb = trim((string) ($b['seura'] ?? ''));
        $ea = $sa === '';
        $eb = $sb === '';
        if ($ea !== $eb) {
            return $ea ? 1 : -1;
        }
        $cmp = strcasecmp($sa, $sb);
        if ($cmp !== 0) {
            return $cmp;
        }
        $ja = (int) ($a['sarja_jarjestys'] ?? 0);
        $jb = (int) ($b['sarja_jarjestys'] ?? 0);
        if ($ja !== $jb) {
            return $ja <=> $jb;
        }
        $na = $a['numero'] ?? null;
        $nb = $b['numero'] ?? null;
        if (($na === null) !== ($nb === null)) {
            return $na === null ? 1 : -1;
        }
        if ($na !== null && $nb !== null && (int) $na !== (int) $nb) {
            return (int) $na <=> (int) $nb;
        }

        return strcasecmp((string) ($a['sukunimi'] ?? ''), (string) ($b['sukunimi'] ?? ''));
    });

    foreach ($rivit as $r) {
        $seura = trim((string) ($r['seura'] ?? ''));
        $key = $seura !== '' ? mb_strtolower($seura, 'UTF-8') : '__ei_seuraa__';
        if (!isset($ryhmat[$key])) {
            $ryhmat[$key] = [
                'otsikko' => $seura !== '' ? $seura : 'Ei seuraa',
                'sarja' => null,
                'sarja_matka' => null,
                'seura' => $seura !== '' ? $seura : null,
                'meta' => null,
                'rivit' => [],
            ];
        }
        $ryhmat[$key]['rivit'][] = $r;
    }

    return $ryhmat;
}

function kilpailuTilaLabel($tila)
{
    $labels = [
        'kaynnissa' => 'Käynnissä',
        'tulossa' => 'Tulossa',
        'ilmoittautuminen' => 'Ilmoittautuminen',
        'valmis' => 'Valmis',
        'luonnos' => 'Luonnos',
        'arkisto' => 'Arkisto',
    ];

    return $labels[$tila] ?? $tila;
}

/** Sallitut kilpailun tilat (näyttöjärjestys). Arkisto ei ole lomakevalinta. */
function kilpailuTilat(): array
{
    return ['luonnos', 'ilmoittautuminen', 'tulossa', 'kaynnissa', 'valmis'];
}

/** Varmista että tila-enum sisältää ilmoittautuminen, tulossa ja arkisto. */
function kilpailuVarmistaTilaEnum(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM kilpailut LIKE 'tila'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($col['Type'] ?? '');
        $tarvitaan = $type === ''
            || stripos($type, 'ilmoittautuminen') === false
            || stripos($type, 'tulossa') === false
            || stripos($type, 'arkisto') === false;
        if ($tarvitaan) {
            $pdo->exec("
                ALTER TABLE kilpailut
                MODIFY tila ENUM('luonnos','ilmoittautuminen','tulossa','kaynnissa','valmis','arkisto')
                DEFAULT 'luonnos'
            ");
        }
    } catch (Throwable $e) {
        // vanha DB / ei oikeuksia — ohita
    }
    $ok = true;
}

function formatPaiva($paiva)
{
    if (!$paiva) {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $paiva);

    return $dt ? $dt->format('j.n.Y') : $paiva;
}

/** Sallitut kilpailijan status-merkinnät. */
function kilpailijaStatukset(): array
{
    return ['OK', 'DNF', 'DNS', 'DSQ', 'ESITYS'];
}

function statusLabel($status)
{
    $labels = [
        'OK' => 'OK',
        'DNF' => 'DNF',
        'DNS' => 'DNS',
        'DSQ' => 'DSQ',
        'ESITYS' => 'Esitys',
        // vanhat arvot (ennen migraatiota)
        'MP' => 'DSQ',
        'RJK' => 'DSQ',
    ];

    return $labels[$status] ?? (string) $status;
}

/** Normalisoi syötetty status (OK/DNF/DNS/DSQ). */
function normalisoiKilpailijaStatus(?string $status): string
{
    $s = strtoupper(trim((string) $status));
    if ($s === 'MP' || $s === 'RJK') {
        return 'DSQ';
    }
    if (in_array($s, kilpailijaStatukset(), true)) {
        return $s;
    }

    return 'OK';
}

function haeTulokset(PDO $pdo, $kilpailu_id)
{
    $stmt = $pdo->prepare('
        SELECT
            kilpailijat.numero,
            kilpailijat.nimi,
            kilpailijat.etunimi,
            kilpailijat.sukunimi,
            kilpailijat.seura,
            kilpailijat.status,
            kilpailijat.keskhyl,
            kilpailijat.ampsakot,
            sarjat.nimi AS sarja,
            sarjat.jarjestys AS sarja_jarjestys,
            sarjat.nayta_ajat,
            ajat.maaliaika,
            ajat.aika_sec
        FROM kilpailijat
        JOIN sarjat ON kilpailijat.sarja_id = sarjat.id
        LEFT JOIN ajat ON kilpailijat.id = ajat.kilpailija_id
        WHERE kilpailijat.kilpailu_id = ?
        ORDER BY sarjat.jarjestys, sarjat.nimi, ajat.aika_sec ASC, kilpailijat.numero
    ');
    $stmt->execute([$kilpailu_id]);
    $rivit = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rivit as &$r) {
        $r['nimi'] = kilpailijaNimiRivista($r);
    }
    unset($r);

    return $rivit;
}

function ryhmitteleTulokset($tulokset)
{
    $sarjat = [];

    foreach ($tulokset as $rivi) {
        $avain = $rivi['sarja'];

        if (!isset($sarjat[$avain])) {
            $sarjat[$avain] = [
                'nayta_ajat' => $rivi['nayta_ajat'],
                'maalissa' => [],
                'ei_maaleissa' => [],
            ];
        }

        $kesk = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');
        $st = strtoupper(trim((string) ($rivi['status'] ?? '')));
        if ($kesk === 'E' || $st === 'ESITYS') {
            $rivi['status'] = 'ESITYS';
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($kesk === 'H' || $st === 'DSQ') {
            $rivi['status'] = 'DSQ';
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($kesk === 'K' || $st === 'DNF') {
            $rivi['status'] = 'DNF';
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($rivi['aika_sec'] && ($st === 'OK' || $kesk === 'T' || $kesk === '')) {
            $sarjat[$avain]['maalissa'][] = $rivi;
        } else {
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        }
    }

    return $sarjat;
}

function haeLiveTulokset(PDO $pdo, $kilpailu_id)
{
    $stmt = $pdo->prepare('
        SELECT
            k.id AS kilpailija_id,
            k.numero,
            k.nimi,
            k.etunimi,
            k.sukunimi,
            k.seura,
            k.status,
            k.keskhyl,
            k.rata,
            k.emit_koodi,
            k.pirila_lahto_at,
            k.ampsakot,
            s.id AS sarja_id,
            s.nimi AS sarja,
            s.matka AS sarja_matka,
            s.jarjestys AS sarja_jarjestys,
            s.nayta_ajat,
            s.valia_lkm,
            a.id AS ajat_id,
            a.maaliaika AS maaliaika,
            a.aika_sec AS maali_aika_sec,
            a.luotu AS ajat_luotu,
            v.piste AS viime_piste,
            v.aika_sec AS viime_aika_sec,
            v.sija AS viime_sija,
            v.punch_at AS viime_punch_at
        FROM kilpailijat k
        JOIN sarjat s ON k.sarja_id = s.id
        LEFT JOIN ajat a ON k.id = a.kilpailija_id
        LEFT JOIN valiajat v ON v.id = (
            SELECT v2.id
            FROM valiajat v2
            WHERE v2.kilpailija_id = k.id
            ORDER BY v2.piste DESC
            LIMIT 1
        )
        WHERE k.kilpailu_id = ?
          AND COALESCE(k.osuus, 0) = 0
        ORDER BY s.jarjestys, s.nimi, a.aika_sec ASC, v.aika_sec DESC, k.numero
    ');
    $stmt->execute([$kilpailu_id]);
    $rivit = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updAika = null;
    foreach ($rivit as &$r) {
        $r['nimi'] = kilpailijaNimiRivista($r);
        // Jos maaliaika on mutta netto puuttuu (esim. lähtö asetettu myöhemmin), laske ja tallenna.
        if ($r['maali_aika_sec'] === null && !empty($r['maaliaika']) && !empty($r['ajat_id'])) {
            $henkiloLahto = trim((string) ($r['pirila_lahto_at'] ?? ''));
            $sec = function_exists('laskeAikaSec')
                ? laskeAikaSec(
                    $pdo,
                    (int) $r['sarja_id'],
                    (string) $r['maaliaika'],
                    $henkiloLahto !== '' ? $henkiloLahto : null
                )
                : null;
            if ($sec === null && function_exists('haeSarjanLahtoaika')) {
                $lahto = $henkiloLahto !== '' ? $henkiloLahto : haeSarjanLahtoaika($pdo, (int) $r['sarja_id']);
                if ($lahto) {
                    $tsMaali = strtotime((string) $r['maaliaika']);
                    $tsLahto = strtotime((string) $lahto);
                    if ($tsMaali !== false && $tsLahto !== false && ($tsMaali - $tsLahto) >= 0) {
                        $sec = (int) ($tsMaali - $tsLahto);
                    }
                }
            }
            if ($sec !== null) {
                $r['maali_aika_sec'] = $sec;
                if ($updAika === null) {
                    $updAika = $pdo->prepare('UPDATE ajat SET aika_sec = ? WHERE id = ?');
                }
                $updAika->execute([$sec, (int) $r['ajat_id']]);
            }
        }
        if ($r['maali_aika_sec'] !== null) {
            $r['maali_aika_sec'] = (int) $r['maali_aika_sec'];
        }
    }
    unset($r);

    return $rivit;
}

/**
 * Viestikilpailun live-rivit joukkueittain (yhteensopiva ryhmitteleLiveTulokset-kanssa).
 * @return list<array>
 */
/**
 * Viestikilpailun osuusmäärä (1–30).
 */
function viestiOsuusLkm(PDO $pdo, int $kilpailu_id, int $havaittuMax = 0, int $sarjaId = 0): int
{
    if (is_readable(__DIR__ . '/viesti_sarjat.php')) {
        require_once __DIR__ . '/viesti_sarjat.php';
    }
    if (function_exists('viestiOsuusLkmSarjasta')) {
        return viestiOsuusLkmSarjasta($pdo, $kilpailu_id, $sarjaId, $havaittuMax);
    }
    $n = 0;
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(osuuksia, 0) FROM kilpailut WHERE id = ?');
        $stmt->execute([$kilpailu_id]);
        $n = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $n = 0;
    }
    $n = max($n, $havaittuMax, 1);

    return min(30, $n);
}

/**
 * Laske osuusvaihtojen sijat sarjoittain (kumulatiivinen aika).
 * Täydentää jokaisen osuuden kentät sija + osuus_sija.
 *
 * @param list<array> $tulokset
 * @return list<array>
 */
function taydennaViestiOsuusSijat(array $tulokset): array
{
    $bySarja = [];
    foreach ($tulokset as $i => $r) {
        if (empty($r['_viesti'])) {
            continue;
        }
        $sarja = (string) ($r['sarja'] ?? '');
        $bySarja[$sarja][] = $i;
    }

    foreach ($bySarja as $indeksit) {
        $maxOs = 0;
        foreach ($indeksit as $i) {
            foreach ($tulokset[$i]['osuudet'] ?? [] as $o) {
                $maxOs = max($maxOs, (int) ($o['osuus'] ?? 0));
            }
            $maxOs = max($maxOs, (int) ($tulokset[$i]['osuus_lkm'] ?? 0));
        }
        $maxOs = min(30, $maxOs);

        for ($os = 1; $os <= $maxOs; $os++) {
            $ajat = [];
            foreach ($indeksit as $i) {
                $kumul = null;
                $leg = null;
                foreach ($tulokset[$i]['osuudet'] ?? [] as $o) {
                    if ((int) ($o['osuus'] ?? 0) !== $os) {
                        continue;
                    }
                    $kumul = $o['kumul_sec'] ?? null;
                    $leg = $o['maali_aika_sec'] ?? null;
                    break;
                }
                if ($kumul !== null) {
                    $ajat[] = ['i' => $i, 'sec' => (int) $kumul, 'leg' => $leg !== null ? (int) $leg : null];
                }
            }
            usort($ajat, static fn ($a, $b) => $a['sec'] <=> $b['sec']);
            $sija = 0;
            $prev = null;
            $legSorted = $ajat;
            usort($legSorted, static function ($a, $b) {
                if ($a['leg'] === null && $b['leg'] === null) {
                    return 0;
                }
                if ($a['leg'] === null) {
                    return 1;
                }
                if ($b['leg'] === null) {
                    return -1;
                }

                return $a['leg'] <=> $b['leg'];
            });
            $legSijaMap = [];
            $ls = 0;
            $prevLeg = null;
            foreach ($legSorted as $row) {
                if ($row['leg'] === null) {
                    continue;
                }
                if ($prevLeg === null || $row['leg'] !== $prevLeg) {
                    $ls++;
                    $prevLeg = $row['leg'];
                }
                $legSijaMap[$row['i']] = $ls;
            }

            foreach ($ajat as $row) {
                if ($prev === null || $row['sec'] !== $prev) {
                    $sija++;
                    $prev = $row['sec'];
                }
                $i = $row['i'];
                foreach ($tulokset[$i]['osuudet'] as $oi => $o) {
                    if ((int) ($o['osuus'] ?? 0) !== $os) {
                        continue;
                    }
                    $tulokset[$i]['osuudet'][$oi]['sija'] = $sija;
                    $tulokset[$i]['osuudet'][$oi]['osuus_sija'] = $legSijaMap[$i] ?? null;
                }
            }
        }
    }

    return $tulokset;
}

function haeLiveViestiTulokset(PDO $pdo, $kilpailu_id): array
{
    $kilpailu_id = (int) $kilpailu_id;
    if (is_readable(__DIR__ . '/viesti_sarjat.php')) {
        require_once __DIR__ . '/viesti_sarjat.php';
        if (function_exists('viestiSarjatVarmista')) {
            viestiSarjatVarmista($pdo);
        }
    }
    $osuusLkmOletus = viestiOsuusLkm($pdo, $kilpailu_id);

    $stmt = $pdo->prepare('
        SELECT
            j.id AS joukkue_id,
            j.numero,
            j.seura,
            j.joukkue,
            s.id AS sarja_id,
            s.nimi AS sarja,
            s.matka AS sarja_matka,
            s.jarjestys AS sarja_jarjestys,
            s.nayta_ajat,
            s.valia_lkm,
            s.osuuksia AS sarja_osuuksia
        FROM joukkueet j
        JOIN sarjat s ON s.id = j.sarja_id
        WHERE j.kilpailu_id = ?
        ORDER BY s.jarjestys, s.nimi, j.numero
    ');
    $stmt->execute([$kilpailu_id]);
    $joukkueet = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $osuusStmt = $pdo->prepare('
        SELECT
            k.id AS kilpailija_id,
            k.osuus,
            k.nimi,
            k.etunimi,
            k.sukunimi,
            k.status,
            k.rata,
            k.pirila_lahto_at,
            a.aika_sec AS maali_aika_sec,
            a.luotu AS ajat_luotu,
            a.maaliaika AS maaliaika,
            v.piste AS viime_piste,
            v.aika_sec AS viime_aika_sec,
            v.sija AS viime_sija,
            v.punch_at AS viime_punch_at
        FROM kilpailijat k
        LEFT JOIN ajat a ON a.kilpailija_id = k.id
        LEFT JOIN valiajat v ON v.id = (
            SELECT v2.id
            FROM valiajat v2
            WHERE v2.kilpailija_id = k.id
            ORDER BY v2.piste DESC
            LIMIT 1
        )
        WHERE k.joukkue_id = ?
        ORDER BY k.osuus ASC
    ');

    $rivit = [];
    $havaittuMax = 0;
    foreach ($joukkueet as $j) {
        $osuusStmt->execute([(int) $j['joukkue_id']]);
        $osuudetRaw = $osuusStmt->fetchAll(PDO::FETCH_ASSOC);

        $byOsuus = [];
        foreach ($osuudetRaw as $o) {
            $os = (int) ($o['osuus'] ?? 0);
            if ($os < 1) {
                $os = 1;
            }
            if ($os > 30) {
                continue;
            }
            $byOsuus[$os] = $o;
            $havaittuMax = max($havaittuMax, $os);
        }

        $osuusLkm = min(30, max(
            (int) ($j['sarja_osuuksia'] ?? 0),
            $osuusLkmOletus,
            $havaittuMax,
            count($byOsuus),
            1
        ));

        $osuudet = [];
        $kumul = 0;
        $valmiitPakolliset = 0;
        $pakolliset = 0;
        $status = 'OK';
        $onDsq = false;
        $onDnf = false;
        $onDns = false;
        $okLkm = 0;
        $aktiivinen = null;
        $ensimLahto = null;
        $viimeAjatLuotu = null;
        $viimeMaaliaika = null;
        $viimeVa = [
            'viime_piste' => null,
            'viime_aika_sec' => null,
            'viime_sija' => null,
            'viime_punch_at' => null,
        ];

        for ($os = 1; $os <= $osuusLkm; $os++) {
            $o = $byOsuus[$os] ?? null;
            if ($o === null) {
                $osuudet[] = [
                    'kilpailija_id' => 0,
                    'osuus' => $os,
                    'nimi' => '',
                    'status' => 'OK',
                    'rata' => null,
                    'maali_aika_sec' => null,
                    'kumul_sec' => null,
                    'sija' => null,
                    'osuus_sija' => null,
                ];
                $pakolliset++;
                if ($aktiivinen === null) {
                    $aktiivinen = ['kilpailija_id' => 0, 'osuus' => $os, 'pirila_lahto_at' => null, 'rata' => null,
                        'viime_piste' => null, 'viime_aika_sec' => null, 'viime_sija' => null, 'viime_punch_at' => null];
                }
                continue;
            }

            $onimi = kilpailijaNimiRivista($o);
            $st = (string) ($o['status'] ?? 'OK');
            $legSec = $o['maali_aika_sec'] !== null ? (int) $o['maali_aika_sec'] : null;
            $kumulSec = null;

            if ($st === 'DSQ') {
                $onDsq = true;
            } elseif ($st === 'DNF') {
                $onDnf = true;
            } elseif ($st === 'DNS') {
                $onDns = true;
            } else {
                $okLkm++;
                $pakolliset++;
                if ($legSec !== null) {
                    $kumul += $legSec;
                    $kumulSec = $kumul;
                    $valmiitPakolliset++;
                } elseif ($aktiivinen === null) {
                    $aktiivinen = $o;
                }
            }

            // DNS ei kasvata kumulaatiota; DNF katkaisee yleensä viestin
            if ($st !== 'DNS' && $st !== 'DNF' && $st !== 'DSQ' && $legSec !== null) {
                // kumul jo päivitetty
            } elseif ($st === 'DNF' && $legSec !== null) {
                $kumul += $legSec;
                $kumulSec = $kumul;
            }

            $osuudet[] = [
                'kilpailija_id' => (int) $o['kilpailija_id'],
                'osuus' => $os,
                'nimi' => $onimi,
                'status' => $st,
                'rata' => $o['rata'] ?? null,
                'maali_aika_sec' => $legSec,
                'kumul_sec' => $kumulSec,
                'sija' => null,
                'osuus_sija' => null,
            ];

            if ($ensimLahto === null && !empty($o['pirila_lahto_at'])) {
                $ensimLahto = (string) $o['pirila_lahto_at'];
            }

            if ($legSec !== null) {
                if (!empty($o['ajat_luotu'])) {
                    $viimeAjatLuotu = (string) $o['ajat_luotu'];
                }
                if (!empty($o['maaliaika'])) {
                    $viimeMaaliaika = (string) $o['maaliaika'];
                }
            }

            if ($o['viime_aika_sec'] !== null) {
                $viimeVa = [
                    'viime_piste' => $o['viime_piste'] !== null ? (int) $o['viime_piste'] : null,
                    'viime_aika_sec' => (int) $o['viime_aika_sec'],
                    'viime_sija' => $o['viime_sija'] !== null ? (int) $o['viime_sija'] : null,
                    'viime_punch_at' => $o['viime_punch_at'] ?? null,
                ];
            }
        }

        if ($onDsq) {
            $status = 'DSQ';
        } elseif ($onDnf) {
            $status = 'DNF';
        } elseif ($onDns && $okLkm === 0) {
            $status = 'DNS';
        } else {
            $status = 'OK';
        }

        $valmis = ($status === 'OK' && $pakolliset > 0 && $valmiitPakolliset >= $pakolliset);
        $kokonais = $valmis ? $kumul : null;

        // Joukkueen/seuran aika = viimeisen osuuden tulos (kumul) tai DNS/DNF/DSQ
        $viimeLeg = $osuudet !== [] ? $osuudet[count($osuudet) - 1] : null;
        if ($viimeLeg !== null) {
            $viimeSt = (string) ($viimeLeg['status'] ?? 'OK');
            if (in_array($viimeSt, ['DNS', 'DNF', 'DSQ'], true)) {
                $status = $viimeSt;
                $kokonais = null;
                $valmis = false;
            } elseif (($viimeLeg['kumul_sec'] ?? null) !== null) {
                $status = 'OK';
                $kokonais = (int) $viimeLeg['kumul_sec'];
                $valmis = true;
            } else {
                // Viimeinen osuus kesken → ei lopputulosta (aiemman osuuden DNF/DSQ ei näy tässä)
                $status = 'OK';
                $kokonais = null;
                $valmis = false;
            }
        }

        $joukkueNimi = trim(($j['seura'] ?? '') . (isset($j['joukkue']) && $j['joukkue'] !== '' && $j['joukkue'] !== null
            ? ' ' . $j['joukkue']
            : ''));

        $liveLahto = ($aktiivinen['pirila_lahto_at'] ?? null) ?: $ensimLahto;
        $liveVa = $aktiivinen && !empty($aktiivinen['viime_aika_sec']) ? [
            'viime_piste' => $aktiivinen['viime_piste'] !== null ? (int) $aktiivinen['viime_piste'] : null,
            'viime_aika_sec' => $aktiivinen['viime_aika_sec'] !== null ? (int) $aktiivinen['viime_aika_sec'] : null,
            'viime_sija' => $aktiivinen['viime_sija'] !== null ? (int) $aktiivinen['viime_sija'] : null,
            'viime_punch_at' => $aktiivinen['viime_punch_at'] ?? null,
        ] : $viimeVa;

        $rivit[] = [
            'kilpailija_id' => !empty($aktiivinen['kilpailija_id'])
                ? (int) $aktiivinen['kilpailija_id']
                : (int) ($osuudet[count($osuudet) - 1]['kilpailija_id'] ?? $j['joukkue_id']),
            'joukkue_id' => (int) $j['joukkue_id'],
            'numero' => $j['numero'],
            'nimi' => $joukkueNimi !== '' ? $joukkueNimi : ('Joukkue ' . (int) $j['numero']),
            'etunimi' => null,
            'sukunimi' => null,
            'seura' => $j['seura'] ?? '',
            'status' => $status,
            'rata' => $aktiivinen['rata'] ?? ($osuudet[count($osuudet) - 1]['rata'] ?? null),
            'emit_koodi' => null,
            'pirila_lahto_at' => $liveLahto,
            'sarja_id' => (int) $j['sarja_id'],
            'sarja' => $j['sarja'],
            'sarja_matka' => $j['sarja_matka'] ?? null,
            'sarja_jarjestys' => $j['sarja_jarjestys'] ?? null,
            'nayta_ajat' => $j['nayta_ajat'],
            'valia_lkm' => (int) ($j['valia_lkm'] ?? 0),
            'maali_aika_sec' => $kokonais,
            'maaliaika' => $viimeMaaliaika,
            'ajat_luotu' => $viimeAjatLuotu,
            'viime_piste' => $liveVa['viime_piste'],
            'viime_aika_sec' => $liveVa['viime_aika_sec'],
            'viime_sija' => $liveVa['viime_sija'],
            'viime_punch_at' => $liveVa['viime_punch_at'],
            'osuudet' => $osuudet,
            'osuus_lkm' => $osuusLkm,
            '_viesti' => true,
        ];
    }

    if ($havaittuMax > $osuusLkmOletus) {
        try {
            $pdo->prepare('
                UPDATE kilpailut
                SET osuuksia = GREATEST(COALESCE(osuuksia, 0), ?)
                WHERE id = ?
            ')->execute([min(30, $havaittuMax), $kilpailu_id]);
        } catch (Throwable $e) {
            // ohita
        }
    }

    return taydennaViestiOsuusSijat($rivit);
}

function ryhmitteleLiveTulokset($tulokset)
{
    $sarjat = [];

    foreach ($tulokset as $rivi) {
        $avain = $rivi['sarja'];
        if (onVakanttiSarja(is_string($avain) ? $avain : (string) $avain)) {
            continue;
        }

        if (!isset($sarjat[$avain])) {
            $sarjat[$avain] = [
                'nayta_ajat' => $rivi['nayta_ajat'],
                'valia_lkm' => (int) ($rivi['valia_lkm'] ?? 0),
                'osuus_lkm' => (int) ($rivi['osuus_lkm'] ?? 0),
                'sarja_id' => (int) ($rivi['sarja_id'] ?? 0),
                'matka' => trim((string) ($rivi['sarja_matka'] ?? '')),
                'maalissa' => [],
                'maastossa' => [],
                'ei_maaleissa' => [],
            ];
        }
        $sarjat[$avain]['osuus_lkm'] = max(
            (int) ($sarjat[$avain]['osuus_lkm'] ?? 0),
            (int) ($rivi['osuus_lkm'] ?? 0),
            count($rivi['osuudet'] ?? [])
        );

        $kesk = mb_strtoupper(mb_substr(trim((string) ($rivi['keskhyl'] ?? '')), 0, 1), 'UTF-8');
        $st = strtoupper(trim((string) ($rivi['status'] ?? '')));
        if ($kesk === 'E' || $st === 'ESITYS') {
            $rivi['status'] = 'ESITYS';
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($kesk === 'H' || $st === 'DSQ') {
            $rivi['status'] = 'DSQ';
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($kesk === 'K' || $st === 'DNF') {
            $rivi['status'] = 'DNF';
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($st === 'OK' && ($rivi['maali_aika_sec'] !== null || !empty($rivi['maaliaika']))) {
            $sarjat[$avain]['maalissa'][] = $rivi;
        } elseif ($kesk === 'T') {
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        } elseif ($st === 'OK' || $kesk === '-' || $kesk === '') {
            $sarjat[$avain]['maastossa'][] = $rivi;
        } else {
            $sarjat[$avain]['ei_maaleissa'][] = $rivi;
        }
    }

    return $sarjat;
}

/**
 * Viestin yhden osuuden rivit joukkuevertailusta (osuuskohtainen tuloslista).
 *
 * @param list<array> $vertailu
 * @return list<array>
 */
function viestiOsuusRivit(array $vertailu, int $osuus): array
{
    if ($osuus < 1) {
        return [];
    }

    $out = [];
    foreach ($vertailu as $r) {
        $leg = null;
        foreach ($r['osuudet'] ?? [] as $o) {
            if ((int) ($o['osuus'] ?? 0) === $osuus) {
                $leg = $o;
                break;
            }
        }
        if ($leg === null) {
            continue;
        }

        $ost = (string) ($leg['status'] ?? 'OK');
        $legSec = $leg['maali_aika_sec'] ?? null;
        $onStatus = in_array($ost, ['DNS', 'DNF', 'DSQ'], true);
        $maalissa = !$onStatus && $legSec !== null;

        $out[] = [
            'kilpailija_id' => (int) ($leg['kilpailija_id'] ?? 0),
            'joukkue_id' => (int) ($r['joukkue_id'] ?? 0),
            'numero' => $r['numero'] ?? null,
            'nimi' => trim((string) ($leg['nimi'] ?? '')),
            'etunimi' => null,
            'sukunimi' => null,
            'joukkue' => (string) ($r['nimi'] ?? ''),
            'seura' => (string) ($r['seura'] ?? ''),
            'status' => $ost,
            'rata' => $leg['rata'] ?? null,
            'maali_aika_sec' => $legSec !== null ? (int) $legSec : null,
            'kumul_sec' => isset($leg['kumul_sec']) && $leg['kumul_sec'] !== null ? (int) $leg['kumul_sec'] : null,
            'osuus_sija' => $leg['osuus_sija'] ?? null,
            'vaihto_sija' => $leg['sija'] ?? null,
            'osuus' => $osuus,
            'sarja_id' => (int) ($r['sarja_id'] ?? 0),
            'sarja' => $r['sarja'] ?? null,
            'nayta_ajat' => $r['nayta_ajat'] ?? true,
            'pirila_lahto_at' => $r['pirila_lahto_at'] ?? null,
            'viime_piste' => $r['viime_piste'] ?? null,
            'viime_aika_sec' => $r['viime_aika_sec'] ?? null,
            'viime_sija' => $r['viime_sija'] ?? null,
            'viime_punch_at' => $r['viime_punch_at'] ?? null,
            '_maalissa' => $maalissa,
            '_status' => $onStatus,
            '_viesti' => true,
            '_viesti_osuus' => $osuus,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $aOk = !empty($a['_maalissa']);
        $bOk = !empty($b['_maalissa']);
        if ($aOk !== $bOk) {
            return $aOk ? -1 : 1;
        }
        if ($aOk) {
            $as = $a['osuus_sija'] ?? null;
            $bs = $b['osuus_sija'] ?? null;
            if ($as !== null && $bs !== null && (int) $as !== (int) $bs) {
                return (int) $as <=> (int) $bs;
            }
            $at = $a['maali_aika_sec'] ?? null;
            $bt = $b['maali_aika_sec'] ?? null;
            if ($at !== null && $bt !== null && (int) $at !== (int) $bt) {
                return (int) $at <=> (int) $bt;
            }
        }
        $aSt = !empty($a['_status']);
        $bSt = !empty($b['_status']);
        if ($aSt !== $bSt) {
            return $aSt ? 1 : -1;
        }

        return ((int) ($a['numero'] ?? 0)) <=> ((int) ($b['numero'] ?? 0));
    });

    return $out;
}

/**
 * Online-väliaikapisteiden numerot sarjalle (1..N).
 * Pirilä: online-VA = ApiVaNva / p_aika ilman rasti_koodia.
 * Emit-leimat (laskeemitvaliajat + rasti_koodi) → rastiväliajat-näkymä, ei tähän.
 *
 * Ensisijaisesti oikeat online-leimat DB:stä. valia_lkm vain jos ei Emit-RADAT-saastetta
 * (valia_lkm voi olla radan rastimäärä).
 */
function haeSarjanOnlinePisteet(PDO $pdo, int $sarja_id, int $valia_lkm = 0): array
{
    $onlineMax = 0;
    $emitMax = 0;
    try {
        $stmt = $pdo->prepare('
            SELECT
                COALESCE(MAX(CASE
                    WHEN v.rasti_koodi IS NULL OR v.rasti_koodi = \'\' THEN v.piste
                    ELSE NULL
                END), 0) AS online_max,
                COALESCE(MAX(CASE
                    WHEN v.rasti_koodi IS NOT NULL AND v.rasti_koodi <> \'\' THEN v.piste
                    ELSE NULL
                END), 0) AS emit_max
            FROM valiajat v
            INNER JOIN kilpailijat k ON k.id = v.kilpailija_id
            WHERE k.sarja_id = ?
              AND v.piste > 0
              AND v.aika_sec IS NOT NULL
        ');
        $stmt->execute([$sarja_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $onlineMax = (int) ($row['online_max'] ?? 0);
        $emitMax = (int) ($row['emit_max'] ?? 0);
    } catch (Throwable $e) {
        // Vanha skeema ilman rasti_koodia: kaikki pisteet ovat "online"
        try {
            $stmt = $pdo->prepare('
                SELECT COALESCE(MAX(v.piste), 0)
                FROM valiajat v
                INNER JOIN kilpailijat k ON k.id = v.kilpailija_id
                WHERE k.sarja_id = ?
                  AND v.piste > 0
                  AND v.aika_sec IS NOT NULL
            ');
            $stmt->execute([$sarja_id]);
            $onlineMax = (int) $stmt->fetchColumn();
        } catch (Throwable $e2) {
            $onlineMax = 0;
        }
    }

    if ($valia_lkm <= 0) {
        $stmt = $pdo->prepare('SELECT COALESCE(valia_lkm, 0) FROM sarjat WHERE id = ?');
        $stmt->execute([$sarja_id]);
        $valia_lkm = (int) $stmt->fetchColumn();
    }

    $n = 0;
    if ($onlineMax > 0) {
        // Online-leimoja on: näytä ne (+ valia_lkm jos odottaa lisää pisteitä)
        $n = max($onlineMax, $valia_lkm);
    } elseif ($valia_lkm > 0 && $emitMax <= 0) {
        // Ei vielä leimoja, mutta admin/tapahtuma asetti online-pisteet (ei Emit-rataa)
        $n = $valia_lkm;
    }
    // else: vain Emit-leimoja / RADAT-valia_lkm → ei online-sarakkeita

    if ($n <= 0) {
        return [];
    }

    if ($n > 60) {
        $n = 60;
    }

    return range(1, $n);
}

/**
 * Rastikohtainen väliaika (sek): nykyinen kokonaisaika − edellisen rastin kokonaisaika.
 * Ensimmäisellä rastilla null.
 *
 * @param array<int, array{aika_sec:?int}> $omatVa piste => data
 * @param list<int> $pisteet
 * @return array<int, ?int>
 */
function laskeRastiValiajat(array $omatVa, array $pisteet): array
{
    $split = [];
    $prev = null;

    foreach ($pisteet as $piste) {
        $piste = (int) $piste;
        if (!isset($omatVa[$piste]) || $omatVa[$piste]['aika_sec'] === null) {
            $split[$piste] = null;
            continue;
        }

        $sec = (int) $omatVa[$piste]['aika_sec'];
        if ($prev === null) {
            $split[$piste] = null;
        } else {
            $split[$piste] = max(0, $sec - $prev);
        }
        $prev = $sec;
    }

    return $split;
}

/**
 * Rastivälien (leg) sijat sarjan juoksijoille verrattuna toisiinsa.
 *
 * @param array<int, array<int, array{aika_sec:?int,sija:?int}>> $valiaKartta
 * @param list<int> $pisteet
 * @return array<int, array<int, array{split_sec:?int,sija:?int}>>
 */
function laskeRastiValiajatSijat(array $valiaKartta, array $pisteet): array
{
    $out = [];
    $splitsByKid = [];

    foreach ($valiaKartta as $kid => $pisteData) {
        $kid = (int) $kid;
        $splitsByKid[$kid] = laskeRastiValiajat($pisteData, $pisteet);
        foreach ($pisteet as $piste) {
            $piste = (int) $piste;
            $out[$kid][$piste] = [
                'split_sec' => $splitsByKid[$kid][$piste] ?? null,
                'sija' => null,
            ];
        }
    }

    foreach ($pisteet as $piste) {
        $piste = (int) $piste;
        $rivit = [];

        foreach ($splitsByKid as $kid => $splits) {
            $sec = $splits[$piste] ?? null;
            if ($sec === null || $sec <= 0) {
                continue;
            }
            $rivit[] = ['kid' => (int) $kid, 'sec' => (int) $sec];
        }

        if ($rivit === []) {
            continue;
        }

        usort($rivit, static fn($a, $b) => $a['sec'] <=> $b['sec']);

        $sija = 0;
        $edellinenSec = null;

        foreach ($rivit as $i => $r) {
            if ($edellinenSec === null || $r['sec'] > $edellinenSec) {
                $sija = $i + 1;
            }
            $edellinenSec = $r['sec'];
            $out[$r['kid']][$piste]['sija'] = $sija;
        }
    }

    // Ensimmäisellä rastilla: rastiväli = sama kuin tilanne rastilla (ylärivi).
    if ($pisteet !== []) {
        $firstPiste = (int) $pisteet[0];
        foreach ($valiaKartta as $kid => $pisteData) {
            $kid = (int) $kid;
            if (!isset($pisteData[$firstPiste]) || $pisteData[$firstPiste]['aika_sec'] === null) {
                continue;
            }
            $out[$kid][$firstPiste]['split_sec'] = (int) $pisteData[$firstPiste]['aika_sec'];
            // Sija = kumulatiivinen sija (laskeValiajatSijat) jos saatavilla, muuten jo laskettu väli.
            if (!empty($pisteData[$firstPiste]['sija'])) {
                $out[$kid][$firstPiste]['sija'] = (int) $pisteData[$firstPiste]['sija'];
            }
        }
    }

    return $out;
}

/**
 * Laskee kumulatiivisten väliaikojen sijoitukset ajan mukaan (jokaisella sivulatauksella).
 *
 * @param array<int, array<int, array{aika_sec:?int,sija:?int}>> $valiaKartta
 * @param list<int> $pisteet
 * @return array<int, array<int, array{aika_sec:?int,sija:?int}>>
 */
function laskeValiajatSijat(array $valiaKartta, array $pisteet): array
{
    $out = $valiaKartta;

    // Nollaa vanhat sijat, jotta laskenta on aina ajanmukainen.
    foreach ($out as $kid => $pisteData) {
        foreach ($pisteData as $piste => $va) {
            $out[$kid][$piste]['sija'] = null;
        }
    }

    foreach ($pisteet as $piste) {
        $piste = (int) $piste;
        $rivit = [];

        foreach ($valiaKartta as $kid => $pisteData) {
            if (!isset($pisteData[$piste]) || $pisteData[$piste]['aika_sec'] === null) {
                continue;
            }
            $rivit[] = ['kid' => (int) $kid, 'sec' => (int) $pisteData[$piste]['aika_sec']];
        }

        if ($rivit === []) {
            continue;
        }

        usort($rivit, static fn($a, $b) => $a['sec'] <=> $b['sec']);

        $sija = 0;
        $edellinenSec = null;

        foreach ($rivit as $i => $r) {
            if ($edellinenSec === null || $r['sec'] > $edellinenSec) {
                $sija = $i + 1;
            }
            $edellinenSec = $r['sec'];

            if (!isset($out[$r['kid']][$piste])) {
                $out[$r['kid']][$piste] = ['aika_sec' => $r['sec'], 'sija' => null];
            }
            $out[$r['kid']][$piste]['sija'] = $sija;
        }
    }

    return $out;
}

/**
 * Vertaa kilpailijan rastivälejä sarjan parhaaseen → heikoimmat / vahvimmat.
 *
 * @param array<int, array<int, array{aika_sec:?int,sija:?int}>> $valiaKartta
 * @param array<int, array<int, array{split_sec:?int,sija:?int}>> $vaSplitSijat
 * @param list<int> $pisteet
 * @return array{
 *   heikoimmat: list<array{piste:int,ero:int,sija:?int,split:?int}>,
 *   vahvimmat: list<array{piste:int,ero:int,sija:?int,split:?int}>,
 *   keski_sija:?float,
 *   lkm:int
 * }|null
 */
function laskeKilpailijaValiajatAnalytiikka(
    int $kilpailijaId,
    array $valiaKartta,
    array $vaSplitSijat,
    array $pisteet
): ?array {
    if ($pisteet === [] || !isset($vaSplitSijat[$kilpailijaId])) {
        return null;
    }

    $omat = $vaSplitSijat[$kilpailijaId];
    $erot = [];

    foreach ($pisteet as $piste) {
        $piste = (int) $piste;
        $oma = $omat[$piste]['split_sec'] ?? null;
        if ($oma === null || (int) $oma <= 0) {
            continue;
        }
        $oma = (int) $oma;

        $paras = null;
        foreach ($vaSplitSijat as $kid => $splits) {
            $sec = $splits[$piste]['split_sec'] ?? null;
            if ($sec === null || (int) $sec <= 0) {
                continue;
            }
            $sec = (int) $sec;
            if ($paras === null || $sec < $paras) {
                $paras = $sec;
            }
        }
        if ($paras === null) {
            continue;
        }

        $erot[] = [
            'piste' => $piste,
            'ero' => $oma - $paras,
            'sija' => isset($omat[$piste]['sija']) ? (int) $omat[$piste]['sija'] : null,
            'split' => $oma,
        ];
    }

    if ($erot === []) {
        return null;
    }

    $heikot = $erot;
    usort($heikot, static fn ($a, $b) => $b['ero'] <=> $a['ero']);
    $vahvat = $erot;
    usort($vahvat, static fn ($a, $b) => $a['ero'] <=> $b['ero']);

    $sijat = array_values(array_filter(
        array_map(static fn ($e) => $e['sija'], $erot),
        static fn ($s) => $s !== null && $s > 0
    ));
    $keski = $sijat !== [] ? array_sum($sijat) / count($sijat) : null;

    return [
        'heikoimmat' => array_slice($heikot, 0, 3),
        'vahvimmat' => array_slice($vahvat, 0, 3),
        'keski_sija' => $keski,
        'lkm' => count($erot),
    ];
}

/**
 * Tiivis analytiikkarivi kilpailijan alle (seuranta / väliajat).
 */
function formatValiajatAnalytiikkaHtml(?array $analytiikka, string $mode = 'compact'): string
{
    if ($analytiikka === null || empty($analytiikka['heikoimmat'])) {
        return '';
    }

    $fmtEro = static function (int $ero): string {
        if ($ero === 0) {
            return '±0';
        }
        $merkki = $ero > 0 ? '+' : '−';
        return $merkki . formatAikaSecPirila(abs($ero));
    };

    $items = [];
    foreach ($analytiikka['heikoimmat'] as $h) {
        if ((int) $h['ero'] <= 0) {
            continue;
        }
        $items[] = [
            'cls' => 'is-heikko',
            'label' => 'R' . (int) $h['piste'] . ' ' . $fmtEro((int) $h['ero']),
            'title' => 'Heikoin väli vs. sarjan paras',
        ];
    }
    foreach ($analytiikka['vahvimmat'] as $v) {
        if ((int) $v['ero'] > 0) {
            continue;
        }
        // Älä toista samaa rastia heikko-listasta
        $dup = false;
        foreach ($analytiikka['heikoimmat'] as $h) {
            if ((int) $h['piste'] === (int) $v['piste']) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            continue;
        }
        $items[] = [
            'cls' => 'is-vahva',
            'label' => 'R' . (int) $v['piste'] . ' ' . $fmtEro((int) $v['ero']),
            'title' => 'Vahvin väli vs. sarjan paras',
        ];
        if (count($items) >= 4) {
            break;
        }
    }

    if ($items === []) {
        return '';
    }

    $html = '<div class="lb-analytiikka lb-analytiikka--' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '">';
    if ($mode === 'full') {
        $html .= '<div class="lb-analytiikka-otsikko">Analytiikka <span class="lb-muted">(vs. sarjan paras rastiväli)</span></div>';
    }
    $html .= '<div class="lb-analytiikka-rivit">';
    foreach ($items as $it) {
        $html .= '<span class="lb-anal-chip ' . $it['cls'] . '" title="'
            . htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
    if ($mode === 'full' && $analytiikka['keski_sija'] !== null) {
        $html .= '<span class="lb-anal-chip is-meta">keskisija '
            . htmlspecialchars(number_format((float) $analytiikka['keski_sija'], 1, ',', ''), ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
    $html .= '</div></div>';

    return $html;
}

/**
 * HTML: solu — "(6) 1:01" (sijoitus suluissa) tai rastiväliaika.
 */
function formatLbVaAikaHtml(?array $va, ?int $splitSec = null, ?int $splitSija = null): string
{
    $sijaHtml = static function (?int $sija): string {
        if ($sija !== null && $sija > 0) {
            return '<span class="lb-va-sija">(' . (int) $sija . ')</span>';
        }

        return '<span class="lb-va-sija lb-va-sija--empty" aria-hidden="true"></span>';
    };

    $cellOpen = static function (?int $sija): string {
        $cls = 'lb-va-cell';
        if ($sija === 1) {
            $cls .= ' is-nopein';
        }

        return '<span class="' . $cls . '">';
    };

    $aikaHtml = static function (int $sec): string {
        return '<span class="lb-va-aika">'
            . htmlspecialchars(formatAikaSecPirila($sec), ENT_QUOTES, 'UTF-8')
            . '</span>';
    };

    if ($splitSec !== null) {
        if ($splitSec <= 0) {
            return $cellOpen(null) . $sijaHtml(null) . '<span class="lb-tag">—</span></span>';
        }

        return $cellOpen($splitSija)
            . $sijaHtml($splitSija)
            . $aikaHtml($splitSec)
            . '</span>';
    }

    if ($va === null || $va['aika_sec'] === null) {
        return $cellOpen(null) . $sijaHtml(null) . '<span class="lb-tag">—</span></span>';
    }

    $sija = !empty($va['sija']) ? (int) $va['sija'] : null;

    return $cellOpen($sija)
        . $sijaHtml($sija)
        . $aikaHtml((int) $va['aika_sec'])
        . '</span>';
}

/**
 * Sarjan paras rastiväliaika pisteittäin.
 *
 * @param array<int, array<int, array{split_sec:?int,sija:?int}>> $vaSplitSijat
 * @param list<int> $pisteet
 * @return array<int, int> piste => paras split_sec
 */
function haeParhaatRastiValit(array $vaSplitSijat, array $pisteet): array
{
    $paras = [];
    foreach ($pisteet as $piste) {
        $piste = (int) $piste;
        $best = null;
        foreach ($vaSplitSijat as $splits) {
            $sec = $splits[$piste]['split_sec'] ?? null;
            if ($sec === null || (int) $sec <= 0) {
                continue;
            }
            $sec = (int) $sec;
            if ($best === null || $sec < $best) {
                $best = $sec;
            }
        }
        if ($best !== null) {
            $paras[$piste] = $best;
        }
    }

    return $paras;
}

/**
 * Muotoile matka metreistä (esim. "172 m" / "2,0 km").
 */
function formatMatkaMetreit(int $meters): string
{
    if ($meters <= 0) {
        return '';
    }
    if ($meters >= 1000) {
        return str_replace('.', ',', sprintf('%.1f km', $meters / 1000));
    }

    return $meters . ' m';
}

/**
 * Kilometrivauhti: sekunnit + metrit → "5:23/km".
 */
function formatKmVauhti(?int $sec, ?int $meters): string
{
    if ($sec === null || $sec <= 0 || $meters === null || $meters <= 0) {
        return '';
    }
    $secPerKm = ($sec / $meters) * 1000.0;
    if ($secPerKm <= 0 || $secPerKm > 3600 * 3) {
        return '';
    }
    $min = (int) floor($secPerKm / 60);
    $s = (int) round($secPerKm - ($min * 60));
    if ($s >= 60) {
        $min++;
        $s = 0;
    }

    return sprintf('%d:%02d/km', $min, $s);
}

/**
 * Kumulatiivinen matka (m) lähdöstä pisteittäin: summa leg_length 1..p.
 *
 * @param array<int, int> $legByPiste piste => leg_length_m
 * @param int|null $finishLegM viimeinen väli maaliin (m), jos piste > max rata-piste
 * @return array<int, int> piste => kumulatiivinen matka m
 */
function haeKumulatiivisetMatkatPisteittain(array $legByPiste, ?int $finishLegM = null): array
{
    if ($legByPiste === []) {
        return [];
    }

    $maxPiste = max(array_map('intval', array_keys($legByPiste)));
    $cum = [];
    $sum = 0;

    for ($p = 1; $p <= $maxPiste; $p++) {
        if (isset($legByPiste[$p]) && (int) $legByPiste[$p] > 0) {
            $sum += (int) $legByPiste[$p];
        }
        if ($sum > 0) {
            $cum[$p] = $sum;
        }
    }

    if ($finishLegM !== null && $finishLegM > 0 && $sum > 0) {
        $cum[$maxPiste + 1] = $sum + $finishLegM;
    }

    return $cum;
}

/**
 * Ero parhaaseen rastiväliin ("+0:12" tai tyhjä jos paras / puuttuu).
 */
function formatEroParhaaseenRastiValiin(?int $omaSec, ?int $bestSec): string
{
    if ($omaSec === null || $omaSec <= 0 || $bestSec === null || $bestSec <= 0) {
        return '';
    }
    if ($omaSec <= $bestSec) {
        return '0';
    }

    return '+' . formatAikaSecPirila($omaSec - $bestSec);
}

/**
 * Heatmap-luokka suhteessa sarjan parhaaseen rastiväliin.
 */
function rastiValiHeatClass(?int $omaSec, ?int $bestSec): string
{
    if ($omaSec === null || $omaSec <= 0 || $bestSec === null || $bestSec <= 0) {
        return '';
    }
    if ($omaSec <= $bestSec) {
        return 'is-heat-best';
    }
    $ratio = $omaSec / $bestSec;
    if ($ratio < 1.05) {
        return 'is-heat-good';
    }
    if ($ratio < 1.15) {
        return 'is-heat-ok';
    }
    if ($ratio < 1.30) {
        return 'is-heat-slow';
    }

    return 'is-heat-bad';
}

/**
 * Sarjan radan pituus + maaliin-väli (RADAT1).
 *
 * @return array{course_length_m:?int,finish_leg_length_m:?int,matka:?string}
 */
function haeSarjanRadanPituudet(PDO $pdo, int $sarja_id): array
{
    $empty = ['course_length_m' => null, 'finish_leg_length_m' => null, 'matka' => null];
    if ($sarja_id <= 0) {
        return $empty;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT course_length_m, finish_leg_length_m, matka
            FROM sarjat
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$sarja_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->prepare('SELECT course_length_m, matka FROM sarjat WHERE id = ? LIMIT 1');
            $stmt->execute([$sarja_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['finish_leg_length_m'] = null;
            }
        } catch (PDOException $e2) {
            try {
                $stmt = $pdo->prepare('SELECT matka FROM sarjat WHERE id = ? LIMIT 1');
                $stmt->execute([$sarja_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['course_length_m'] = null;
                    $row['finish_leg_length_m'] = null;
                }
            } catch (PDOException $e3) {
                return $empty;
            }
        }
    }
    if (!$row) {
        return $empty;
    }
    $course = isset($row['course_length_m']) && $row['course_length_m'] !== null && (int) $row['course_length_m'] > 0
        ? (int) $row['course_length_m']
        : null;
    $finish = isset($row['finish_leg_length_m']) && $row['finish_leg_length_m'] !== null && (int) $row['finish_leg_length_m'] > 0
        ? (int) $row['finish_leg_length_m']
        : null;

    return [
        'course_length_m' => $course,
        'finish_leg_length_m' => $finish,
        'matka' => isset($row['matka']) ? trim((string) $row['matka']) : null,
    ];
}

/**
 * HTML: kumulatiivinen + rastiväli samassa sarakkeessa (ylhäällä tilanne, alla väli).
 */
function formatLbVaStackHtml(
    ?array $va,
    ?int $splitSec = null,
    ?int $splitSija = null
): string {
    $html = '<span class="lb-va-stack">';
    $html .= '<span class="lb-va-stack-kok">' . formatLbVaAikaHtml($va) . '</span>';

    if ($splitSec !== null && $splitSec > 0) {
        $html .= '<span class="lb-va-stack-leg">'
            . formatLbVaAikaHtml(null, $splitSec, $splitSija)
            . '</span>';
    }

    $html .= '</span>';

    return $html;
}

/**
 * Sarjan rastit RADAT1 / sarja_rastit -taulusta, tai radan rastilistasta.
 *
 * @return list<array{piste:int,rasti_koodi:string,jarjestys:int}>
 */
function rastijonotSamat(array $a, array $b): bool
{
    if (count($a) !== count($b)) {
        return false;
    }
    foreach ($a as $i => $r) {
        $ak = trim((string) ($r['rasti_koodi'] ?? $r['koodi'] ?? ''));
        $bk = trim((string) ($b[$i]['rasti_koodi'] ?? $b[$i]['koodi'] ?? ''));
        if ($ak !== $bk) {
            return false;
        }
    }

    return true;
}

function haeSarjanRastit(PDO $pdo, int $sarja_id): array
{
    if (!function_exists('pirilaHaeSarjaRastit')) {
        require_once __DIR__ . '/pirila_radat.php';
    }

    $sarja = pirilaHaeSarjaRastit($pdo, $sarja_id);
    $rata = haeSarjanRastitRadalta($pdo, $sarja_id);
    if ($rata !== [] && !rastijonotSamat($rata, $sarja)) {
        return $rata;
    }
    if ($sarja !== []) {
        return $sarja;
    }

    return $rata;
}

/**
 * Kun sarja_rastit on tyhjä, käytä radat + rata_rastit (iltarastin silmukkarata).
 *
 * @return list<array{piste:int,rasti_koodi:string,jarjestys:int,unit_koodit:list<string>,leg_length_m:?int}>
 */
function haeSarjanRastitRadalta(PDO $pdo, int $sarja_id): array
{
    if ($sarja_id <= 0) {
        return [];
    }
    if (function_exists('radatVarmistaSammunutSarake')) {
        radatVarmistaSammunutSarake($pdo);
    }
    try {
        $stmt = $pdo->prepare('
            SELECT rr.jarjestys AS piste, rr.rasti_koodi, rr.jarjestys, rr.kumul_m, rr.sammunut
            FROM sarjat s
            JOIN radat r ON r.kilpailu_id = s.kilpailu_id AND r.tunnus = s.rata_nimi
            JOIN rata_rastit rr ON rr.rata_id = r.id
            WHERE s.id = ?
            ORDER BY rr.jarjestys ASC
        ');
        $stmt->execute([$sarja_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    $edellinen = 0;
    foreach ($rows as $r) {
        $code = (string) $r['rasti_koodi'];
        $piste = (int) $r['piste'];
        $kum = $r['kumul_m'] === null || $r['kumul_m'] === '' ? null : (int) $r['kumul_m'];
        $leg = null;
        if ($kum !== null && $kum > $edellinen) {
            $leg = $kum - $edellinen;
            $edellinen = $kum;
        }
        $out[] = [
            'piste' => $piste,
            'rasti_koodi' => $code,
            'unit_koodit' => [$code],
            'jarjestys' => (int) $r['jarjestys'],
            'leg_length_m' => ($leg !== null && $leg > 0) ? $leg : null,
            'sammunut' => !empty($r['sammunut']) ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * Rastiväliaikojen pistenumerot annetuille kilpailijoille (Emit-leimat).
 * Kun RADAT1 / sarja_rastit puuttuu, näytä kaikki leimatut pisteet.
 *
 * @param list<int> $kilpailijaIds
 * @return list<int>
 */
function haeKilpailijoidenRastiPisteet(PDO $pdo, array $kilpailijaIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $kilpailijaIds))));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT piste
        FROM valiajat
        WHERE kilpailija_id IN ($placeholders)
          AND piste > 0
          AND aika_sec IS NOT NULL
        ORDER BY piste
    ");
    $stmt->execute($ids);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Viestin rastiväliajan rivit: yksi osuus tai kaikki osuudet litistettynä.
 *
 * @param list<array> $vertailu
 * @return list<array>
 */
function viestiValiajatRivit(array $vertailu, int $osuus = 0): array
{
    if ($osuus > 0) {
        return viestiOsuusRivit($vertailu, $osuus);
    }

    $maxOs = 0;
    foreach ($vertailu as $r) {
        $maxOs = max(
            $maxOs,
            (int) ($r['osuus_lkm'] ?? 0),
            count($r['osuudet'] ?? [])
        );
        foreach ($r['osuudet'] ?? [] as $o) {
            $maxOs = max($maxOs, (int) ($o['osuus'] ?? 0));
        }
    }
    $maxOs = min(30, max(0, $maxOs));
    if ($maxOs < 1) {
        return [];
    }

    $out = [];
    for ($os = 1; $os <= $maxOs; $os++) {
        foreach (viestiOsuusRivit($vertailu, $os) as $row) {
            $out[] = $row;
        }
    }

    // Tuloksen mukaan (ei joukkuenumeroittain): maalissa → maastossa → status
    usort($out, static function (array $a, array $b): int {
        $aOk = !empty($a['_maalissa']);
        $bOk = !empty($b['_maalissa']);
        if ($aOk !== $bOk) {
            return $aOk ? -1 : 1;
        }
        if ($aOk) {
            $at = $a['maali_aika_sec'] ?? null;
            $bt = $b['maali_aika_sec'] ?? null;
            if ($at !== null && $bt !== null && (int) $at !== (int) $bt) {
                return (int) $at <=> (int) $bt;
            }
            $as = $a['osuus_sija'] ?? null;
            $bs = $b['osuus_sija'] ?? null;
            if ($as !== null && $bs !== null && (int) $as !== (int) $bs) {
                return (int) $as <=> (int) $bs;
            }
        }
        $aSt = !empty($a['_status']);
        $bSt = !empty($b['_status']);
        if ($aSt !== $bSt) {
            return $aSt ? 1 : -1;
        }
        $osCmp = ((int) ($a['osuus'] ?? 0)) <=> ((int) ($b['osuus'] ?? 0));
        if ($osCmp !== 0) {
            return $osCmp;
        }

        return ((int) ($a['numero'] ?? 0)) <=> ((int) ($b['numero'] ?? 0));
    });

    return $out;
}

/**
 * Rastiväliaikojen pistenumerot sarjalle (Emit / Pirilä-synkka).
 * Ensisijaisesti RADAT1-radan mukaiset sarakkeet; muuten DISTINCT leimatut pisteet.
 *
 * @param list<int>|null $kilpailijaIds Jos annettu, DISTINCT vain näille (osuuskohtainen Emit).
 */
function haeSarjanRastiPisteet(PDO $pdo, int $sarja_id, ?array $kilpailijaIds = null): array
{
    $rastit = haeSarjanRastit($pdo, $sarja_id);
    if ($rastit !== []) {
        return array_map(static fn ($r) => (int) $r['piste'], $rastit);
    }

    if ($kilpailijaIds !== null) {
        return haeKilpailijoidenRastiPisteet($pdo, $kilpailijaIds);
    }

    $stmt = $pdo->prepare('
        SELECT DISTINCT v.piste
        FROM valiajat v
        JOIN kilpailijat k ON k.id = v.kilpailija_id
        WHERE k.sarja_id = ?
          AND v.piste > 0
          AND v.aika_sec IS NOT NULL
        ORDER BY v.piste
    ');
    $stmt->execute([$sarja_id]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param bool $vainOnline Jos true, vain Pirilä online-VA (ei rasti_koodia / Emit).
 * @return array<int, array<int, array{aika_sec:?int,sija:?int}>> kilpailija_id => piste => data
 */
function haeValiajatKartta(PDO $pdo, array $kilpailijaIds, bool $vainOnline = false): array
{
    $kartta = [];

    if (empty($kilpailijaIds)) {
        return $kartta;
    }

    $ids = array_values(array_unique(array_map('intval', $kilpailijaIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $onlineEhto = $vainOnline
        ? ' AND (rasti_koodi IS NULL OR rasti_koodi = \'\')'
        : '';
    $stmt = $pdo->prepare("
        SELECT kilpailija_id, piste, aika_sec, sija
        FROM valiajat
        WHERE kilpailija_id IN ($placeholders)
        $onlineEhto
        ORDER BY kilpailija_id, piste
    ");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $kid = (int) $r['kilpailija_id'];
        $piste = (int) $r['piste'];
        $kartta[$kid][$piste] = [
            'aika_sec' => $r['aika_sec'] !== null ? (int) $r['aika_sec'] : null,
            // Sija lasketaan aina sivulatauksella (laskeValiajatSijat) — älä käytä DB-arvoa.
            'sija' => null,
        ];
    }

    return $kartta;
}

/**
 * valia_lkm = online-väliaikapisteiden määrä (admin).
 * Ei päivitetä Emit-radan max-pisteestä — muuten tuloksiin ilmestyisi kaikki rastit.
 */
function paivitaSarjojenValiaLkm(PDO $pdo, int $kilpailu_id): void
{
    // no-op: valia_lkm tulee hallinnasta ja online-tapahtumista, ei Emit-radasta
}

function haeValiajat(PDO $pdo, $kilpailija_id)
{
    $stmt = $pdo->prepare('
        SELECT piste, rasti_koodi, aika_sec, sija, punch_at
        FROM valiajat
        WHERE kilpailija_id = ?
        ORDER BY piste ASC
    ');
    $stmt->execute([$kilpailija_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Täydentää kilpailijan väliaikarivit radan kaikilla pisteillä.
 * Puuttuvat rastit jäävät listaan null-ajoilla (näytetään viivoina).
 *
 * @param list<array{piste:int|string,rasti_koodi?:?string,aika_sec?:?int,sija?:?int,punch_at?:?string}> $valiajat
 * @param list<array{piste:int,rasti_koodi?:string,...}> $radanRastit
 * @return list<array{piste:int,rasti_koodi:?string,aika_sec:?int,sija:?int,punch_at:?string}>
 */
function taydennaValiajatRadalla(array $valiajat, array $radanRastit): array
{
    if ($radanRastit === []) {
        return $valiajat;
    }

    $byPiste = [];
    foreach ($valiajat as $va) {
        $p = (int) ($va['piste'] ?? 0);
        if ($p > 0) {
            $byPiste[$p] = $va;
        }
    }

    $out = [];
    $maxRata = 0;
    foreach ($radanRastit as $rr) {
        $p = (int) ($rr['piste'] ?? 0);
        if ($p <= 0) {
            continue;
        }
        $maxRata = max($maxRata, $p);
        $code = isset($rr['rasti_koodi']) ? trim((string) $rr['rasti_koodi']) : '';
        if (isset($byPiste[$p])) {
            $row = $byPiste[$p];
            if ($code !== '' && trim((string) ($row['rasti_koodi'] ?? '')) === '') {
                $row['rasti_koodi'] = $code;
            }
            $out[] = $row;
            unset($byPiste[$p]);
        } else {
            $out[] = [
                'piste' => $p,
                'rasti_koodi' => $code !== '' ? $code : null,
                'aika_sec' => null,
                'sija' => null,
                'punch_at' => null,
            ];
        }
    }

    // Mahdollinen maalipiste / ylimääräiset leimaukset radan jälkeen
    ksort($byPiste, SORT_NUMERIC);
    foreach ($byPiste as $p => $va) {
        if ($p > $maxRata) {
            $out[] = $va;
        }
    }

    return $out;
}

function haeKilpailijaJulkinen(
    PDO $pdo,
    $kilpailu_id,
    $numero,
    ?int $kilpailija_id = null,
    ?int $osuus = null
) {
    if ($kilpailija_id) {
        $stmt = $pdo->prepare('
            SELECT
                k.id,
                k.numero,
                k.osuus,
                k.nimi,
                k.etunimi,
                k.sukunimi,
                k.seura,
                k.status,
                k.rata,
                k.pirila_lahto_at,
                k.sarja_id,
                s.nimi AS sarja,
                a.aika_sec AS maali_aika_sec,
                a.maaliaika
            FROM kilpailijat k
            JOIN sarjat s ON k.sarja_id = s.id
            LEFT JOIN ajat a ON k.id = a.kilpailija_id
            WHERE k.kilpailu_id = ? AND k.id = ?
            LIMIT 1
        ');
        $stmt->execute([(int) $kilpailu_id, $kilpailija_id]);
    } else {
        $sql = '
            SELECT
                k.id,
                k.numero,
                k.osuus,
                k.nimi,
                k.etunimi,
                k.sukunimi,
                k.seura,
                k.status,
                k.rata,
                k.pirila_lahto_at,
                k.sarja_id,
                s.nimi AS sarja,
                a.aika_sec AS maali_aika_sec,
                a.maaliaika
            FROM kilpailijat k
            JOIN sarjat s ON k.sarja_id = s.id
            LEFT JOIN ajat a ON k.id = a.kilpailija_id
            WHERE k.kilpailu_id = ? AND k.numero = ?
        ';
        $params = [(int) $kilpailu_id, (int) $numero];
        if ($osuus !== null) {
            $sql .= ' AND k.osuus = ?';
            $params[] = $osuus;
        } else {
            // Hk: osuus 0; viestissä ilman osuus-parametria ensimmäinen osuma
            $sql .= ' ORDER BY COALESCE(k.osuus, 0) ASC, k.id ASC';
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $rivi = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rivi) {
        $rivi['nimi'] = kilpailijaNimiRivista($rivi);
    }

    return $rivi ?: null;
}

function valiajatLink($kilpailu_id, $numero, $public = true, ?int $kilpailija_id = null, ?int $osuus = null)
{
    $prefix = $public ? '' : '../public/';
    $params = ['kilpailu_id' => (int) $kilpailu_id];
    if ($kilpailija_id) {
        $params['kilpailija_id'] = $kilpailija_id;
    }
    if ($numero) {
        $params['numero'] = (int) $numero;
    }
    if ($osuus !== null && $osuus > 0) {
        $params['osuus'] = $osuus;
    }

    return $prefix . 'valiajat.php?' . http_build_query($params);
}

function vertailuLink(int $kilpailu_id, int $kilpailija_a, ?int $kilpailija_b = null, bool $public = true): string
{
    $prefix = $public ? '' : '../public/';
    $params = [
        'kilpailu_id' => $kilpailu_id,
        'a' => $kilpailija_a,
    ];
    if ($kilpailija_b !== null && $kilpailija_b > 0) {
        $params['b'] = $kilpailija_b;
    }

    return $prefix . 'vertailu.php?' . http_build_query($params);
}

/**
 * Sarjan kilpailijat vertailuvalikkoon (maalissa tai rastiväliajat).
 *
 * @return list<array{id:int,numero:?int,nimi:string,maali_aika_sec:?int}>
 */
function haeSarjanVertailuKilpailijat(PDO $pdo, int $kilpailuId, int $sarjaId, int $paitsiKid = 0): array
{
    $stmt = $pdo->prepare('
        SELECT
            k.id,
            k.numero,
            k.nimi,
            k.etunimi,
            k.sukunimi,
            a.aika_sec AS maali_aika_sec
        FROM kilpailijat k
        LEFT JOIN ajat a ON a.kilpailija_id = k.id
        WHERE k.kilpailu_id = ?
          AND k.sarja_id = ?
          AND k.id != ?
          AND COALESCE(k.osuus, 0) = 0
          AND (
              a.aika_sec IS NOT NULL
              OR EXISTS (
                  SELECT 1 FROM valiajat v
                  WHERE v.kilpailija_id = k.id AND v.aika_sec IS NOT NULL
              )
          )
        ORDER BY
            CASE WHEN k.numero IS NULL THEN 1 ELSE 0 END,
            k.numero ASC,
            k.sukunimi ASC,
            k.etunimi ASC,
            k.id ASC
    ');
    $stmt->execute([$kilpailuId, $sarjaId, $paitsiKid]);
    $rivit = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rivit as &$r) {
        $r['id'] = (int) $r['id'];
        $r['numero'] = $r['numero'] !== null ? (int) $r['numero'] : null;
        $r['nimi'] = kilpailijaNimiRivista($r);
        $r['maali_aika_sec'] = $r['maali_aika_sec'] !== null ? (int) $r['maali_aika_sec'] : null;
    }
    unset($r);

    return $rivit;
}

/** Vertailu: ero kahden kumulatiivisen ajan välillä (+/-). */
function formatVertailuEro(?int $secA, ?int $secB): string
{
    if ($secA === null || $secB === null) {
        return '';
    }

    $diff = $secA - $secB;
    if ($diff === 0) {
        return '0:00';
    }

    $sign = $diff > 0 ? '+' : '−';

    return $sign . formatAikaSecPirila(abs($diff));
}

/** Julkisten listojen hakukysely (?q=). */
function julkinenHakuQ(): string
{
    return trim((string) ($_GET['q'] ?? ''));
}

/** Julkisten listojen seurasuodatin (?seura=), tarkka osuma. */
function julkinenSeuraQ(): string
{
    return trim((string) ($_GET['seura'] ?? ''));
}

function seuraAvain(?string $seura): string
{
    $s = trim((string) $seura);
    if ($s === '') {
        return '';
    }

    return mb_strtolower($s, 'UTF-8');
}

/** Vastaaako rivi seurasuodattimeen (tarkka, case-insensitive). */
function riviVastaaSeuraan(array $r, string $seura): bool
{
    $seura = trim($seura);
    if ($seura === '') {
        return true;
    }

    return seuraAvain((string) ($r['seura'] ?? '')) === seuraAvain($seura);
}

/** Vastaaako rivi vapaatekstihakuun (numero, nimi, seura, sarja, status, rata, osuudet). */
function riviVastaaHakuun(array $r, string $q): bool
{
    $q = trim($q);
    if ($q === '') {
        return true;
    }
    $needle = mb_strtolower($q, 'UTF-8');
    $osat = [
        (string) ($r['numero'] ?? ''),
        (string) ($r['nimi'] ?? ''),
        (string) ($r['etunimi'] ?? ''),
        (string) ($r['sukunimi'] ?? ''),
        (string) ($r['seura'] ?? ''),
        (string) ($r['sarja'] ?? ''),
        (string) ($r['status'] ?? ''),
        (string) ($r['rata'] ?? ''),
        (string) ($r['joukkue'] ?? ''),
        (string) ($r['osuudet_teksti'] ?? ''),
    ];
    if (!empty($r['osuudet']) && is_array($r['osuudet'])) {
        foreach ($r['osuudet'] as $o) {
            $osat[] = (string) ($o['nimi'] ?? '');
            $osat[] = (string) ($o['status'] ?? '');
        }
    }
    $hay = mb_strtolower(implode(' ', $osat), 'UTF-8');

    return mb_strpos($hay, $needle) !== false;
}

function riviVastaaJulkisiinSuodattimiin(array $r, string $q, string $seura): bool
{
    return riviVastaaHakuun($r, $q) && riviVastaaSeuraan($r, $seura);
}

/**
 * @param list<array> $rivit
 * @return list<array>
 */
function suodataRivitHaulla(array $rivit, string $q): array
{
    if (trim($q) === '') {
        return $rivit;
    }

    return array_values(array_filter($rivit, static fn(array $r): bool => riviVastaaHakuun($r, $q)));
}

/**
 * @param list<array> $rivit
 * @return list<array>
 */
function suodataRivitSeuralla(array $rivit, string $seura): array
{
    if (trim($seura) === '') {
        return $rivit;
    }

    return array_values(array_filter($rivit, static fn(array $r): bool => riviVastaaSeuraan($r, $seura)));
}

/**
 * @param list<array> $rivit
 * @return list<array>
 */
function suodataRivitJulkisesti(array $rivit, string $q, string $seura): array
{
    return array_values(array_filter(
        $rivit,
        static fn(array $r): bool => riviVastaaJulkisiinSuodattimiin($r, $q, $seura)
    ));
}

/** Linkki seuran jäseniin annetulla julkisella sivulla. */
function seuraLink(int $kilpailuId, string $seura, string $sivu = 'lahtolista', array $extra = []): string
{
    $seura = trim($seura);
    $params = array_merge(['kilpailu_id' => $kilpailuId], $extra);
    unset($params['_target']);
    if ($seura !== '') {
        $params['seura'] = $seura;
    }
    if ($sivu === 'seuranta') {
        return 'seuranta.php?' . http_build_query($params);
    }
    if ($sivu === 'ilmoittautuneet') {
        return 'ilmoittautuneet.php?' . http_build_query($params);
    }
    if (!isset($params['nakyma'])) {
        $params['nakyma'] = 'seura';
    }

    return 'lahtolista.php?' . http_build_query($params);
}

/** Lähtölistan sarja-ankkuri (sivun sisäinen hyppy). */
function lahtolistaSarjaAnchorId(array $ryhma): string
{
    $id = (int) ($ryhma['sarja_id'] ?? 0);
    if ($id > 0) {
        return 'lahto-sarja-' . $id;
    }

    $sarja = trim((string) ($ryhma['sarja'] ?? ''));
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $sarja) ?: 'sarja';

    return 'lahto-sarja-' . $slug;
}

/** HTML: sarjan hyppylinkit lähtölistalla (desktop: chipit, mobiili: select). */
function lahtolistaSarjaNavHtml(array $ryhmat, string $extraClass = ''): string
{
    if ($ryhmat === []) {
        return '';
    }

    $opts = [];
    foreach ($ryhmat as $ryhma) {
        $sarja = trim((string) ($ryhma['sarja'] ?? ''));
        if ($sarja === '') {
            continue;
        }
        $opts[] = [
            'anchor' => lahtolistaSarjaAnchorId($ryhma),
            'label' => sarjaOtsikkoMatka($sarja, $ryhma['sarja_matka'] ?? null),
        ];
    }

    if ($opts === []) {
        return '';
    }

    $cls = 'lb-sarja-nav' . ($extraClass !== '' ? ' ' . $extraClass : '');
    $html = '<nav class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" aria-label="Sarjat">';

    $html .= '<label class="lb-sarja-nav-select-wrap">'
        . '<span class="lb-sarja-nav-select-label">Sarja</span>'
        . '<select class="lb-sarja-nav-select" aria-label="Siirry sarjaan">';
    $html .= '<option value="">Valitse sarja…</option>';
    foreach ($opts as $o) {
        $html .= '<option value="#' . htmlspecialchars($o['anchor'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($o['label'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<div class="lb-sarja-nav-chips">';
    foreach ($opts as $o) {
        $html .= '<a class="lb-chip lb-sarja-nav-link" href="#' . htmlspecialchars($o['anchor'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($o['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }
    $html .= '</div>';

    $html .= '</nav>';

    return $html;
}

/** Lähtölistan sarakeleveydet (sama kaikissa taulukoissa). */
function lahtolistaColgroupHtml(bool $naytaRata, bool $naytaEmit = false, bool $naytaKolmas = true): string
{
    $html = '<colgroup>';
    $html .= '<col class="lahto-col-nro">';
    if ($naytaEmit) {
        $html .= '<col class="lahto-col-emit">';
    }
    $html .= '<col class="lahto-col-nimi">';
    if ($naytaKolmas) {
        $html .= '<col class="lahto-col-kolmas">';
    }
    if ($naytaRata) {
        $html .= '<col class="lahto-col-rata">';
    }
    $html .= '<col class="lahto-col-lahto">';
    $html .= '</colgroup>';

    return $html;
}

/** Linkki sarjan online-tuloksiin. */
function sarjaLink(int $kilpailuId, string $sarja, array $extra = []): string
{
    $params = array_merge([
        'kilpailu_id' => $kilpailuId,
        'sarja' => $sarja,
    ], $extra);

    return 'seuranta.php?' . http_build_query($params);
}

/** HTML-linkki seuralle (tyhjä seura → tyhjä merkkijono). */
function seuraLinkHtml(int $kilpailuId, ?string $seura, string $sivu = 'lahtolista', array $extra = [], string $class = 'seura-link'): string
{
    $seura = trim((string) $seura);
    if ($seura === '') {
        return '';
    }

    $target = '';
    if (!empty($extra['_target'])) {
        $target = ' target="' . htmlspecialchars((string) $extra['_target'], ENT_QUOTES, 'UTF-8') . '"';
        if ((string) $extra['_target'] === '_parent' || (string) $extra['_target'] === '_top') {
            $target .= ' rel="noopener"';
        }
    }

    return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="'
        . htmlspecialchars(seuraLink($kilpailuId, $seura, $sivu, $extra), ENT_QUOTES, 'UTF-8')
        . '"' . $target . '>' . htmlspecialchars($seura, ENT_QUOTES, 'UTF-8') . '</a>';
}

/** HTML-linkki sarjalle. */
function sarjaLinkHtml(int $kilpailuId, string $sarja, ?string $matka = null, array $extra = [], string $class = 'sarja-link'): string
{
    $sarja = trim($sarja);
    if ($sarja === '') {
        return '';
    }
    $label = sarjaOtsikkoMatka($sarja, $matka);

    return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="'
        . htmlspecialchars(sarjaLink($kilpailuId, $sarja, $extra), ENT_QUOTES, 'UTF-8')
        . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

/**
 * Julkisen tuloshaun indeksi (koko kisa): kilpailijat/joukkueet + navigointilinkit.
 *
 * @param list<array> $tulokset haeLiveTulokset / haeLiveViestiTulokset
 * @return list<array{numero:mixed,nimi:string,seura:string,sarja:string,sarja_label:string,haystack:string,links:array<string,string>}>
 */
function rakennaTulosHakuIndeksi(int $kilpailuId, array $tulokset, bool $onPirila): array
{
    $items = [];
    foreach ($tulokset as $r) {
        $nimi = trim((string) ($r['nimi'] ?? ''));
        if ($nimi === '') {
            $nimi = kilpailijaNimiRivista($r);
        }
        $seura = trim((string) ($r['seura'] ?? ''));
        $sarja = trim((string) ($r['sarja'] ?? ''));
        $sarjaLabel = sarjaOtsikkoMatka($sarja, $r['sarja_matka'] ?? null);
        $numero = $r['numero'] ?? null;
        $status = (string) ($r['status'] ?? 'OK');
        $maalissa = $status === 'OK'
            && (($r['maali_aika_sec'] ?? null) !== null || !empty($r['maaliaika']));

        $hay = trim(implode(' ', array_filter([
            (string) ($numero ?? ''),
            $nimi,
            (string) ($r['etunimi'] ?? ''),
            (string) ($r['sukunimi'] ?? ''),
            $seura,
            $sarja,
            $status,
            (string) ($r['rata'] ?? ''),
            (string) ($r['joukkue'] ?? ''),
        ], static fn($x) => trim((string) $x) !== '')));

        if (!empty($r['osuudet']) && is_array($r['osuudet'])) {
            foreach ($r['osuudet'] as $o) {
                $hay .= ' ' . (string) ($o['nimi'] ?? '');
            }
        }

        $links = [
            'tulos' => sarjaLink($kilpailuId, $sarja),
        ];
        if ($seura !== '') {
            $links['seura'] = seuraLink($kilpailuId, $seura, 'seuranta');
            $links['lahtolista'] = seuraLink($kilpailuId, $seura, 'lahtolista');
        }
        if ($onPirila && empty($r['_viesti']) && (int) ($r['kilpailija_id'] ?? 0) > 0
            && ($maalissa || $status !== 'OK')) {
            $links['valiajat'] = valiajatLink(
                $kilpailuId,
                (int) ($numero ?? 0),
                true,
                (int) $r['kilpailija_id']
            );
        }

        $items[] = [
            'numero' => $numero,
            'nimi' => $nimi,
            'seura' => $seura,
            'sarja' => $sarja,
            'sarja_label' => $sarjaLabel,
            'haystack' => $hay,
            'links' => $links,
        ];
    }

    return $items;
}

/**
 * Pirilä keskhyl / Tark → OK/DNF/DNS/DSQ.
 *
 * KILP.DAT / ohjelma (yksikirjaiminen):
 *   T = tark (hyväksytty), H = hyl, K = kesk, E = eiläht
 * Tekstimuotoiset nimet (CSV / UI) hyväksytään myös.
 */
function pirilaNormalisoiTark(?string $tark): string
{
    $raw = trim((string) $tark);
    $k = mb_strtoupper($raw, 'UTF-8');
    // Poista ääkköset vertailua varten (EILÄHT → EILAHT)
    $ascii = strtr($k, ['Ä' => 'A', 'Ö' => 'O', 'Å' => 'A']);

    if ($k === '' || $k === '-' || $k === 'T' || $k === 'OK' || $ascii === 'TARK') {
        return 'OK';
    }
    if (in_array($k, ['DNF', 'DNS', 'DSQ'], true)) {
        return $k;
    }
    if ($k === 'HYL' || $k === 'MP' || $k === 'RJK' || $k === 'DQ') {
        return 'DSQ';
    }
    if ($k === 'KESK' || $ascii === 'KESKEYTTANYT') {
        return 'DNF';
    }
    if ($ascii === 'EILAHT' || $ascii === 'EILAHTENYT' || $k === 'DNS') {
        return 'DNS';
    }

    // Pirilä Stat: -THKEOIVPXMB → Open, OK, DQ, DNF, DNS, ...
    // N = ilmoittautunut (käsitellään kuten avoin, ei DNS)
    $map = [
        'H' => 'DSQ', // hylätty
        'K' => 'DNF', // keskeyttänyt
        'X' => 'DNF',
        'E' => 'DNS', // ei lähtenyt
        'N' => 'OK',  // ilmoittautunut ≈ avoin
        'S' => 'DNS',
        'D' => 'DNS',
        'O' => 'DSQ',
        'I' => 'OK',  // Open (Stat)
        'P' => 'DNS', // Absent / poissa
        'V' => 'DNS', // Vacant
        'M' => 'DSQ',
        'B' => 'OK',  // observed / seurattu
    ];

    return $map[$k] ?? 'OK';
}

/** TV: viestin osuudet lyhyenä seura-kenttänä. */
function tvViestiOsuusTeksti(array $rivi): string
{
    if (empty($rivi['osuudet']) || !is_array($rivi['osuudet'])) {
        return (string) ($rivi['seura'] ?? '');
    }
    $palat = [];
    foreach ($rivi['osuudet'] as $o) {
        $pala = ((int) ($o['osuus'] ?? 0)) . '. ' . (($o['nimi'] ?? '') !== '' ? $o['nimi'] : '—');
        if (($o['status'] ?? 'OK') !== 'OK') {
            $pala .= ' ' . statusLabel($o['status']);
        } elseif (($o['maali_aika_sec'] ?? null) !== null) {
            $pala .= ' ' . formatAikaSec($o['maali_aika_sec']);
        }
        $palat[] = $pala;
    }

    return implode(' · ', $palat);
}

/** Onko kilpailu live (tila = käynnissä). */
function kilpailuOnLive(array $kilpailu): bool
{
    return ($kilpailu['tila'] ?? '') === 'kaynnissa';
}

/**
 * Seurannan julkinen nimi: live → Online-tulokset, muuten Tulokset.
 */
function seurantaOtsikko(array $kilpailu): string
{
    return kilpailuOnLive($kilpailu) ? 'Online-tulokset' : 'Tulokset';
}

/** Ylävalikon lyhyt nimi seurannalle. */
function seurantaNavLabel(array $kilpailu): string
{
    return kilpailuOnLive($kilpailu) ? 'Online' : 'Tulokset';
}

function seurantaRefreshSec(array $kilpailu)
{
    // Jaettu live-cache TTL = 2 s → pollaa samaan tahtiin.
    // Useimmat pyynnöt ovat kevyitä 204-vastauksia (X-Live-Hash),
    // raskas HTML/tuloslaskenta jaetaan katsojien kesken.
    if (!empty($kilpailu["pirila_synkka"]) || ($kilpailu["kisa_tyyppi"] ?? "") === "pirila") {
        return 2;
    }

    if (kilpailuOnLive($kilpailu)) {
        return 2;
    }

    // Ei live: harvempi pollaus (valmis / tulossa)
    return 20;
}

/** Soft-refresh: jos client-hash sama, palauta 204 ilman HTML:ää. */
function seurantaLiveRefreshEiMuutosta(string $liveHash): bool
{
    if (($_SERVER['HTTP_X_LIVE_REFRESH'] ?? '') !== '1') {
        return false;
    }
    $client = (string) ($_SERVER['HTTP_X_LIVE_HASH'] ?? '');
    if ($client === '' || !hash_equals($liveHash, $client)) {
        return false;
    }
    header('X-Live-Hash: ' . $liveHash);
    header('Cache-Control: public, s-maxage=2, max-age=0, must-revalidate');
    http_response_code(204);

    return true;
}

/**
 * Arvioi maastossa olevan kilpailijan kuluneen ajan (sekunteina) sivun lataushetkellä.
 * Ensisijaisesti lähtöajasta (Pirilä tlahtotime) — vastaa todellista kulunutta aikaa.
 */
function arvioiKulunutSec(array $rivi): ?int
{
    if ($rivi['maali_aika_sec'] !== null && ($rivi['status'] ?? 'OK') === 'OK') {
        return (int) $rivi['maali_aika_sec'];
    }

    $now = time();

    if (!empty($rivi['pirila_lahto_at'])) {
        $lahto = strtotime((string) $rivi['pirila_lahto_at']);
        if ($lahto !== false) {
            return max(0, $now - $lahto);
        }
    }

    if ($rivi['viime_aika_sec'] !== null && !empty($rivi['viime_punch_at'])) {
        $punch = strtotime((string) $rivi['viime_punch_at']);
        if ($punch !== false) {
            return (int) $rivi['viime_aika_sec'] + max(0, $now - $punch);
        }

        return (int) $rivi['viime_aika_sec'];
    }

    return null;
}

/**
 * Sekunteja lähdöstä. Negatiivinen, jos lähtö on vielä edessä.
 * Vain näyttöön — järjestys käyttää arvioiKulunutSec-arvoa.
 */
function lahtoKulunutSec(array $rivi): ?int
{
    if (empty($rivi['pirila_lahto_at'])) {
        return null;
    }
    $lahto = strtotime((string) $rivi['pirila_lahto_at']);
    if ($lahto === false) {
        return null;
    }

    return time() - (int) $lahto;
}

/**
 * Aikaero näyttöön. Ennen lähtöä esim. -01:20, lähdön jälkeen tavallinen aika.
 */
function formatAikaEroSec(int $sec): string
{
    if ($sec >= 0) {
        return formatAikaSec($sec);
    }

    $sec = -$sec;
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) {
        return sprintf('-%d:%02d:%02d', $h, $m, $s);
    }

    return sprintf('-%02d:%02d', $m, $s);
}

/**
 * Live-kuluva aika HTML:nä. Maastossa = suluissa, esim. (12:34).
 * Ennen lähtöä miinus, esim. (-01:20).
 */
function liveAikaHtml(array $rivi, bool $live, bool $suluissa = true): string
{
    $wrap = static function (string $inner) use ($suluissa): string {
        return $suluissa ? '(' . $inner . ')' : $inner;
    };

    $eroTeksti = static function (array $rivi) use ($wrap): ?string {
        if (!empty($rivi['maali_aika_sec'])) {
            return null;
        }
        $ero = lahtoKulunutSec($rivi);
        if ($ero === null) {
            return null;
        }

        return $wrap(htmlspecialchars(formatAikaEroSec($ero)));
    };

    if (!$live) {
        $eroHtml = $eroTeksti($rivi);
        if ($eroHtml !== null) {
            return $eroHtml;
        }

        $sec = arvioiKulunutSec($rivi);
        if ($sec !== null && empty($rivi['maali_aika_sec'])) {
            return $wrap(htmlspecialchars(formatAikaSec($sec)));
        }

        if (!empty($rivi['pirila_lahto_at'])) {
            return '<span class="muted">Lähtenyt</span>';
        }

        return '<span class="muted">Maastossa</span>';
    }

    // Kulunut aika lähdöstä — luotettavin Pirilä-datalla
    if (!empty($rivi['pirila_lahto_at'])) {
        $lahto = htmlspecialchars($rivi['pirila_lahto_at'], ENT_QUOTES, 'UTF-8');
        $eroHtml = $eroTeksti($rivi);
        if ($eroHtml !== null) {
            $alku = $eroHtml;
        } else {
            $arvio = arvioiKulunutSec($rivi);
            $alku = $arvio !== null ? $wrap(htmlspecialchars(formatAikaSec($arvio))) : $wrap('—');
        }

        return '<span class="live-aika live-aika--maastossa" data-live-start data-parens="'
            . ($suluissa ? '1' : '0') . '" data-start-at="' . $lahto . '">'
            . $alku . '</span>';
    }

    if ($rivi['viime_aika_sec'] !== null && !empty($rivi['viime_punch_at'])) {
        $punch = htmlspecialchars($rivi['viime_punch_at'], ENT_QUOTES, 'UTF-8');
        $sec = (int) $rivi['viime_aika_sec'];
        $nayta = arvioiKulunutSec($rivi);
        $txt = $wrap(htmlspecialchars(formatAikaSec($nayta !== null ? $nayta : $sec)));

        return '<span class="live-aika live-aika--maastossa" data-live-elapsed data-parens="'
            . ($suluissa ? '1' : '0') . '" data-base-sec="' . $sec
            . '" data-punch-at="' . $punch . '">' . $txt . '</span>';
    }

    return '<span class="muted">Maastossa</span>';
}

/**
 * Maalissa-laskuri: valmiit + DSQ/DNF/DNS (muuten luku jää vajaaksi).
 */
function laskeMaalissaLkm(array $rivit): int
{
    $n = 0;
    foreach ($rivit as $r) {
        if (!is_array($r)) {
            continue;
        }
        $st = strtoupper(trim((string) ($r['status'] ?? '')));
        $kesk = mb_strtoupper(mb_substr(trim((string) ($r['keskhyl'] ?? '')), 0, 1), 'UTF-8');
        if ($st === 'ESITYS' || $kesk === 'E') {
            continue;
        }
        if (!empty($r['_maalissa']) || !empty($r['_status'])) {
            $n++;
            continue;
        }
        if (in_array($st, ['DSQ', 'DNF', 'DNS'], true)) {
            $n++;
        }
    }

    return $n;
}

/**
 * Sama lista: maalissa → maastossa → status (DNF/DNS/DSQ) listan lopussa.
 */
function jarjestaLiveVertailu(array $maalissa, array $maastossa, array $status = []): array
{
    $rivit = [];

    foreach ($maalissa as $r) {
        $sec = $r['maali_aika_sec'] ?? null;
        $r['_vertailu_sec'] = ($sec !== null && $sec !== '') ? (int) $sec : PHP_INT_MAX;
        $r['_maalissa'] = true;
        $r['_status'] = false;
        $rivit[] = $r;
    }

    $maastossaRivit = [];

    foreach ($maastossa as $r) {
        $sec = arvioiKulunutSec($r);
        $r['_vertailu_sec'] = $sec !== null ? $sec : PHP_INT_MAX;
        $r['_maalissa'] = false;
        $r['_status'] = false;
        $maastossaRivit[] = $r;
    }

    usort($maastossaRivit, static function ($a, $b) {
        if ($a['_vertailu_sec'] === $b['_vertailu_sec']) {
            return ((int) $a['numero']) <=> ((int) $b['numero']);
        }

        return $a['_vertailu_sec'] <=> $b['_vertailu_sec'];
    });

    foreach ($maastossaRivit as $r) {
        $rivit[] = $r;
    }

    $statusRivit = [];
    foreach ($status as $r) {
        $r['_vertailu_sec'] = PHP_INT_MAX;
        $r['_maalissa'] = false;
        $r['_status'] = true;
        $statusRivit[] = $r;
    }
    usort($statusRivit, static function ($a, $b) {
        return ((int) $a['numero']) <=> ((int) $b['numero']);
    });
    foreach ($statusRivit as $r) {
        $rivit[] = $r;
    }

    return $rivit;
}

if (is_readable(__DIR__ . '/sarja_numerot.php')) {
    require_once __DIR__ . '/sarja_numerot.php';
}

?>
