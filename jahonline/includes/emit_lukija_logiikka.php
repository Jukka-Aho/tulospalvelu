<?php

/**
 * Emit-lukijan päätös: sisäänluku vai leimantarkastus.
 * Ei tietokantaa — testattavissa yksin.
 */

/**
 * @param list<array{koodi?:int,kello_sec?:int}> $leimat
 */
function emitLukijaNollaLeimoista(array $leimat): ?int
{
    foreach ($leimat as $l) {
        if ((int) ($l['koodi'] ?? -1) !== 0) {
            continue;
        }
        $sec = (int) ($l['kello_sec'] ?? $l['aika_sec'] ?? -1);
        if ($sec >= 0) {
            return $sec;
        }
    }

    return null;
}

function emitLukijaLeimaOnRasti(int $koodi): bool
{
    if ($koodi === 248) {
        return true;
    }
    if ($koodi <= 0 || $koodi > 239) {
        return false;
    }

    return true;
}

/**
 * @param list<array<string,mixed>> $leimat
 */
function emitLukijaLeimatOvatMaali(array $leimat): bool
{
    $n = 0;
    $maxAika = 0;
    foreach ($leimat as $l) {
        if (!is_array($l)) {
            continue;
        }
        $koodi = (int) ($l['koodi'] ?? $l['rasti_koodi'] ?? 0);
        if (!emitLukijaLeimaOnRasti($koodi)) {
            continue;
        }
        if ($koodi === 248) {
            return true;
        }
        $n++;
        $a = (int) ($l['aika_sec'] ?? $l['kello_sec'] ?? 0);
        if ($a > $maxAika) {
            $maxAika = $a;
        }
    }

    return $n >= 2 || $maxAika >= 60;
}

/** Kortti on varattu, kun henkilö on vielä maastossa. Valmis tulos vapauttaa lainaemitin. */
function emitLukijaEmitKaytossa(?string $keskhyl): bool
{
    $k = function_exists('mb_strtoupper')
        ? mb_strtoupper(mb_substr(trim((string) $keskhyl), 0, 1), 'UTF-8')
        : strtoupper(substr(trim((string) $keskhyl), 0, 1));

    return $k === '' || $k === '-';
}

/** Automaatti / kioski näyttää valmiin tuloksen uudelleen, tulospalvelu kysyy. */
function emitLukijaOnAutomaatti(array $data): bool
{
    $v = $data['automaatti'] ?? false;
    if ($v === true || $v === 1 || $v === '1' || $v === 'true') {
        return true;
    }

    return (string) ($data['moodi'] ?? '') === 'automaatti';
}

function emitLukijaOnLahtenyt(array $rivi): bool
{
    $lahto = trim((string) ($rivi['pirila_lahto_at'] ?? ''));
    if ($lahto !== '') {
        return true;
    }
    if (trim((string) ($rivi['mobiili_lasna_at'] ?? '')) !== '') {
        return true;
    }
    $kesk = function_exists('mb_strtoupper')
        ? mb_strtoupper(trim((string) ($rivi['keskhyl'] ?? '')), 'UTF-8')
        : strtoupper(trim((string) ($rivi['keskhyl'] ?? '')));
    if ($kesk === '-') {
        return true;
    }

    return (int) ($rivi['lasna'] ?? 0) === 1 && $kesk !== 'N';
}

/**
 * @param list<array<string,mixed>> $leimat
 * @return 'kysy'|'maali'|'lahto'|'jo_lahdossa'
 */
function emitLukijaValitseToiminto(bool $loytyi, bool $joLahtenyt, string $moodi, array $leimat, bool $iltarasti = false, bool $maastossa = true): string
{
    if ($iltarasti) {
        return ($loytyi && $maastossa) ? 'maali' : 'kysy';
    }
    if (!$loytyi) {
        return 'kysy';
    }
    $maaliLeimat = emitLukijaLeimatOvatMaali($leimat);
    if ($joLahtenyt) {
        if ($moodi === 'maali' || $maaliLeimat) {
            return 'maali';
        }

        return 'jo_lahdossa';
    }
    if ($moodi === 'maali' || $maaliLeimat) {
        return 'maali';
    }

    return 'lahto';
}

