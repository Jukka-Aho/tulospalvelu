<?php

/**
 * SSL-tulostiedosto Suomen Suunnistusliitolle (Pirilän kiinteäleveys, ISO-8859-1).
 *
 * 999999 + kilpailun nimi
 * 999998 + sarjarivit
 * kilpailijarivi: lisenssi(6) + nimi(23) + seura(8) + sarja(10) + aika(6)
 */

function sslTuloksetVarmista(PDO $pdo): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $ok = true;
    if (function_exists('tiliVarmistaSarake')) {
        tiliVarmistaSarake($pdo, 'kilpailut', 'ssl_lataus_at', 'DATETIME NULL');
    }
}

function sslTuloksetSallittu(array $kilpailu): bool
{
    if (function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu)) {
        return false;
    }

    return function_exists('voiHallitaKilpailua') && voiHallitaKilpailua($kilpailu);
}

function sslTuloksetLaskutusOk(?PDO $pdo, array $kilpailu): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (function_exists('tiliKilpailuLaskutuspostiOk')) {
        return tiliKilpailuLaskutuspostiOk($pdo, $kilpailu);
    }

    return false;
}

function sslTuloksetKuoriLinkki(?PDO $pdo, array $kilpailu, int $kid, string $active = ''): void
{
    $ok = sslTuloksetLaskutusOk($pdo, $kilpailu);
    if ($ok) {
        echo '<a href="/public/tulokset_ssl.php?kilpailu_id=' . (int) $kid . '"'
            . ($active === 'ssl' ? ' class="active"' : '')
            . '>SSL-tulokset</a>';

        return;
    }
    $puute = function_exists('tiliLaskutuspostiPuuttuuTeksti')
        ? tiliLaskutuspostiPuuttuuTeksti()
        : 'Laskutussähköposti puuttuu';
    if (function_exists('tiliLaskutuspostiTyylit')) {
        echo tiliLaskutuspostiTyylit();
    }
    echo '<span class="laskutusposti-esto" title="' . htmlspecialchars($puute) . '">SSL-tulokset</span>';
    echo '<span class="muted" style="display:block;font-size:12px;padding:0 12px 6px">'
        . htmlspecialchars($puute) . '</span>';
}

function sslTuloksetNappiHtml(?PDO $pdo, array $kilpailu, string $luokka = 'btn'): void
{
    if (!sslTuloksetSallittu($kilpailu)) {
        return;
    }
    $ok = sslTuloksetLaskutusOk($pdo, $kilpailu);
    $href = '/public/tulokset_ssl.php?kilpailu_id=' . (int) ($kilpailu['id'] ?? 0);
    if ($ok) {
        echo '<a class="' . htmlspecialchars($luokka) . '" href="' . htmlspecialchars($href) . '">Lataa SSL-tulokset</a>';

        return;
    }
    if (function_exists('tiliLaskutuspostiEstettyNappi')) {
        tiliLaskutuspostiEstettyNappi('Lataa SSL-tulokset', $luokka);
        tiliLaskutuspostiHuomioHtml('Lisää se seuran asetuksiin.');
    }
}

function sslTuloksetKaytetty(array $kilpailu): bool
{
    return trim((string) ($kilpailu['ssl_lataus_at'] ?? '')) !== '';
}

function sslTuloksetMerkitseKaytto(PDO $pdo, int $kilpailuId): void
{
    if ($kilpailuId < 1) {
        return;
    }
    sslTuloksetVarmista($pdo);
    try {
        $pdo->prepare('
            UPDATE kilpailut
            SET ssl_lataus_at = COALESCE(ssl_lataus_at, NOW())
            WHERE id = ?
        ')->execute([$kilpailuId]);
    } catch (Throwable $e) {
    }
}

function sslLatin1(string $s): string
{
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    if ($out === false) {
        $out = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $s);
    }
    if ($out === false) {
        $out = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
    }

    return $out;
}

function sslPad(string $s, int $len, bool $oikea = false): string
{
    $s = sslLatin1($s);
    if (strlen($s) > $len) {
        $s = substr($s, 0, $len);
    }

    return str_pad($s, $len, ' ', $oikea ? STR_PAD_LEFT : STR_PAD_RIGHT);
}

function sslAikaKentta(?int $sec, string $status): ?string
{
    $st = strtoupper(trim($status));
    if (in_array($st, ['DNF', 'K'], true)) {
        return 'KES   ';
    }
    if (in_array($st, ['DSQ', 'DQ', 'DISQ', 'HYL', 'H', 'MP'], true)) {
        return 'HYL   ';
    }
    if (in_array($st, ['DNS', 'ESITYS', 'NC'], true)) {
        return null;
    }
    if ($sec === null || $sec < 0) {
        return null;
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;

    return sprintf('%02d%02d%02d', min(99, $h), $m, $s);
}

function sslLisenssinumero(array $rivi): int
{
    foreach (['lisenssi', 'lisno', 'pirila_kilpid'] as $k) {
        $raw = trim((string) ($rivi[$k] ?? ''));
        if ($raw !== '' && ctype_digit($raw)) {
            $n = (int) $raw;
            if ($n > 0 && $n < 1000000) {
                return $n;
            }
        }
    }

    return 0;
}

function sslNimiRivista(array $rivi): string
{
    if (function_exists('kilpailijaNimiRivista')) {
        $nimi = trim(kilpailijaNimiRivista($rivi));
        if ($nimi !== '' && $nimi !== 'Nimetön') {
            return $nimi;
        }
    }

    return trim((string) ($rivi['nimi'] ?? ''));
}

function sslMatkaKentta(?string $matka): string
{
    $norm = function_exists('normalisoiSarjaMatka') ? normalisoiSarjaMatka($matka) : null;
    if ($norm === null || $norm === '') {
        return '';
    }

    return str_replace('.', ',', (string) $norm);
}

/**
 * @param array<string,mixed> $kilpailu
 * @param array<string,array<string,mixed>> $ryhmat
 */
function sslTiedostoMerkkijono(array $kilpailu, array $ryhmat): string
{
    $rivit = [];
    $nimi = trim((string) ($kilpailu['nimi'] ?? 'Kilpailu'));
    $ots = '999999' . sslPad($nimi, 47);
    if (strlen($ots) < 53) {
        $ots = str_pad($ots, 53, ' ');
    }
    if ($ots[52] === ' ') {
        $ots[52] = '0';
    }
    $rivit[] = substr($ots, 0, 53);

    $sarjaNro = 0;
    foreach ($ryhmat as $sarjaNimi => $data) {
        $kaikki = array_merge(
            $data['maalissa'] ?? [],
            $data['ei_maaleissa'] ?? [],
            $data['maastossa'] ?? []
        );
        if ($kaikki === []) {
            continue;
        }
        $sarjaNro++;
        $ilmo = 0;
        $tulos = 0;
        $kesk = 0;
        $hyl = 0;
        foreach ($kaikki as $r) {
            $st = strtoupper(trim((string) ($r['status'] ?? 'OK')));
            $keskMerk = mb_strtoupper(mb_substr(trim((string) ($r['keskhyl'] ?? '')), 0, 1), 'UTF-8');
            if ($st === 'ESITYS' || $keskMerk === 'E' || $st === 'DNS') {
                continue;
            }
            $ilmo++;
            if ($st === 'DNF' || $keskMerk === 'K') {
                $kesk++;
            } elseif (in_array($st, ['DSQ', 'DQ', 'DISQ'], true) || $keskMerk === 'H') {
                $hyl++;
            } elseif (($r['maali_aika_sec'] ?? null) !== null) {
                $tulos++;
            }
        }
        $rivit[] = '999998'
            . sslPad((string) $sarjaNimi, 10)
            . sslPad(sslMatkaKentta($data['matka'] ?? null), 6)
            . sprintf('%5d%4d%4d%4d%4d       %3d', 0, $ilmo, $tulos, $kesk, $hyl, $sarjaNro);
    }

    foreach ($ryhmat as $sarjaNimi => $data) {
        $kaikki = array_merge(
            $data['maalissa'] ?? [],
            $data['ei_maaleissa'] ?? [],
            $data['maastossa'] ?? []
        );
        foreach ($kaikki as $r) {
            $st = strtoupper(trim((string) ($r['status'] ?? 'OK')));
            $keskMerk = mb_strtoupper(mb_substr(trim((string) ($r['keskhyl'] ?? '')), 0, 1), 'UTF-8');
            if ($st === 'ESITYS' || $keskMerk === 'E') {
                $st = 'ESITYS';
            } elseif ($st === 'DNF' || $keskMerk === 'K') {
                $st = 'DNF';
            } elseif (in_array($st, ['DSQ', 'DQ', 'DISQ'], true) || $keskMerk === 'H') {
                $st = 'DSQ';
            }
            $aika = sslAikaKentta(
                isset($r['maali_aika_sec']) && $r['maali_aika_sec'] !== null ? (int) $r['maali_aika_sec'] : null,
                $st
            );
            if ($aika === null) {
                continue;
            }
            $rivit[] = sprintf('%06d', sslLisenssinumero($r))
                . sslPad(sslNimiRivista($r), 23)
                . sslPad((string) ($r['seura'] ?? ''), 8)
                . sslPad((string) $sarjaNimi, 10)
                . $aika;
        }
    }

    return implode("\r\n", $rivit) . "\r\n";
}

function sslTiedostonimi(array $kilpailu): string
{
    $nimi = trim((string) ($kilpailu['nimi'] ?? 'tulokset'));
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $nimi) ?: 'tulokset';
    $slug = trim((string) $slug, '_');
    if ($slug === '') {
        $slug = 'tulokset';
    }
    $id = (int) ($kilpailu['id'] ?? 0);

    return 'SSL_' . $slug . ($id > 0 ? '_' . $id : '') . '.txt';
}

function sslLahetaTiedosto(PDO $pdo, array $kilpailu, ?array $ryhmat = null): void
{
    $kilpailuId = (int) ($kilpailu['id'] ?? 0);
    if ($ryhmat === null) {
        $live = haeLiveTulokset($pdo, $kilpailuId);
        $ryhmat = ryhmitteleLiveTulokset($live);
    }
    $sisalto = sslTiedostoMerkkijono($kilpailu, $ryhmat);
    sslTuloksetMerkitseKaytto($pdo, $kilpailuId);
    $filename = sslTiedostonimi($kilpailu);
    header('Content-Type: text/plain; charset=ISO-8859-1');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $sisalto;
    exit;
}