function emitLukijaViestiJoukkueNimi(?string $seura, ?string $joukkue, $numero = null): string
{
    $seura = trim((string) $seura);
    $joukkue = trim((string) $joukkue);
    $nimi = trim($seura . ($joukkue !== '' ? ' ' . $joukkue : ''));
    if ($nimi !== '') {
        return $nimi;
    }
    if ($numero !== null && $numero !== '' && (int) $numero > 0) {
        return 'Joukkue ' . (int) $numero;
    }

    return '';
}

/**
 * @param array<string,mixed> $h
 * @return array{joukkue_nimi:string,otsikko:string,osuus_txt:string}
 */
function emitLukijaViestiNaytto(array $h): array
{
    $nro = $h['joukkue_numero'] ?? $h['numero'] ?? null;
    $joukkue = emitLukijaViestiJoukkueNimi(
        (string) ($h['joukkue_seura'] ?? $h['seura'] ?? ''),
        (string) ($h['joukkue'] ?? ''),
        $nro
    );
    $osuus = (int) ($h['osuus'] ?? 0);
    $juoksija = trim(trim((string) ($h['sukunimi'] ?? '')) . ' ' . trim((string) ($h['etunimi'] ?? '')));
    if ($juoksija === '') {
        $juoksija = trim((string) ($h['nimi'] ?? ''));
    }
    $otsikko = trim(($nro !== null && $nro !== '' ? ((int) $nro) . ' ' : '') . $joukkue);
    if ($otsikko === '') {
        $otsikko = $juoksija;
    }
    $osuusTxt = '';
    if ($osuus > 0) {
        $osuusTxt = $osuus . '. osuus';
        if ($juoksija !== '' && $juoksija !== $joukkue) {
            $osuusTxt .= ' · ' . $juoksija;
        }
    }

    return [
        'joukkue_nimi' => $joukkue,
        'otsikko' => $otsikko,
        'osuus_txt' => $osuusTxt,
    ];
}

/**
 * Kohdista leimat radan rasteihin järjestyksessä. Sama koodi saa toistua
 * (esim. 12–13–12); viimeinen osuma ei saa peittää ensimmäistä.
 *
 * @param list<array{piste?:int,rasti_koodi?:string}> $rataRastit
 * @param list<array{koodi?:int|string}> $leimat
 * @return list<int> piste per leima (0 = ei radalla)
 */
function emitLukijaLeimaPisteet(array $rataRastit, array $leimat): array
{
    $n = count($rataRastit);
    $i = 0;
    $jarj = 0;
    $out = [];
    foreach ($leimat as $leima) {
        $jarj++;
        $koodi = trim((string) ($leima['koodi'] ?? ''));
        $piste = 0;
        if ($n === 0) {
            if (!in_array((int) $koodi, [0, 248, 250, 253, 254], true)) {
                $piste = $jarj;
            }
        } else {
            for ($j = $i; $j < $n; $j++) {
                if (trim((string) ($rataRastit[$j]['rasti_koodi'] ?? '')) === $koodi) {
                    $piste = (int) ($rataRastit[$j]['piste'] ?? ($j + 1));
                    $i = $j + 1;
                    break;
                }
            }
        }
        $out[] = $piste;
    }

    return $out;
}

/** Emit e-cardin tyhjä paikka: aika 0xFFFF. */
function emitLukijaLeimaOnTyhjaMuisti(int $kello): bool
{
    return $kello === 65535;
}

/**
 * Uusi leimasarja on vanhan etuliite (lyhyempi, samat koodit alusta).
 * Lukijan toinen dumppi 0xFFFF-roskan jälkeen ei saa ylikirjoittaa täyttä lukua.
 *
 * @param list<array{koodi?:int|string,rasti_koodi?:int|string}> $vanhat
 * @param list<array{koodi?:int|string,rasti_koodi?:int|string}> $uudet
 */
function emitLukijaUudetLeimatVainEtuliite(array $vanhat, array $uudet): bool
{
    if ($uudet === [] || count($vanhat) <= count($uudet)) {
        return false;
    }
    foreach ($uudet as $i => $uusi) {
        $uk = (int) ($uusi['koodi'] ?? $uusi['rasti_koodi'] ?? -1);
        $vk = (int) ($vanhat[$i]['koodi'] ?? $vanhat[$i]['rasti_koodi'] ?? -2);
        if ($uk !== $vk) {
            return false;
        }
    }

    return true;
}
