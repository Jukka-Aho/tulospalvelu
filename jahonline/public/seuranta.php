<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/live_cache.php';
require_once __DIR__ . '/../includes/pirila_mode.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/tulospalvelu.php';

$kilpailu_id = isset($_GET['kilpailu_id']) ? (int) $_GET['kilpailu_id'] : 0;

if ($kilpailu_id <= 0) {
    header('Location: index.php');
    exit;
}

$kilpailu = haeKilpailu($pdo, $kilpailu_id);

if (!$kilpailu) {
    header('Location: index.php');
    exit;
}

vaadiJulkinenNakyvyys($kilpailu);

$onPirila = pirilaSynkkaPaalla($kilpailu);
if (is_readable(__DIR__ . '/../includes/iltarasti.php')) {
    require_once __DIR__ . '/../includes/iltarasti.php';
}
$onIltarasti = function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu);
$naytaSakot = function_exists('kisaNaytaSakot')
    ? kisaNaytaSakot($kilpailu)
    : (function_exists('kisaOnAmpumapaikat') && kisaOnAmpumapaikat($kilpailu));
$tulostaSakkoSolu = static function (?array $r = null) use ($naytaSakot): void {
    if (!$naytaSakot) {
        return;
    }
    if ($r === null) {
        echo '<span class="lb-sakko">Sakot</span>';

        return;
    }
    $txt = function_exists('sakkoNaytto') ? sakkoNaytto((string) ($r['ampsakot'] ?? '')) : '';
    echo '<span class="lb-sakko">' . htmlspecialchars($txt) . '</span>';
};
$piilotaMatkalla = function_exists('kisaPiilotaMatkalla') && kisaPiilotaMatkalla($kilpailu);
$sarjaSana = $onIltarasti ? 'Rata' : 'Sarja';
$sarjaPaikassa = $onIltarasti ? 'tällä radalla' : 'tässä sarjassa';
$tulosRiviLuokka = static function (array $r, bool $onStatus, bool $maalissa) use ($onIltarasti): string {
    if (!$onStatus) {
        return $maalissa ? '' : ' maastossa';
    }
    $st = strtoupper(trim((string) ($r['status'] ?? '')));
    $kesk = mb_strtoupper(mb_substr(trim((string) ($r['keskhyl'] ?? '')), 0, 1), 'UTF-8');
    $cls = ' status';
    if ($onIltarasti && ($kesk === 'E' || $st === 'ESITYS')) {
        $cls .= ' status-esitys';
    } elseif ($kesk === 'H' || $st === 'DSQ') {
        $cls .= ' status-hylatty';
    } elseif ($kesk === 'K' || $st === 'DNF') {
        $cls .= ' status-keskeyttanyt';
    }

    return $cls;
};
$live = ($kilpailu['tila'] ?? '') === 'kaynnissa';
$refresh = seurantaRefreshSec($kilpailu);
$isLiveRefresh = ($_SERVER['HTTP_X_LIVE_REFRESH'] ?? '') === '1';
$fragmentOnly = $isLiveRefresh || isset($_GET['fragment']);
$embedMode = isset($_GET['embed'])
    && ((string) $_GET['embed'] === '1' || strtolower((string) $_GET['embed']) === 'true');

// Iltarasti: kuori ilman kilpailun tulospalveluvalikoita.
if ($onIltarasti && !$fragmentOnly && !$embedMode) {
    $q = ['kilpailu_id' => $kilpailu_id];
    $nakyma = trim((string) ($_GET['nakyma'] ?? ''));
    if ($nakyma === 'valiajat') {
        $q['nakyma'] = 'valiajat';
    }
    header('Location: /public/iltarasti_tulokset.php?' . http_build_query($q), true, 302);
    exit;
}

// Täysi sivu → kilpailukuori (yksi sivu + oikeusvalikot). Embed/live-fragmentti jäävät suoraan.
if (!$fragmentOnly && !$embedMode) {
    $q = $_GET;
    unset($q['embed']);
    $q['osio'] = 'online';
    if (!isset($q['kilpailu_id'])) {
        $q['kilpailu_id'] = $kilpailu_id;
    }
    header('Location: kilpailu.php?' . http_build_query($q), true, 302);
    exit;
}

$nakyma = trim((string) ($_GET['nakyma'] ?? 'tulokset'));
$naytaRastiValiajat = $onPirila || $onIltarasti;
$sallitutNakyma = $onPirila
    ? ['tulokset', 'online', 'valiajat']
    : ($onIltarasti ? ['tulokset', 'valiajat'] : ['tulokset']);
if (!in_array($nakyma, $sallitutNakyma, true)) {
    $nakyma = 'tulokset';
}

$osuusValittu = isset($_GET['osuus']) ? (int) $_GET['osuus'] : 0;
if ($osuusValittu < 0) {
    $osuusValittu = 0;
}
if ($osuusValittu > 30) {
    $osuusValittu = 30;
}

$seuraSuodatin = julkinenSeuraQ();
$sarjaValittu = trim((string) ($_GET['sarja'] ?? ''));
$matkaValittu = trim((string) ($_GET['matka'] ?? ''));
// Matkan mukaan vain normaaleissa (ei-Pirilä, ei-iltarasti) kisoissa
$naytaMatkanMukaan = !$onPirila && !$onIltarasti
    && ((($_GET['ryhmittele'] ?? '') === 'matka') || $matkaValittu !== '');
if ($onPirila || $onIltarasti) {
    $matkaValittu = '';
}
// Osuusvalinta vain viestissä
$onViestiEarly = onkoViestiKilpailu($kilpailu);
if (!$onViestiEarly) {
    $osuusValittu = 0;
}
$seuraKaikkiSarjat = ($seuraSuodatin !== '' && $sarjaValittu === '' && !$naytaMatkanMukaan);

// Ratkaise oletussarja kevyesti (ennen raskasta tuloshakua) → early 204
if ($naytaMatkanMukaan) {
    // Matkan mukaan: sarjavalintaa ei käytetä
    $sarjaValittu = '';
    if ($matkaValittu !== '') {
        $matkaValittu = sarjaMatkaAvain($matkaValittu);
    }
    if ($matkaValittu === '') {
        $st = $pdo->prepare("
            SELECT matka FROM sarjat
            WHERE kilpailu_id = ?
              AND matka IS NOT NULL AND TRIM(matka) <> ''
            ORDER BY jarjestys ASC, nimi ASC
        ");
        $st->execute([$kilpailu_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rawMatka) {
            $avain = sarjaMatkaAvain((string) $rawMatka);
            if ($avain !== '') {
                $matkaValittu = $avain;
                break;
            }
        }
    }
} elseif (!$seuraKaikkiSarjat && $sarjaValittu === '') {
    $oletusKey = 'sarja_oletus:' . $kilpailu_id;
    $oletus = liveCacheGet($oletusKey);
    if (!is_string($oletus) || $oletus === '' || onVakanttiSarja($oletus)) {
        $st = $pdo->prepare("
            SELECT nimi FROM sarjat
            WHERE kilpailu_id = ?
              AND UPPER(TRIM(nimi)) NOT IN ('VAKANTIT', 'VAKANTTI')
            ORDER BY jarjestys ASC, nimi ASC
            LIMIT 1
        ");
        $st->execute([$kilpailu_id]);
        $oletus = (string) ($st->fetchColumn() ?: '');
        liveCacheSet($oletusKey, $oletus, 10);
    }
    $sarjaValittu = $oletus;
} elseif (!$seuraKaikkiSarjat && $sarjaValittu !== '') {
    if (onVakanttiSarja($sarjaValittu)) {
        $sarjaValittu = '';
    }
    $chk = $pdo->prepare('SELECT 1 FROM sarjat WHERE kilpailu_id = ? AND nimi = ? LIMIT 1');
    $chk->execute([$kilpailu_id, $sarjaValittu]);
    if ($sarjaValittu === '' || !$chk->fetchColumn()) {
        $st = $pdo->prepare("
            SELECT nimi FROM sarjat
            WHERE kilpailu_id = ?
              AND UPPER(TRIM(nimi)) NOT IN ('VAKANTIT', 'VAKANTTI')
            ORDER BY jarjestys ASC, nimi ASC
            LIMIT 1
        ");
        $st->execute([$kilpailu_id]);
        $sarjaValittu = (string) ($st->fetchColumn() ?: '');
    }
}

$watermark = seurantaLiveWatermark($pdo, $kilpailu_id, $kilpailu);
$hashSarja = $naytaMatkanMukaan
    ? ('matka:' . sarjaMatkaAvain($matkaValittu))
    : $sarjaValittu;
$liveHash = seurantaViewHash($nakyma, $hashSarja, $seuraSuodatin, $seuraKaikkiSarjat, $watermark, $osuusValittu);

if (seurantaLiveRefreshEiMuutosta($liveHash)) {
    exit;
}

// Jaettu HTML-fragmentti (2 s) — tuhannet soft-refreshit jakavat saman renderöinnin
$fragCacheKey = 'seuranta_frag:' . $liveHash;
if ($fragmentOnly) {
    $cachedFrag = liveCacheGet($fragCacheKey);
    if (is_string($cachedFrag) && $cachedFrag !== '') {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Live-Hash: ' . $liveHash);
        header('Cache-Control: public, s-maxage=2, max-age=0, must-revalidate');
        echo $cachedFrag;
        exit;
    }
}

$onViesti = onkoViestiKilpailu($kilpailu);
$tulokset = $onViesti
    ? haeLiveViestiTuloksetCached($pdo, $kilpailu_id, $watermark)
    : haeLiveTuloksetCached($pdo, $kilpailu_id, $watermark);
$sarjat = ryhmitteleLiveTulokset($tulokset);
$linkit = julkinenLinkit($kilpailu_id, true);

if ($onViesti && $osuusValittu > 0) {
    $maxOsuus = 0;
    foreach ($sarjat as $sData) {
        $maxOsuus = max($maxOsuus, (int) ($sData['osuus_lkm'] ?? 0));
    }
    if ($maxOsuus > 0 && $osuusValittu > $maxOsuus) {
        $osuusValittu = 0;
        $liveHash = seurantaViewHash($nakyma, $hashSarja, $seuraSuodatin, $seuraKaikkiSarjat, $watermark, $osuusValittu);
        $fragCacheKey = 'seuranta_frag:' . $liveHash;
    }
}

/**
 * Rakenna yhden sarjan näytettävä data (suodatetut rivit + Pirilä-sarakkeet).
 *
 * @param array $data
 * @param bool $ohitaTyhja Jos true, palauta null kun suodatuksen jälkeen ei rivejä
 * @return array{data:array,vertailu:list,valiaRivit:list,onlinePisteet:list,rastiPisteet:list,sarjaRastit:list,valiaKartta:array}|null
 */
$rakennaSarjaNaytto = static function (array $data, bool $ohitaTyhja = false) use (
    $seuraSuodatin,
    $onPirila,
    $naytaRastiValiajat,
    $onViesti,
    $pdo,
    $nakyma,
    $watermark,
    $osuusValittu,
    $piilotaMatkalla
): ?array {
    $maastossaNaytto = $piilotaMatkalla ? [] : ($data['maastossa'] ?? []);
    $vertailu = jarjestaLiveVertailu(
        $data['maalissa'] ?? [],
        $maastossaNaytto,
        $data['ei_maaleissa'] ?? []
    );
    $vertailu = suodataRivitSeuralla($vertailu, $seuraSuodatin);

    $onlinePisteet = [];
    $rastiPisteet = [];
    $sarjaRastit = [];
    $valiaKartta = [];
    $valiaRivit = [];
    $osuusPisteet = [];
    $osuusRivit = [];

    if (!empty($onViesti)) {
        $osuusLkm = min(30, max(1, (int) ($data['osuus_lkm'] ?? 0)));
        if ($osuusLkm < 1) {
            foreach (array_merge($data['maalissa'] ?? [], $data['maastossa'] ?? [], $data['ei_maaleissa'] ?? []) as $r) {
                $osuusLkm = max($osuusLkm, (int) ($r['osuus_lkm'] ?? 0), count($r['osuudet'] ?? []));
            }
            $osuusLkm = min(30, max(1, $osuusLkm));
        }
        $osuusPisteet = range(1, $osuusLkm);

        if ($osuusValittu > 0 && $osuusValittu <= $osuusLkm) {
            $osuusRivit = viestiOsuusRivit($vertailu, $osuusValittu);
        }
    }

    if ($naytaRastiValiajat) {
        $sarjaId = (int) ($data['sarja_id'] ?? 0);
        // Rastiväliajat = Emit-rata; tulokset (viesti-osuus) = online-VA (piste n = va[n+1]).
        if ($nakyma === 'valiajat') {
            $sarjaRastit = $sarjaId > 0 ? haeSarjanRastit($pdo, $sarjaId) : [];

            if (!empty($onViesti)) {
                // Emit-rastiväliajat osuuksittain (ei joukkueen aktiivista id:tä)
                $valiaRivit = viestiValiajatRivit($vertailu, $osuusValittu);
            } else {
                $valiaRivit = jarjestaLiveVertailu(
                    $data['maalissa'] ?? [],
                    $maastossaNaytto,
                    $data['ei_maaleissa'] ?? []
                );
                $valiaRivit = suodataRivitSeuralla($valiaRivit, $seuraSuodatin);
            }

            $ids = [];
            foreach ($valiaRivit as $r) {
                $kid = (int) ($r['kilpailija_id'] ?? 0);
                if ($kid > 0) {
                    $ids[] = $kid;
                }
            }
            // RADAT1 jos on; muuten näiden juoksijoiden Emit-leimat (kaikki pisteet)
            $rastiPisteet = $sarjaId > 0
                ? haeSarjanRastiPisteet($pdo, $sarjaId, !empty($onViesti) ? $ids : null)
                : haeKilpailijoidenRastiPisteet($pdo, $ids);

            $valiaKartta = $ids !== []
                ? (
                    $sarjaId > 0
                        ? haeValiajatKarttaRankedCached($pdo, $sarjaId, $ids, $rastiPisteet, $watermark)
                        : laskeValiajatSijat(haeValiajatKartta($pdo, $ids), $rastiPisteet)
                )
                : [];
        } elseif ($onPirila && $nakyma === 'tulokset' && !empty($onViesti) && $osuusValittu > 0 && $osuusRivit !== []) {
            // Vain viestin osuuslista: online-VA-sarakkeet (valia_lkm).
            // Henkilökohtaiset tulokset = pelkkä tulos (VA → online-/rastiväliajat-näkymä).
            $valiaLkm = (int) ($data['valia_lkm'] ?? 0);
            if ($valiaLkm <= 0) {
                foreach (array_merge($data['maalissa'] ?? [], $data['maastossa'] ?? [], $data['ei_maaleissa'] ?? []) as $r) {
                    $valiaLkm = max($valiaLkm, (int) ($r['valia_lkm'] ?? 0));
                }
            }
            $onlinePisteet = $sarjaId > 0
                ? haeSarjanOnlinePisteet($pdo, $sarjaId, $valiaLkm)
                : ($valiaLkm > 0 ? range(1, $valiaLkm) : []);
            $ids = [];
            foreach ($osuusRivit as $r) {
                $kid = (int) ($r['kilpailija_id'] ?? 0);
                if ($kid > 0) {
                    $ids[] = $kid;
                }
            }
            if ($onlinePisteet !== [] && $ids !== []) {
                $valiaKartta = $sarjaId > 0
                    ? haeValiajatKarttaRankedCached($pdo, $sarjaId, $ids, $onlinePisteet, $watermark, true)
                    : laskeValiajatSijat(haeValiajatKartta($pdo, $ids, true), $onlinePisteet);
            }
        } elseif ($onPirila && $nakyma === 'online') {
            // Online-tulokset: maaston online-väliaikapisteet Pirilästä (ei Emit).
            $valiaLkm = (int) ($data['valia_lkm'] ?? 0);
            if ($valiaLkm <= 0) {
                foreach (array_merge($data['maalissa'] ?? [], $data['maastossa'] ?? [], $data['ei_maaleissa'] ?? []) as $r) {
                    $valiaLkm = max($valiaLkm, (int) ($r['valia_lkm'] ?? 0));
                }
            }
            $onlinePisteet = $sarjaId > 0
                ? haeSarjanOnlinePisteet($pdo, $sarjaId, $valiaLkm)
                : ($valiaLkm > 0 ? range(1, $valiaLkm) : []);
            $rivitForIds = ($osuusValittu > 0 && $osuusRivit !== []) ? $osuusRivit : $vertailu;
            $ids = [];
            foreach ($rivitForIds as $r) {
                $kid = (int) ($r['kilpailija_id'] ?? 0);
                if ($kid > 0) {
                    $ids[] = $kid;
                }
            }
            if ($onlinePisteet !== [] && $ids !== []) {
                $valiaKartta = $sarjaId > 0
                    ? haeValiajatKarttaRankedCached($pdo, $sarjaId, $ids, $onlinePisteet, $watermark, true)
                    : laskeValiajatSijat(haeValiajatKartta($pdo, $ids, true), $onlinePisteet);
            }
        }
    }

    $rivitNakymaan = $nakyma === 'valiajat'
        ? $valiaRivit
        : ($osuusValittu > 0 && $osuusRivit !== [] ? $osuusRivit : $vertailu);
    if ($ohitaTyhja && empty($rivitNakymaan)) {
        return null;
    }

    return [
        'data' => $data,
        'vertailu' => $vertailu,
        'osuusRivit' => $osuusRivit,
        'valiaRivit' => $valiaRivit,
        'onlinePisteet' => $onlinePisteet,
        'osuusPisteet' => $osuusPisteet,
        'rastiPisteet' => $rastiPisteet,
        'sarjaRastit' => $sarjaRastit,
        'valiaKartta' => $valiaKartta,
        'yhteensa' => count($data['maalissa'] ?? [])
            + count($data['maastossa'] ?? [])
            + count($data['ei_maaleissa'] ?? []),
    ];
};

/** @var array<string, array> $sarjaNaytot */
$sarjaNaytot = [];

/** @var list<string> $matkaVaihtoehdot */
$matkaVaihtoehdot = [];
$matkaKartta = [];
foreach ($sarjat as $sarjaNimi => $data) {
    $avain = sarjaMatkaAvain($data['matka'] ?? '');
    if ($avain === '') {
        continue;
    }
    if (!isset($matkaKartta[$avain])) {
        $matkaKartta[$avain] = [];
        $matkaVaihtoehdot[] = $avain;
    }
    $matkaKartta[$avain][] = $sarjaNimi;
}
usort($matkaVaihtoehdot, static function ($a, $b) {
    return ((float) $a <=> (float) $b) ?: strcmp($a, $b);
});

// Oletussarja: suosi sarjaa jossa on maaliintulijoita (ei tyhjää / vain maastossa)
if (!$naytaMatkanMukaan && !$seuraKaikkiSarjat) {
    $valitseOletus = static function (array $sarjat, string $ehdotus): string {
        if ($ehdotus !== '' && isset($sarjat[$ehdotus]) && !empty($sarjat[$ehdotus]['maalissa'])) {
            return $ehdotus;
        }
        foreach ($sarjat as $nimi => $data) {
            if (!empty($data['maalissa'])) {
                return (string) $nimi;
            }
        }
        if ($ehdotus !== '' && isset($sarjat[$ehdotus])) {
            return $ehdotus;
        }

        return (string) (array_key_first($sarjat) ?: '');
    };
    $sarjaValittu = $valitseOletus($sarjat, $sarjaValittu);
}

if ($naytaMatkanMukaan) {
    if ($matkaValittu !== '') {
        $matkaValittu = sarjaMatkaAvain($matkaValittu);
    }
    if ($matkaValittu === '' || !isset($matkaKartta[$matkaValittu])) {
        $matkaValittu = $matkaVaihtoehdot[0] ?? '';
    }
    if ($matkaValittu !== '' && isset($matkaKartta[$matkaValittu])) {
        foreach ($matkaKartta[$matkaValittu] as $sarjaNimi) {
            if (!isset($sarjat[$sarjaNimi])) {
                continue;
            }
            // Älä ohita tyhjiä: näytä sarjapaneeli heti (kuten sarjoittain)
            $naytto = $rakennaSarjaNaytto($sarjat[$sarjaNimi], false);
            if ($naytto !== null) {
                $sarjaNaytot[$sarjaNimi] = $naytto;
            }
        }
    }
} elseif ($seuraKaikkiSarjat) {
    foreach ($sarjat as $sarjaNimi => $data) {
        $naytto = $rakennaSarjaNaytto($data, true);
        if ($naytto !== null) {
            $sarjaNaytot[$sarjaNimi] = $naytto;
        }
    }
} elseif ($sarjaValittu !== '' && isset($sarjat[$sarjaValittu])) {
    $sarjaNaytot[$sarjaValittu] = $rakennaSarjaNaytto($sarjat[$sarjaValittu], false);
}

/**
 * Matkan mukaan (tulokset): yksi yhteinen lista kaikista sarjoista, nopein ensin.
 * Sarjanimi näytetään omana sarakkeena nimen jälkeen.
 *
 * @var array{matka:string,nayta_ajat:bool,vertailu:list,sarja_lkm:int}|null $matkaYhdistetty
 */
$matkaYhdistetty = null;
if ($naytaMatkanMukaan && $nakyma === 'tulokset' && $matkaValittu !== '' && $sarjaNaytot !== []) {
    $mMaalissa = [];
    $mMaastossa = [];
    $mStatus = [];
    $naytaAjatYhdistetty = false;
    foreach ($sarjaNaytot as $sarjaNimi => $naytto) {
        if (!empty($naytto['data']['nayta_ajat'])) {
            $naytaAjatYhdistetty = true;
        }
        foreach ($naytto['vertailu'] as $r) {
            $r['_sarja_nimi'] = (string) $sarjaNimi;
            if (!empty($r['_status'])) {
                $mStatus[] = $r;
            } elseif (!empty($r['_maalissa'])) {
                $mMaalissa[] = $r;
            } else {
                $mMaastossa[] = $r;
            }
        }
    }
    usort($mMaalissa, static function ($a, $b) {
        $as = $a['maali_aika_sec'] ?? $a['_vertailu_sec'] ?? PHP_INT_MAX;
        $bs = $b['maali_aika_sec'] ?? $b['_vertailu_sec'] ?? PHP_INT_MAX;
        $as = ($as !== null && $as !== '') ? (int) $as : PHP_INT_MAX;
        $bs = ($bs !== null && $bs !== '') ? (int) $bs : PHP_INT_MAX;
        if ($as === $bs) {
            return ((int) ($a['numero'] ?? 0)) <=> ((int) ($b['numero'] ?? 0));
        }

        return $as <=> $bs;
    });
    usort($mMaastossa, static function ($a, $b) {
        $as = (int) ($a['_vertailu_sec'] ?? PHP_INT_MAX);
        $bs = (int) ($b['_vertailu_sec'] ?? PHP_INT_MAX);
        if ($as === $bs) {
            return ((int) ($a['numero'] ?? 0)) <=> ((int) ($b['numero'] ?? 0));
        }

        return $as <=> $bs;
    });
    $matkaYhteensa = 0;
    foreach ($sarjaNaytot as $nayttoYht) {
        $matkaYhteensa += (int) ($nayttoYht['yhteensa'] ?? 0);
    }
    $matkaYhdistetty = [
        'matka' => $matkaValittu,
        'nayta_ajat' => $naytaAjatYhdistetty,
        'vertailu' => array_merge($mMaalissa, $mMaastossa, $mStatus),
        'sarja_lkm' => count($sarjaNaytot),
        'yhteensa' => $matkaYhteensa,
    ];
}

// Päivitä live-hash jos oletussarja korjattiin tulosten perusteella
$hashSarjaKorjattu = $naytaMatkanMukaan
    ? ('matka:' . sarjaMatkaAvain($matkaValittu))
    : $sarjaValittu;
if ($hashSarjaKorjattu !== $hashSarja) {
    $hashSarja = $hashSarjaKorjattu;
    $liveHash = seurantaViewHash($nakyma, $hashSarja, $seuraSuodatin, $seuraKaikkiSarjat, $watermark, $osuusValittu);
}

$seurantaUrl = static function (string $nak, string $sarja, array $extra = []) use (
    $kilpailu_id,
    $seuraSuodatin,
    $naytaMatkanMukaan,
    $matkaValittu,
    $embedMode,
    $osuusValittu,
    $onViesti
): string {
    $params = [
        'kilpailu_id' => (int) $kilpailu_id,
        'nakyma' => $nak,
    ];
    if ($embedMode) {
        $params['embed'] = 1;
    }
    $forceMatka = array_key_exists('ryhmittele', $extra)
        ? ((string) ($extra['ryhmittele'] ?? '') === 'matka')
        : $naytaMatkanMukaan;

    if ($forceMatka) {
        $params['ryhmittele'] = 'matka';
        $m = (string) ($extra['matka'] ?? $matkaValittu);
        if ($m !== '') {
            $params['matka'] = $m;
        }
    } elseif ($sarja !== '') {
        $params['sarja'] = $sarja;
    }
    if ($seuraSuodatin !== '') {
        $params['seura'] = $seuraSuodatin;
    }
    $osuus = array_key_exists('osuus', $extra) ? (int) $extra['osuus'] : $osuusValittu;
    if (!empty($onViesti) && $osuus > 0) {
        $params['osuus'] = $osuus;
    }
    foreach ($extra as $k => $v) {
        if ($k === 'ryhmittele' || $k === 'matka' || $k === 'sarja' || $k === 'osuus') {
            continue;
        }
        if ($v !== null && $v !== '') {
            $params[$k] = $v;
        }
    }

    return 'seuranta.php?' . http_build_query($params);
};

$tyhjennaSeuraParams = [
    'kilpailu_id' => (int) $kilpailu_id,
    'nakyma' => $nakyma,
];
if ($embedMode) {
    $tyhjennaSeuraParams['embed'] = 1;
}
if ($naytaMatkanMukaan) {
    $tyhjennaSeuraParams['ryhmittele'] = 'matka';
    if ($matkaValittu !== '') {
        $tyhjennaSeuraParams['matka'] = $matkaValittu;
    }
} elseif ($sarjaValittu !== '') {
    $tyhjennaSeuraParams['sarja'] = $sarjaValittu;
}
if (!empty($onViesti) && $osuusValittu > 0) {
    $tyhjennaSeuraParams['osuus'] = $osuusValittu;
}

$tyhjaViesti = static function (string $oletus) use ($seuraSuodatin, $onViesti, $sarjaPaikassa): string {
    if ($seuraSuodatin !== '') {
        return $onViesti
            ? 'Ei joukkueita seuralla “' . $seuraSuodatin . '” ' . $sarjaPaikassa . '.'
            : 'Ei kilpailijoita seuralla “' . $seuraSuodatin . '” ' . $sarjaPaikassa . '.';
    }

    return $oletus;
};

/**
 * Nimisolu: 1) nimi 2) meta (seura · rata) 3) osuudet.
 * Status (DNF/DSQ) näytetään vain Aika-sarakkeessa.
 */
$tulostaNimiSolu = static function (
    array $r,
    bool $maalissa,
    bool $linkitaNimi
) use ($kilpailu_id, $onPirila, $onIltarasti, $embedMode, $nakyma): void {
    $kid = (int) ($r['kilpailija_id'] ?? 0);
    $nimiTxt = (string) ($r['nimi'] ?? '');
    $parentTarget = $embedMode ? ' target="_parent" rel="noopener"' : '';
    echo '<span class="lb-nimi">';
    echo '<span class="lb-nimi-paa" title="' . htmlspecialchars($nimiTxt, ENT_QUOTES, 'UTF-8') . '">';
    if ($linkitaNimi && !($embedMode && $onIltarasti)) {
        echo '<a class="lb-nimi-link" href="' . htmlspecialchars(
            valiajatLink($kilpailu_id, (int) ($r['numero'] ?? 0), true, $kid),
            ENT_QUOTES,
            'UTF-8'
        ) . '"' . $parentTarget . '>' . htmlspecialchars($nimiTxt) . '</a>';
    } else {
        echo htmlspecialchars($nimiTxt);
    }
    echo '</span>';

    $meta = [];
    if (!empty($r['seura'])) {
        // Seuralinkki: kaikki seuran juoksijat (ei sarjasuodatinta)
        $seuraExtra = ['nakyma' => $nakyma];
        if ($embedMode) {
            $seuraExtra['embed'] = 1;
        }
        $meta[] = seuraLinkHtml((int) $kilpailu_id, (string) $r['seura'], 'seuranta', $seuraExtra, 'lb-seura-link');
    }
    if (!empty($r['rata'])) {
        $meta[] = '<span class="lb-meta-item">rata ' . htmlspecialchars((string) $r['rata'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($meta !== []) {
        echo '<span class="lb-nimi-meta">' . implode('<span class="lb-meta-sep" aria-hidden="true">·</span>', $meta) . '</span>';
    }

    if (!empty($r['_viesti']) && !empty($r['osuudet']) && empty($r['_piilota_osuuslista'])) {
        echo '<span class="lb-osuudet">';
        $osuusPalat = [];
        foreach ($r['osuudet'] as $o) {
            $pala = ((int) $o['osuus']) . '. ' . ($o['nimi'] !== '' ? $o['nimi'] : '—');
            if (($o['status'] ?? 'OK') !== 'OK') {
                $pala .= ' ' . statusLabel($o['status']);
            } elseif ($o['maali_aika_sec'] !== null) {
                $pala .= ' ' . formatAikaSec($o['maali_aika_sec']);
            }
            if ($onPirila && ($o['maali_aika_sec'] ?? null) !== null && !empty($o['kilpailija_id'])) {
                $href = valiajatLink(
                    $kilpailu_id,
                    (int) ($r['numero'] ?? 0),
                    true,
                    (int) $o['kilpailija_id'],
                    (int) $o['osuus']
                );
                $osuusPalat[] = '<a class="lb-nimi-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                    . $parentTarget . '>'
                    . htmlspecialchars($pala) . '</a>';
            } else {
                $osuusPalat[] = htmlspecialchars($pala);
            }
        }
        echo implode(' · ', $osuusPalat);
        echo '</span>';
    }

    echo '</span>';
};

/** Kilpailunumero tulostaulukossa. Iltarastissa ei näytetä. */
$tulostaNumeroSolu = static function (string $sisalto, string $luokka = 'lb-numero') use ($onIltarasti): void {
    if ($onIltarasti) {
        return;
    }
    echo '<span class="' . $luokka . '">' . $sisalto . '</span>';
};

$metaHtml = $live
    ? ''
    : ('päivittyy ' . (int) $refresh . ' s välein');
// Päivitetty-aika näytetään sarjaotsikon yhteydessä (ei ylämetassa)

if (!$fragmentOnly) {
    $pageOpts = tulospalveluPageOpts(
        'seuranta-page'
        . ($embedMode ? ' seuranta-embed' : '')
        . ($onIltarasti ? ' seuranta-iltarasti' : '')
    );
    if ($embedMode) {
        $pageOpts['embed'] = true;
    }
    $seurantaOtsikko = seurantaOtsikko($kilpailu);
    pageStart($seurantaOtsikko, $pageOpts);
    if (!$embedMode) {
        $ylaAktiivinen = 'seuranta';
        if ($nakyma === 'valiajat') {
            $ylaAktiivinen = 'valiajat';
        } elseif ($nakyma === 'online') {
            $ylaAktiivinen = 'online';
        }
        tulospalveluYla($kilpailu, $linkit, $seurantaOtsikko, [
            'aktiivinen' => $ylaAktiivinen,
            'meta_html' => $metaHtml !== '' ? $metaHtml : null,
        ]);
    } else {
        echo '<div class="lb">';
    }
} else {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Live-Hash: ' . $liveHash);
    header('Cache-Control: public, s-maxage=2, max-age=0, must-revalidate');
}
?>

<?php if (!$fragmentOnly && !empty($sarjat)): ?>
  <?php
  $ensimmainenSarja = (string) (array_key_first($sarjat) ?: '');
  $sarjaLinkiksi = $sarjaValittu !== '' ? $sarjaValittu : $ensimmainenSarja;
  $matkaLinkiksi = $matkaValittu !== '' ? $matkaValittu : ($matkaVaihtoehdot[0] ?? '');
  ?>
  <div class="lb-controls" data-lb-sticky-controls>
    <form method="get" class="lb-filters" id="sarja-valitsin">
      <input type="hidden" name="kilpailu_id" value="<?= (int) $kilpailu_id ?>">
      <input type="hidden" name="nakyma" value="<?= htmlspecialchars($nakyma, ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($embedMode): ?>
        <input type="hidden" name="embed" value="1">
      <?php endif; ?>
      <?php if ($seuraSuodatin !== ''): ?>
        <input type="hidden" name="seura" value="<?= htmlspecialchars($seuraSuodatin, ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>
      <?php if ($naytaMatkanMukaan): ?>
        <input type="hidden" name="ryhmittele" value="matka">
        <label for="matka">Matka</label>
        <select id="matka" name="matka" class="lb-filter-select" onchange="this.form.submit()">
          <?php foreach ($matkaVaihtoehdot as $mAvain): ?>
            <option value="<?= htmlspecialchars($mAvain, ENT_QUOTES, 'UTF-8') ?>"
              <?= $mAvain === $matkaValittu ? 'selected' : '' ?>>
              <?= htmlspecialchars(formatSarjaMatka($mAvain)) ?>
              (<?= count($matkaKartta[$mAvain] ?? []) ?> sarjaa)
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <label for="sarja"><?= htmlspecialchars($sarjaSana) ?></label>
        <select id="sarja" name="sarja" class="lb-filter-select" onchange="this.form.submit()">
          <?php foreach ($sarjat as $sarjaNimi => $data): ?>
            <option value="<?= htmlspecialchars($sarjaNimi, ENT_QUOTES, 'UTF-8') ?>"
              <?= (!$seuraKaikkiSarjat && $sarjaNimi === $sarjaValittu) ? 'selected' : '' ?>>
              <?= htmlspecialchars(sarjaOtsikkoMatka($sarjaNimi, $data['matka'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?php if (!empty($onViesti) && $osuusValittu > 0): ?>
        <input type="hidden" name="osuus" value="<?= (int) $osuusValittu ?>">
      <?php endif; ?>
      <button type="button" class="lb-chip lb-chip-haku" data-tulos-haku data-kilpailu-id="<?= (int) $kilpailu_id ?>" title="Haku tuloksista" aria-label="Haku tuloksista">
        <span class="lb-haku-pitka">Haku tuloksista</span>
        <span class="lb-haku-lyhyt">Haku</span>
      </button>
    </form>
    <?php
    $osuusChipit = [];
    if (!empty($onViesti) && !$naytaMatkanMukaan) {
        $maxChip = 0;
        if ($sarjaValittu !== '' && isset($sarjat[$sarjaValittu])) {
            $maxChip = (int) ($sarjat[$sarjaValittu]['osuus_lkm'] ?? 0);
        }
        if ($maxChip < 1) {
            foreach ($sarjaNaytot as $nayttoChip) {
                $maxChip = max($maxChip, count($nayttoChip['osuusPisteet'] ?? []), (int) ($nayttoChip['data']['osuus_lkm'] ?? 0));
            }
        }
        if ($maxChip < 1) {
            foreach ($sarjat as $sDataChip) {
                $maxChip = max($maxChip, (int) ($sDataChip['osuus_lkm'] ?? 0));
            }
        }
        if ($maxChip > 0) {
            $osuusChipit = range(1, min(30, $maxChip));
        }
    }
    ?>
    <?php if ($osuusChipit !== []): ?>
      <div class="lb-osuus-valinta" role="group" aria-label="Osuus">
        <a class="lb-chip<?= $osuusValittu < 1 ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($seurantaUrl($nakyma, $sarjaLinkiksi, ['osuus' => 0]), ENT_QUOTES, 'UTF-8') ?>">Kokonaistulos</a>
        <?php foreach ($osuusChipit as $osChip): ?>
          <a class="lb-chip<?= $osuusValittu === (int) $osChip ? ' is-active' : '' ?>"
             href="<?= htmlspecialchars($seurantaUrl($nakyma, $sarjaLinkiksi, ['osuus' => (int) $osChip]), ENT_QUOTES, 'UTF-8') ?>">Osuus <?= (int) $osChip ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="lb-nakyma" role="group" aria-label="<?= $onPirila || $onIltarasti ? 'Näkymä' : 'Ryhmittely' ?>">
      <?php if (!$onPirila && !$onIltarasti): ?>
        <a class="lb-chip<?= !$naytaMatkanMukaan ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($seurantaUrl($nakyma, $sarjaLinkiksi, ['ryhmittele' => '']), ENT_QUOTES, 'UTF-8') ?>">Sarjoittain</a>
        <?php if ($matkaVaihtoehdot !== []): ?>
          <a class="lb-chip<?= $naytaMatkanMukaan ? ' is-active' : '' ?>"
             href="<?= htmlspecialchars($seurantaUrl($nakyma, '', ['ryhmittele' => 'matka', 'matka' => $matkaLinkiksi]), ENT_QUOTES, 'UTF-8') ?>">Matkan mukaan</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($seuraSuodatin !== ''): ?>
        <span class="lb-chip is-active">Seura: <?= htmlspecialchars($seuraSuodatin) ?></span>
        <a class="lb-chip" href="<?= htmlspecialchars(
            'seuranta.php?' . http_build_query($tyhjennaSeuraParams),
            ENT_QUOTES,
            'UTF-8'
        ) ?>">Näytä kaikki seurat</a>
        <a class="lb-chip" href="<?= htmlspecialchars(
            seuraLink((int) $kilpailu_id, $seuraSuodatin, 'lahtolista'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"<?= $embedMode ? ' target="_parent" rel="noopener"' : '' ?>>Lähtöajat</a>
      <?php endif; ?>
      <?php if ($onPirila || $onIltarasti): ?>
        <a class="lb-chip<?= $nakyma === 'tulokset' ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($seurantaUrl('tulokset', $sarjaValittu), ENT_QUOTES, 'UTF-8') ?>">Tulokset</a>
        <?php if ($onPirila): ?>
        <a class="lb-chip<?= $nakyma === 'online' ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($seurantaUrl('online', $sarjaValittu), ENT_QUOTES, 'UTF-8') ?>">Online tulokset</a>
        <?php endif; ?>
        <a class="lb-chip<?= $nakyma === 'valiajat' ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($seurantaUrl('valiajat', $sarjaValittu), ENT_QUOTES, 'UTF-8') ?>">Rastiväliajat</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
ob_start();
?>
  <div class="lb-main lb-main--<?= htmlspecialchars($nakyma, ENT_QUOTES, 'UTF-8') ?>" data-live-root data-refresh="<?= (int) $refresh ?>" data-live-hash="<?= htmlspecialchars($liveHash) ?>"<?php
    if ($onPirila && !empty($kilpailu['pirila_viimeisin_synkka'])) {
        echo ' data-live-paivitetty="' . htmlspecialchars('Päivitetty ' . $kilpailu['pirila_viimeisin_synkka'], ENT_QUOTES, 'UTF-8') . '"';
    }
  ?>>
<?php
$paivitysTeksti = ($onPirila && !empty($kilpailu['pirila_viimeisin_synkka']))
    ? ('Päivitetty ' . (string) $kilpailu['pirila_viimeisin_synkka'])
    : '';
$paivitysTulostettu = false;
/** Sarjaotsikon Maalissa-rivin perään: Päivitetty (vain kerran ensimmäiseen). */
$sarjaPaivitysHtml = static function () use (&$paivitysTulostettu, $paivitysTeksti): string {
    if ($paivitysTulostettu || $paivitysTeksti === '') {
        return '';
    }
    $paivitysTulostettu = true;

    return ' <span class="lb-paivitys lb-paivitys--sarja" data-live-meta>'
        . htmlspecialchars($paivitysTeksti, ENT_QUOTES, 'UTF-8')
        . '</span>';
};
?>

<?php if ($nakyma === 'tulokset' && $matkaYhdistetty !== null): ?>
<?php
$vertailu = $matkaYhdistetty['vertailu'];
$naytaAjat = !empty($matkaYhdistetty['nayta_ajat']);
$sija = 0;
$karki = null;
if ($naytaAjat) {
    foreach ($vertailu as $vr) {
        if (!empty($vr['_maalissa']) && empty($vr['_status']) && ($vr['maali_aika_sec'] ?? null) !== null) {
            $karki = (int) $vr['maali_aika_sec'];
            break;
        }
    }
}
$maalissaLkm = laskeMaalissaLkm($vertailu);
$yhteensa = (int) ($matkaYhdistetty['yhteensa'] ?? count($vertailu));
$rowClass = 'lb-rivi has-sarja' . ($naytaSakot ? ' has-sakko' : '');
?>

<section class="lb-paneeli lb-paneeli--matka">
  <div class="lb-otsikko">
    <span><?= htmlspecialchars(formatSarjaMatka($matkaYhdistetty['matka'])) ?></span>
    <small>Maalissa <?= (int) $maalissaLkm ?>/<?= (int) $yhteensa ?> · <?= (int) $matkaYhdistetty['sarja_lkm'] ?> sarjaa<?= $sarjaPaivitysHtml() ?></small>
  </div>

  <div class="lb-scroll">
    <div class="<?= $rowClass ?> lb-head">
      <span class="lb-sija">Sija</span>
      <?php $tulostaNumeroSolu('Nro'); ?>
      <span class="lb-nimi"><?= !empty($onViesti) ? 'Joukkue' : 'Nimi' ?></span>
      <span class="lb-sarja-solu"><?= htmlspecialchars($sarjaSana) ?></span>
      <?php $tulostaSakkoSolu(); ?>
      <span class="lb-aika">Aika</span>
      <span class="lb-ero">Ero</span>
    </div>

    <?php if (!empty($vertailu)): ?>
      <?php foreach ($vertailu as $r): ?>
        <?php
        $onStatus = !empty($r['_status']);
        $maalissa = !$onStatus && !empty($r['_maalissa']);
        if ($maalissa) {
            $sija++;
        }
        $ero = ($maalissa && $naytaAjat)
            ? formatEroKeulaan($r['maali_aika_sec'] ?? null, $karki)
            : '';
        $cls = $tulosRiviLuokka($r, $onStatus, $maalissa);
        $sarjaNimiRivi = (string) ($r['_sarja_nimi'] ?? $r['sarja'] ?? '');
        ?>
        <div class="<?= $rowClass ?><?= $cls ?>">
          <span class="lb-sija"><?= $maalissa ? $sija . '.' : '·' ?></span>
          <?php $tulostaNumeroSolu(htmlspecialchars(formatKilpailijaNumero($r['numero'] ?? null))); ?>
          <?php
          $tulostaNimiSolu(
              $r,
              $maalissa,
              $onPirila && empty($r['_viesti']) && ($maalissa || $onStatus)
          );
          ?>
          <span class="lb-sarja-solu"><?php
            if ($sarjaNimiRivi !== '') {
                $sarjaExtra = ['nakyma' => $nakyma];
                if ($seuraSuodatin !== '') {
                    $sarjaExtra['seura'] = $seuraSuodatin;
                }
                echo sarjaLinkHtml((int) $kilpailu_id, $sarjaNimiRivi, null, $sarjaExtra, 'lb-sarja-link');
            }
          ?></span>
          <?php $tulostaSakkoSolu($r); ?>
          <span class="lb-aika">
            <?php if ($onStatus): ?>
              <?= htmlspecialchars($onIltarasti && function_exists('iltarastiTulosTila') ? iltarastiTulosTila($r) : statusLabel($r['status'] ?? 'OK')) ?>
            <?php elseif ($maalissa): ?>
              <?php if ($naytaAjat): ?>
                <?= htmlspecialchars(formatLiveTulosAika($r, false)) ?>
              <?php else: ?>
                <?= $sija ?>
              <?php endif; ?>
            <?php else: ?>
              <?= liveAikaHtml($r, $live, true) ?>
            <?php endif; ?>
          </span>
          <span class="lb-ero"><?= $ero !== '' ? htmlspecialchars($ero) : '' ?></span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="lb-empty"><?= htmlspecialchars($tyhjaViesti(
          !empty($onViesti) ? 'Ei joukkueita tällä matkalla.' : 'Ei kilpailijoita tällä matkalla.'
      )) ?></div>
    <?php endif; ?>
  </div>
</section>

<?php elseif ($nakyma === 'tulokset' && !empty($sarjaNaytot) && !empty($onViesti) && $osuusValittu > 0): ?>
<?php foreach ($sarjaNaytot as $sarjaNimi => $naytto): ?>
<?php
$data = $naytto['data'];
$osuusRivit = $naytto['osuusRivit'] ?? [];
if ($osuusRivit === []) {
    $osuusRivit = viestiOsuusRivit($naytto['vertailu'] ?? [], $osuusValittu);
}
$onlinePisteet = $naytto['onlinePisteet'] ?? [];
$valiaKartta = $naytto['valiaKartta'] ?? [];
$sija = 0;
$karki = null;
if (!empty($data['nayta_ajat'])) {
    foreach ($osuusRivit as $vr) {
        if (!empty($vr['_maalissa']) && empty($vr['_status']) && ($vr['maali_aika_sec'] ?? null) !== null) {
            $karki = (int) $vr['maali_aika_sec'];
            break;
        }
    }
}
$maalissaLkm = laskeMaalissaLkm($osuusRivit);
$yhteensa = count($osuusRivit);
$vaCols = count($onlinePisteet);
$rowClass = $vaCols > 0 ? 'lb-rivi has-va lb-viesti-osuuslista' : 'lb-rivi lb-viesti-osuuslista';
$vaStyle = $vaCols > 0 ? ' style="--va-cols:' . $vaCols . '"' : '';
?>

<section class="lb-paneeli lb-paneeli--viesti-osuus">
  <div class="lb-otsikko">
    <span><?php
      $sarjaExtra = ['nakyma' => $nakyma, 'osuus' => $osuusValittu];
      if ($seuraSuodatin !== '') {
          $sarjaExtra['seura'] = $seuraSuodatin;
      }
      echo sarjaLinkHtml((int) $kilpailu_id, (string) $sarjaNimi, $data['matka'] ?? null, $sarjaExtra, 'lb-sarja-link');
    ?> · osuus <?= (int) $osuusValittu ?></span>
    <small>Maalissa <?= (int) $maalissaLkm ?>/<?= (int) $yhteensa ?><?= $sarjaPaivitysHtml() ?></small>
  </div>

  <div class="lb-scroll">
    <div class="<?= $rowClass ?> lb-head"<?= $vaStyle ?>>
      <span class="lb-sija">Sija</span>
      <?php $tulostaNumeroSolu('Nro'); ?>
      <?php if ($vaCols > 0): ?>
        <span class="lb-nimi-lyh" title="Nimi">···</span>
      <?php endif; ?>
      <span class="lb-nimi">Juoksija / joukkue</span>
      <?php foreach ($onlinePisteet as $piste): ?>
        <span class="lb-va"><?= (int) $piste ?>. va</span>
      <?php endforeach; ?>
      <span class="lb-aika">Osuusaika</span>
      <span class="lb-ero">Ero</span>
    </div>

    <?php if (!empty($osuusRivit)): ?>
      <?php foreach ($osuusRivit as $r): ?>
        <?php
        $onStatus = !empty($r['_status']);
        $maalissa = !$onStatus && !empty($r['_maalissa']);
        if ($maalissa) {
            $sija++;
        }
        $kid = (int) ($r['kilpailija_id'] ?? 0);
        $omatVa = $valiaKartta[$kid] ?? [];
        $ero = ($maalissa && !empty($data['nayta_ajat']))
            ? formatEroKeulaan($r['maali_aika_sec'] ?? null, $karki)
            : '';
        $cls = $tulosRiviLuokka($r, $onStatus, $maalissa);
        $juoksija = trim((string) ($r['nimi'] ?? ''));
        $joukkueNimi = trim((string) ($r['joukkue'] ?? ''));
        $naytaSija = $maalissa
            ? (($r['osuus_sija'] ?? null) !== null ? ((int) $r['osuus_sija'] . '.') : ($sija . '.'))
            : '·';
        $lyh = '';
        if ($juoksija !== '') {
            $palat = preg_split('/\s+/', $juoksija) ?: [];
            $lyh = (string) ($palat[0] ?? $juoksija);
            if (function_exists('mb_substr')) {
                $lyh = mb_substr($lyh, 0, 8, 'UTF-8');
            } else {
                $lyh = substr($lyh, 0, 8);
            }
        }
        ?>
        <div class="<?= $rowClass ?><?= $cls ?>"<?= $vaStyle ?>>
          <span class="lb-sija"><?= htmlspecialchars($naytaSija) ?></span>
          <?php $tulostaNumeroSolu(htmlspecialchars(formatKilpailijaNumero($r['numero'] ?? null))); ?>
          <?php if ($vaCols > 0): ?>
            <span class="lb-nimi-lyh" title="<?= htmlspecialchars($juoksija !== '' ? $juoksija : $joukkueNimi, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lyh !== '' ? $lyh : '···') ?></span>
          <?php endif; ?>
          <span class="lb-nimi">
            <span class="lb-nimi-paa"><?= htmlspecialchars($juoksija !== '' ? $juoksija : '—') ?></span>
            <?php if ($joukkueNimi !== ''): ?>
              <span class="lb-nimi-meta"><?= htmlspecialchars($joukkueNimi) ?></span>
            <?php endif; ?>
          </span>
          <?php foreach ($onlinePisteet as $piste): ?>
            <span class="lb-va">
              <?php if (isset($omatVa[$piste]) && $omatVa[$piste]['aika_sec'] !== null): ?>
                <?php if (!empty($omatVa[$piste]['sija'])): ?>
                  <span class="lb-tag"><?= (int) $omatVa[$piste]['sija'] ?>.</span>
                <?php endif; ?>
                <?= htmlspecialchars(formatAikaSec((int) $omatVa[$piste]['aika_sec'])) ?>
              <?php else: ?>
                <span class="lb-tag">—</span>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
          <span class="lb-aika">
            <?php if ($onStatus): ?>
              <?= htmlspecialchars($onIltarasti && function_exists('iltarastiTulosTila') ? iltarastiTulosTila($r) : statusLabel($r['status'] ?? 'OK')) ?>
            <?php elseif ($maalissa && !empty($data['nayta_ajat'])): ?>
              <?= htmlspecialchars(formatAikaSecPirila((int) $r['maali_aika_sec'])) ?>
            <?php elseif ($maalissa): ?>
              <?= (int) ($r['osuus_sija'] ?? $sija) ?>
            <?php else: ?>
              <?= liveAikaHtml($r, $live, true) ?>
            <?php endif; ?>
          </span>
          <span class="lb-ero"><?= $ero !== '' ? htmlspecialchars($ero) : '' ?></span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="lb-empty"><?= htmlspecialchars($tyhjaViesti('Ei joukkueita tällä osuudella.')) ?></div>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php elseif ($nakyma === 'tulokset' && !empty($sarjaNaytot) && !empty($onViesti)): ?>
<?php foreach ($sarjaNaytot as $sarjaNimi => $naytto): ?>
<?php
$data = $naytto['data'];
$vertailu = $naytto['vertailu'];
$sija = 0;
$karki = !empty($data['nayta_ajat']) ? sarjanKarkiaika($data['maalissa'] ?? []) : null;
$maalissaLkm = laskeMaalissaLkm($vertailu);
$yhteensa = (int) ($naytto['yhteensa'] ?? count($vertailu));
$rowClass = 'lb-rivi lb-viesti-rivi';
?>

<section class="lb-paneeli lb-paneeli--viesti">
  <div class="lb-otsikko">
    <span><?php
      $sarjaExtra = ['nakyma' => $nakyma];
      if ($seuraSuodatin !== '') {
          $sarjaExtra['seura'] = $seuraSuodatin;
      }
      echo sarjaLinkHtml((int) $kilpailu_id, (string) $sarjaNimi, $data['matka'] ?? null, $sarjaExtra, 'lb-sarja-link');
    ?></span>
    <small>Maalissa <?= (int) $maalissaLkm ?>/<?= (int) $yhteensa ?><?= $sarjaPaivitysHtml() ?></small>
  </div>

  <div class="lb-scroll lb-scroll--viesti">
    <?php if (!empty($vertailu)): ?>
      <?php
      // Nopein kumulatiivinen aika osuuksittain → ero tuloksen alle
      $kumulKarkiByOsuus = [];
      $osuusKarkiByOsuus = [];
      foreach ($vertailu as $vrEro) {
          foreach ($vrEro['osuudet'] ?? [] as $oEro) {
              $osE = (int) ($oEro['osuus'] ?? 0);
              if ($osE < 1) {
                  continue;
              }
              $kumulE = $oEro['kumul_sec'] ?? null;
              if ($kumulE !== null && $kumulE !== '') {
                  $kumulE = (int) $kumulE;
                  if (!isset($kumulKarkiByOsuus[$osE]) || $kumulE < $kumulKarkiByOsuus[$osE]) {
                      $kumulKarkiByOsuus[$osE] = $kumulE;
                  }
              }
              $legE = $oEro['maali_aika_sec'] ?? null;
              $ostE = (string) ($oEro['status'] ?? 'OK');
              if ($legE !== null && $legE !== '' && !in_array($ostE, ['DNS', 'DNF', 'DSQ'], true)) {
                  $legE = (int) $legE;
                  if (!isset($osuusKarkiByOsuus[$osE]) || $legE < $osuusKarkiByOsuus[$osE]) {
                      $osuusKarkiByOsuus[$osE] = $legE;
                  }
              }
          }
      }
      ?>
      <div class="lb-viesti-grid">
      <?php foreach ($vertailu as $r): ?>
        <?php
        $onStatus = !empty($r['_status']);
        $maalissa = !$onStatus && !empty($r['_maalissa']);
        if ($maalissa) {
            $sija++;
        }
        $ero = ($maalissa && !empty($data['nayta_ajat']))
            ? formatEroKeulaan($r['maali_aika_sec'] ?? null, $karki)
            : '';
        $joukkueCls = ' lb-viesti-joukkue' . $tulosRiviLuokka($r, $onStatus, $maalissa);
        $joukkueNimi = (string) ($r['nimi'] ?? '');
        $joukkueAikaHtml = '';
        if ($onStatus) {
            $joukkueAikaHtml = htmlspecialchars(statusLabel($r['status'] ?? 'OK'));
        } elseif ($maalissa && !empty($data['nayta_ajat'])) {
            $joukkueAikaHtml = htmlspecialchars(formatLiveTulosAika($r, true));
        } elseif ($maalissa) {
            $joukkueAikaHtml = (string) (int) $sija;
        } else {
            $joukkueAikaHtml = liveAikaHtml($r, $live, true);
        }
        ?>
        <div class="lb-viesti-ryhma">
        <div class="<?= $rowClass ?><?= $joukkueCls ?>">
          <span class="lb-sija"><?= $maalissa ? $sija . '.' : '·' ?></span>
          <?php $tulostaNumeroSolu(htmlspecialchars(formatKilpailijaNumero($r['numero'] ?? null))); ?>
          <span class="lb-nimi">
            <span class="lb-nimi-rivi">
              <span class="lb-nimi-paa"><?= htmlspecialchars($joukkueNimi) ?></span>
              <?php if ($joukkueAikaHtml !== ''): ?>
                <span class="lb-joukkue-tulos">
                  <span class="lb-joukkue-tulos-aika"><?= $joukkueAikaHtml ?></span>
                  <?php if ($ero !== ''): ?>
                    <span class="lb-ero lb-ero--inline"><?= htmlspecialchars($ero) ?></span>
                  <?php endif; ?>
                </span>
              <?php endif; ?>
            </span>
          </span>
          <span class="lb-os-aika lb-desk-col"></span>
          <span class="lb-os-sija lb-desk-col"></span>
          <span class="lb-aika lb-aika--stack lb-desk-col">
            <?php if ($joukkueAikaHtml !== ''): ?>
              <span class="lb-aika-arvo"><?= $joukkueAikaHtml ?></span>
            <?php endif; ?>
            <?php if ($ero !== ''): ?>
              <span class="lb-ero lb-ero--alla"><?= htmlspecialchars($ero) ?></span>
            <?php endif; ?>
          </span>
          <span class="lb-ero lb-ero--desk lb-desk-col"><?= $ero !== '' ? htmlspecialchars($ero) : '' ?></span>
          <span class="lb-vaihto-sija lb-desk-col"></span>
        </div>

        <?php foreach ($r['osuudet'] ?? [] as $o): ?>
          <?php
          $os = (int) ($o['osuus'] ?? 0);
          if ($os < 1 || $os > 30) {
              continue;
          }
          $ost = (string) ($o['status'] ?? 'OK');
          $legSec = $o['maali_aika_sec'] ?? null;
          $kumulSec = $o['kumul_sec'] ?? null;
          $osSija = $o['osuus_sija'] ?? null;
          $vaihtoSija = $o['sija'] ?? null;
          $onimi = trim((string) ($o['nimi'] ?? ''));
          $osCls = ' lb-viesti-osuus';
          if ($ost === 'DNS' || $ost === 'DNF' || $ost === 'DSQ') {
              $osCls .= ' status';
          } elseif ($legSec === null) {
              $osCls .= ' maastossa';
          }
          $osAikaTxt = '';
          if ($ost === 'DNS' || $ost === 'DNF' || $ost === 'DSQ') {
              $osAikaTxt = statusLabel($ost);
          } elseif ($legSec !== null) {
              $osAikaTxt = formatAikaSecPirila((int) $legSec);
          }
          $tulosTxt = '';
          if ($kumulSec !== null) {
              $tulosTxt = formatAikaSecPirila((int) $kumulSec);
          } elseif ($legSec !== null && !($ost === 'DNS' || $ost === 'DNF' || $ost === 'DSQ')) {
              $tulosTxt = formatAikaSecPirila((int) $legSec);
          }
          $osSijaTxt = ($osSija !== null && $legSec !== null) ? (string) (int) $osSija : '';
          $vaihtoTxt = ($vaihtoSija !== null && $kumulSec !== null) ? (string) (int) $vaihtoSija : '';
          $legEro = '';
          if ($kumulSec !== null && !empty($data['nayta_ajat'])) {
              $legEro = formatEroKeulaan((int) $kumulSec, $kumulKarkiByOsuus[$os] ?? null);
          }
          $osuusEro = '';
          if ($legSec !== null && !empty($data['nayta_ajat']) && !($ost === 'DNS' || $ost === 'DNF' || $ost === 'DSQ')) {
              $osuusEro = formatEroKeulaan((int) $legSec, $osuusKarkiByOsuus[$os] ?? null);
          }
          ?>
          <div class="<?= $rowClass ?><?= $osCls ?>">
            <span class="lb-sija lb-sija--tyhja" aria-hidden="true"></span>
            <?php $tulostaNumeroSolu('', 'lb-numero lb-desk-col'); ?>
            <span class="lb-nimi">
              <span class="lb-osuus-nro"><?= (int) $os ?>.</span>
              <span class="lb-nimi-paa"><?= htmlspecialchars($onimi !== '' ? $onimi : '—') ?></span>
            </span>
            <span class="lb-viesti-col-osuus">
              <?php if ($osAikaTxt !== ''): ?>
                <span class="lb-viesti-col-aika">
                  <span class="lb-viesti-aika-arvo"><?= htmlspecialchars($osAikaTxt) ?></span>
                  <?php if ($osuusEro !== ''): ?>
                    <span class="lb-ero lb-ero--inline"><?= htmlspecialchars($osuusEro) ?></span>
                  <?php endif; ?>
                </span>
                <?php if ($osSijaTxt !== ''): ?>
                  <span class="lb-viesti-col-sija">(<?= htmlspecialchars($osSijaTxt) ?>)</span>
                <?php endif; ?>
              <?php elseif ($legSec === null && !($ost === 'DNS' || $ost === 'DNF' || $ost === 'DSQ')): ?>
                <span class="lb-viesti-col-aika"><span class="lb-viesti-aika-arvo">…</span></span>
              <?php endif; ?>
            </span>
            <span class="lb-viesti-col-tulos">
              <?php if ($tulosTxt !== ''): ?>
                <span class="lb-viesti-col-aika">
                  <span class="lb-viesti-aika-arvo"><?= htmlspecialchars($tulosTxt) ?></span>
                  <?php if ($legEro !== ''): ?>
                    <span class="lb-ero lb-ero--inline"><?= htmlspecialchars($legEro) ?></span>
                  <?php endif; ?>
                </span>
                <?php if ($vaihtoTxt !== ''): ?>
                  <span class="lb-viesti-col-sija">(<?= htmlspecialchars($vaihtoTxt) ?>)</span>
                <?php endif; ?>
              <?php endif; ?>
            </span>
            <span class="lb-os-aika lb-desk-col">
              <?php if ($osAikaTxt !== ''): ?>
                <span class="lb-os-aika-arvo"><?= htmlspecialchars($osAikaTxt) ?></span>
                <?php if ($osuusEro !== ''): ?>
                  <span class="lb-ero lb-ero--alla lb-ero--osuus"><?= htmlspecialchars($osuusEro) ?></span>
                <?php endif; ?>
              <?php elseif ($legSec === null && !($ost === 'DNS' || $ost === 'DNF' || $ost === 'DSQ')): ?>
                <span class="lb-tag">…</span>
              <?php endif; ?>
            </span>
            <span class="lb-os-sija lb-desk-col"><?= htmlspecialchars($osSijaTxt) ?></span>
            <span class="lb-aika lb-aika--stack lb-desk-col">
              <?php if ($tulosTxt !== ''): ?>
                <span class="lb-aika-arvo"><?= htmlspecialchars($tulosTxt) ?></span>
              <?php endif; ?>
              <?php if ($legEro !== ''): ?>
                <span class="lb-ero lb-ero--alla"><?= htmlspecialchars($legEro) ?></span>
              <?php endif; ?>
            </span>
            <span class="lb-ero lb-ero--desk lb-desk-col"><?= $legEro !== '' ? htmlspecialchars($legEro) : '' ?></span>
            <span class="lb-vaihto-sija lb-desk-col"><?= htmlspecialchars($vaihtoTxt) ?></span>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="lb-empty"><?= htmlspecialchars($tyhjaViesti('Ei joukkueita ' . $sarjaPaikassa . '.')) ?></div>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php elseif ($nakyma === 'tulokset' && !empty($sarjaNaytot)): ?>
<?php foreach ($sarjaNaytot as $sarjaNimi => $naytto): ?>
<?php
$data = $naytto['data'];
$vertailu = $naytto['vertailu'];
$onlinePisteet = $naytto['onlinePisteet'];
$valiaKartta = $naytto['valiaKartta'];
$rastiKoodiByPiste = [];
foreach ($naytto['sarjaRastit'] ?? [] as $sr) {
    $rastiKoodiByPiste[(int) $sr['piste']] = (string) $sr['rasti_koodi'];
}
$sija = 0;
$karki = !empty($data['nayta_ajat']) ? sarjanKarkiaika($data['maalissa'] ?? []) : null;
$vaCols = count($onlinePisteet);
$maalissaLkm = laskeMaalissaLkm($vertailu);
$yhteensa = (int) ($naytto['yhteensa'] ?? count($vertailu));
$rowClass = $vaCols > 0 ? 'lb-rivi has-va' : 'lb-rivi';
if ($naytaSakot) {
    $rowClass .= ' has-sakko';
}
$vaStyle = $vaCols > 0 ? ' style="--va-cols:' . $vaCols . '"' : '';
?>

<section class="lb-paneeli">
  <div class="lb-otsikko">
    <span><?php
      $sarjaExtra = ['nakyma' => $nakyma];
      if ($seuraSuodatin !== '') {
          $sarjaExtra['seura'] = $seuraSuodatin;
      }
      echo sarjaLinkHtml((int) $kilpailu_id, (string) $sarjaNimi, $data['matka'] ?? null, $sarjaExtra, 'lb-sarja-link');
    ?></span>
    <small>Maalissa <?= (int) $maalissaLkm ?>/<?= (int) $yhteensa ?><?= $sarjaPaivitysHtml() ?></small>
  </div>

  <div class="lb-scroll">
    <div class="<?= $rowClass ?> lb-head"<?= $vaStyle ?>>
      <span class="lb-sija">Sija</span>
      <?php $tulostaNumeroSolu('Nro'); ?>
      <?php if ($vaCols > 0): ?>
        <span class="lb-nimi-lyh" title="Sukunimi">···</span>
      <?php endif; ?>
      <span class="lb-nimi">Nimi</span>
      <?php foreach ($onlinePisteet as $piste): ?>
        <span class="lb-va"><?= htmlspecialchars($rastiKoodiByPiste[$piste] ?? ((int) $piste . '. va'), ENT_QUOTES, 'UTF-8') ?></span>
      <?php endforeach; ?>
      <?php $tulostaSakkoSolu(); ?>
      <span class="lb-aika">Aika</span>
      <span class="lb-ero">Ero</span>
    </div>

    <?php if (!empty($vertailu)): ?>
      <?php foreach ($vertailu as $r): ?>
        <?php
        $onStatus = !empty($r['_status']);
        $maalissa = !$onStatus && !empty($r['_maalissa']);
        if ($maalissa) {
            $sija++;
        }
        $kid = (int) $r['kilpailija_id'];
        $omatVa = $valiaKartta[$kid] ?? [];
        $ero = ($maalissa && !empty($data['nayta_ajat']))
            ? formatEroKeulaan($r['maali_aika_sec'] ?? null, $karki)
            : '';
        $cls = $tulosRiviLuokka($r, $onStatus, $maalissa);
        $lyh = $vaCols > 0
            ? sukunimiLyhenne($r['sukunimi'] ?? null, $r['nimi'] ?? null)
            : '';
        ?>
        <div class="<?= $rowClass ?><?= $cls ?>"<?= $vaStyle ?>>
          <span class="lb-sija"><?= $maalissa ? $sija . '.' : '·' ?></span>
          <?php $tulostaNumeroSolu(htmlspecialchars(formatKilpailijaNumero($r['numero'] ?? null))); ?>
          <?php if ($vaCols > 0): ?>
            <span class="lb-nimi-lyh" title="<?= htmlspecialchars((string) ($r['nimi'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lyh !== '' ? $lyh : '···', ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
          <?php
          $tulostaNimiSolu(
              $r,
              $maalissa,
              $onPirila && ($maalissa || $onStatus)
          );
          ?>
          <?php foreach ($onlinePisteet as $piste): ?>
            <span class="lb-va">
              <?php if (isset($omatVa[$piste]) && $omatVa[$piste]['aika_sec'] !== null): ?>
                <?php if (!empty($omatVa[$piste]['sija'])): ?>
                  <span class="lb-tag"><?= (int) $omatVa[$piste]['sija'] ?>.</span>
                <?php endif; ?>
                <?= htmlspecialchars(formatAikaSec($omatVa[$piste]['aika_sec'])) ?>
              <?php else: ?>
                <span class="lb-tag">—</span>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
          <?php $tulostaSakkoSolu($r); ?>
          <span class="lb-aika">
            <?php if ($onStatus): ?>
              <?= htmlspecialchars($onIltarasti && function_exists('iltarastiTulosTila') ? iltarastiTulosTila($r) : statusLabel($r['status'] ?? 'OK')) ?>
            <?php elseif ($maalissa): ?>
              <?php if ($data['nayta_ajat']): ?>
                <?= htmlspecialchars(formatLiveTulosAika($r, false)) ?>
              <?php else: ?>
                <?= $sija ?>
              <?php endif; ?>
            <?php else: ?>
              <?= liveAikaHtml($r, $live, true) ?>
            <?php endif; ?>
          </span>
          <span class="lb-ero"><?= $ero !== '' ? htmlspecialchars($ero) : '' ?></span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="lb-empty"><?= htmlspecialchars($tyhjaViesti('Ei kilpailijoita ' . $sarjaPaikassa . '.')) ?></div>
    <?php endif; ?>
  </div>
</section>

<?php endforeach; ?>

<?php elseif ($onPirila && $nakyma === 'online' && !empty($sarjaNaytot)): ?>
<?php foreach ($sarjaNaytot as $sarjaNimi => $naytto): ?>
<?php
$data = $naytto['data'];
$onlineRivit = (!empty($onViesti) && $osuusValittu > 0)
    ? ($naytto['osuusRivit'] ?? [])
    : ($naytto['vertailu'] ?? []);
if (!empty($onViesti) && $osuusValittu > 0 && $onlineRivit === []) {
    $onlineRivit = viestiOsuusRivit($naytto['vertailu'] ?? [], $osuusValittu);
}
$onlinePisteet = $naytto['onlinePisteet'] ?? [];
$valiaKartta = $naytto['valiaKartta'] ?? [];
$sija = 0;
$maalissaLkm = laskeMaalissaLkm($onlineRivit);
$yhteensa = (int) ($naytto['yhteensa'] ?? count($onlineRivit));
$vaCols = count($onlinePisteet);
$rowClass = $vaCols > 0 ? 'lb-rivi has-online' : 'lb-rivi has-online has-online--tyhja';
$vaStyle = ' style="--va-cols:' . (int) $vaCols . '"';
$matkaOtsikko = formatSarjaMatka($data['matka'] ?? '');
$maaliOtsikko = $matkaOtsikko !== '' ? ('Maali ' . $matkaOtsikko) : 'Maali';
$parentTarget = $embedMode ? ' target="_parent" rel="noopener"' : '';
?>

<section class="lb-paneeli lb-paneeli--online">
  <div class="lb-otsikko">
    <span><?php
      $sarjaExtra = ['nakyma' => $nakyma];
      if ($seuraSuodatin !== '') {
          $sarjaExtra['seura'] = $seuraSuodatin;
      }
      if (!empty($onViesti) && $osuusValittu > 0) {
          $sarjaExtra['osuus'] = $osuusValittu;
      }
      echo sarjaLinkHtml((int) $kilpailu_id, (string) $sarjaNimi, $data['matka'] ?? null, $sarjaExtra, 'lb-sarja-link');
      if (!empty($onViesti) && $osuusValittu > 0) {
          echo ' · osuus ' . (int) $osuusValittu;
      }
    ?></span>
    <small>Maalissa <?= (int) $maalissaLkm ?>/<?= (int) $yhteensa ?><?= $sarjaPaivitysHtml() ?></small>
  </div>

  <div class="lb-scroll">
    <div class="<?= $rowClass ?> lb-head"<?= $vaStyle ?>>
      <span class="lb-sija">Sija</span>
      <span class="lb-nimi">Nimi</span>
      <span class="lb-lahto">Lähtö</span>
      <?php foreach ($onlinePisteet as $piste): ?>
        <span class="lb-va"><?= (int) $piste ?>. va</span>
      <?php endforeach; ?>
      <span class="lb-aika"><?= htmlspecialchars($maaliOtsikko) ?></span>
    </div>

    <?php if (!empty($onlineRivit)): ?>
      <?php foreach ($onlineRivit as $r): ?>
        <?php
        $onStatus = !empty($r['_status']);
        $maalissa = !$onStatus && !empty($r['_maalissa']);
        if ($maalissa) {
            $sija++;
        }
        $kid = (int) ($r['kilpailija_id'] ?? 0);
        $omatVa = $valiaKartta[$kid] ?? [];
        $cls = $tulosRiviLuokka($r, $onStatus, $maalissa);
        $juoksija = '';
        $joukkueNimi = '';
        $nimiTxt = (string) ($r['nimi'] ?? '');
        if (!empty($onViesti) && $osuusValittu > 0) {
            $juoksija = trim((string) ($r['nimi'] ?? ''));
            $joukkueNimi = trim((string) ($r['joukkue'] ?? ''));
            $nimiTxt = $juoksija !== '' ? $juoksija : $joukkueNimi;
        }
        $nroTxt = formatKilpailijaNumero($r['numero'] ?? null);
        $nimiNaytto = $nimiTxt;
        if ($nroTxt !== '' && $nroTxt !== '—') {
            $nimiNaytto .= ' (' . $nroTxt . ')';
        }
        $lahtoKello = formatLahtoaikaKello(
            (string) ($r['pirila_lahto_at'] ?? $r['lahtoaika_naytto'] ?? '')
        );
        if ($lahtoKello !== '' && preg_match('/^(\d{2}:\d{2})/', $lahtoKello, $lm)) {
            $lahtoKello = $lm[1];
        }
        $seuraExtra = ['nakyma' => $nakyma];
        if ($embedMode) {
            $seuraExtra['embed'] = 1;
            $seuraExtra['_target'] = '_parent';
        }
        $naytaSija = $maalissa
            ? ((!empty($onViesti) && $osuusValittu > 0 && ($r['osuus_sija'] ?? null) !== null)
                ? ((int) $r['osuus_sija'] . '.')
                : ($sija . '.'))
            : '·';
        $linkitaNimi = $onPirila && ($maalissa || $onStatus);
        $metaPalat = [];
        if (!empty($r['seura'])) {
            $metaPalat[] = seuraLinkHtml(
                (int) $kilpailu_id,
                (string) $r['seura'],
                'seuranta',
                $seuraExtra,
                'lb-seura-link'
            );
        }
        if ($joukkueNimi !== '' && $juoksija !== '') {
            $metaPalat[] = htmlspecialchars($joukkueNimi);
        }
        ?>
        <div class="<?= $rowClass ?><?= $cls ?>"<?= $vaStyle ?>>
          <span class="lb-sija"><?= htmlspecialchars($naytaSija) ?></span>
          <span class="lb-nimi">
            <span class="lb-nimi-paa" title="<?= htmlspecialchars($nimiTxt, ENT_QUOTES, 'UTF-8') ?>">
              <?php if ($linkitaNimi): ?>
                <a class="lb-nimi-link" href="<?= htmlspecialchars(
                    valiajatLink($kilpailu_id, (int) ($r['numero'] ?? 0), true, $kid),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"<?= $parentTarget ?>><?= htmlspecialchars($nimiNaytto) ?></a>
              <?php else: ?>
                <?= htmlspecialchars($nimiNaytto) ?>
              <?php endif; ?>
            </span>
            <?php if ($metaPalat !== []): ?>
              <span class="lb-nimi-meta"><?= implode(
                  '<span class="lb-meta-sep" aria-hidden="true">·</span>',
                  $metaPalat
              ) ?></span>
            <?php endif; ?>
          </span>
          <span class="lb-lahto"><?= $lahtoKello !== '' ? htmlspecialchars($lahtoKello) : '—' ?></span>
          <?php foreach ($onlinePisteet as $piste): ?>
            <span class="lb-va">
              <?php if (isset($omatVa[$piste]) && $omatVa[$piste]['aika_sec'] !== null): ?>
                <?= htmlspecialchars(formatAikaSec((int) $omatVa[$piste]['aika_sec'])) ?>
                <?php if (!empty($omatVa[$piste]['sija'])): ?>
                  <span class="lb-va-sija">(<?= (int) $omatVa[$piste]['sija'] ?>)</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="lb-tag">—</span>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
          <span class="lb-aika">
            <?php if ($onStatus): ?>
              <?= htmlspecialchars($onIltarasti && function_exists('iltarastiTulosTila') ? iltarastiTulosTila($r) : statusLabel($r['status'] ?? 'OK')) ?>
            <?php elseif ($maalissa && !empty($data['nayta_ajat'])): ?>
              <?= htmlspecialchars(
                  !empty($onViesti)
                      ? formatAikaSecPirila((int) $r['maali_aika_sec'])
                      : formatLiveTulosAika($r, !empty($onViesti))
              ) ?>
            <?php elseif ($maalissa): ?>
              <?= (int) ($r['osuus_sija'] ?? $sija) ?>
            <?php else: ?>
              <?= liveAikaHtml($r, $live, true) ?>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="lb-empty"><?= htmlspecialchars($tyhjaViesti(
          !empty($onViesti) ? 'Ei joukkueita ' . $sarjaPaikassa . '.' : 'Ei kilpailijoita ' . $sarjaPaikassa . '.'
      )) ?></div>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php elseif ($naytaRastiValiajat && $nakyma === 'valiajat' && !empty($sarjaNaytot)): ?>
<?php foreach ($sarjaNaytot as $sarjaNimi => $naytto): ?>
<?php
$data = $naytto['data'];
$valiaRivit = $naytto['valiaRivit'];
$rastiPisteet = $naytto['rastiPisteet'];
$valiaKartta = $naytto['valiaKartta'];
$rastiKoodiByPiste = [];
foreach ($naytto['sarjaRastit'] ?? [] as $sr) {
    $rastiKoodiByPiste[(int) $sr['piste']] = (string) $sr['rasti_koodi'];
}
$sija = 0;
$karki = null;
if (!empty($onViesti)) {
    foreach ($valiaRivit as $vr) {
        $stOk = (($vr['status'] ?? 'OK') === 'OK') && empty($vr['_status']);
        if ($stOk && ($vr['maali_aika_sec'] ?? null) !== null) {
            $sec = (int) $vr['maali_aika_sec'];
            if ($karki === null || $sec < $karki) {
                $karki = $sec;
            }
        }
    }
} else {
    $karki = sarjanKarkiaika($data['maalissa'] ?? []);
}
$vaCols = count($rastiPisteet);
$rowClass = $vaCols > 0 ? 'lb-rivi has-va' : 'lb-rivi';
$vaStyle = $vaCols > 0 ? ' style="--va-cols:' . $vaCols . '"' : '';
$vaSplitSijat = ($rastiPisteet !== [] && $valiaKartta !== [])
    ? laskeRastiValiajatSijat($valiaKartta, $rastiPisteet)
    : [];
$maalissaLkm = 0;
foreach ($valiaRivit as $vr) {
    $st = strtoupper(trim((string) ($vr['status'] ?? 'OK')));
    if (in_array($st, ['DSQ', 'DNF', 'DNS'], true)) {
        $maalissaLkm++;
    } elseif ($st === 'OK' && ($vr['maali_aika_sec'] ?? null) !== null) {
        $maalissaLkm++;
    }
}
$yhteensa = count($valiaRivit);
?>

<section class="lb-paneeli">
  <div class="lb-otsikko">
    <span><?php
      $sarjaExtra = ['nakyma' => $nakyma];
      if ($seuraSuodatin !== '') {
          $sarjaExtra['seura'] = $seuraSuodatin;
      }
      echo sarjaLinkHtml((int) $kilpailu_id, (string) $sarjaNimi, $data['matka'] ?? null, $sarjaExtra, 'lb-sarja-link');
    ?> · Rastiväliajat<?php if (!empty($onViesti) && $osuusValittu > 0): ?> · osuus <?= (int) $osuusValittu ?><?php endif; ?></span>
    <small>Maalissa <?= (int) $maalissaLkm ?>/<?= (int) $yhteensa ?><?= $sarjaPaivitysHtml() ?></small>
  </div>

  <div class="lb-scroll">
    <div class="<?= $rowClass ?> lb-head"<?= $vaStyle ?>>
      <span class="lb-sija">Sija</span>
      <?php $tulostaNumeroSolu('Nro'); ?>
      <span class="lb-nimi-lyh" title="Sukunimi">···</span>
      <span class="lb-nimi"><?= !empty($onViesti) ? 'Juoksija / joukkue' : 'Nimi' ?></span>
      <?php foreach ($rastiPisteet as $piste): ?>
        <span class="lb-va lb-va-head">
          <span class="lb-va-piste"><?= (int) $piste ?>.</span>
          <?php if (!empty($rastiKoodiByPiste[$piste])): ?>
            <span class="lb-va-koodi"><?= htmlspecialchars($rastiKoodiByPiste[$piste], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
      <span class="lb-aika">Tulos</span>
      <span class="lb-ero">Ero</span>
    </div>

    <?php if (!empty($valiaRivit)): ?>
      <?php foreach ($valiaRivit as $r): ?>
        <?php
        $onStatus = (($r['status'] ?? 'OK') !== 'OK');
        $maalissa = !$onStatus && (($r['maali_aika_sec'] ?? null) !== null || !empty($r['maaliaika']));
        if ($maalissa) {
            $sija++;
        }
        $kid = (int) $r['kilpailija_id'];
        $omatVa = $valiaKartta[$kid] ?? [];
        $omatSplit = $vaSplitSijat[$kid] ?? [];
        $ero = $maalissa ? formatEroKeulaan($r['maali_aika_sec'] ?? null, $karki) : '';
        $cls = $tulosRiviLuokka($r, $onStatus, $maalissa);
        $lyh = sukunimiLyhenne($r['sukunimi'] ?? null, $r['nimi'] ?? null);
        ?>
        <div class="lb-rivi-ryhma<?= $cls ?>">
        <div class="<?= $rowClass ?><?= $cls ?>"<?= $vaStyle ?>>
          <span class="lb-sija"><?= $maalissa ? $sija . '.' : '·' ?></span>
          <?php $tulostaNumeroSolu(htmlspecialchars(formatKilpailijaNumero($r['numero'] ?? null))); ?>
          <span class="lb-nimi-lyh" title="<?= htmlspecialchars((string) ($r['nimi'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lyh, ENT_QUOTES, 'UTF-8') ?></span>
          <?php
          // Viestissä rivit ovat osuusjuoksijoita — näytä Emit-linkki kuten Hk:ssä
          if (!empty($onViesti) && !empty($r['_viesti_osuus'])) {
              $juoksija = trim((string) ($r['nimi'] ?? ''));
              $joukkueNimi = trim((string) ($r['joukkue'] ?? ''));
              $osNro = (int) ($r['osuus'] ?? $r['_viesti_osuus'] ?? 0);
              echo '<span class="lb-nimi">';
              if ($juoksija !== '' && $kid > 0 && ($maalissa || $onStatus)) {
                  $href = valiajatLink(
                      $kilpailu_id,
                      (int) ($r['numero'] ?? 0),
                      true,
                      $kid,
                      $osNro > 0 ? $osNro : null
                  );
                  echo '<a class="lb-nimi-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                      . ($embedMode ? ' target="_parent" rel="noopener"' : '') . '>'
                      . htmlspecialchars($juoksija) . '</a>';
              } else {
                  echo '<span class="lb-nimi-paa">' . htmlspecialchars($juoksija !== '' ? $juoksija : '—') . '</span>';
              }
              $meta = [];
              if ($osNro > 0 && $osuusValittu < 1) {
                  $meta[] = $osNro . '. osuus';
              }
              if ($joukkueNimi !== '') {
                  $meta[] = $joukkueNimi;
              }
              if ($meta !== []) {
                  echo '<span class="lb-nimi-meta">' . htmlspecialchars(implode(' · ', $meta)) . '</span>';
              }
              echo '</span>';
          } else {
              $tulostaNimiSolu(
                  $r,
                  $maalissa,
                  empty($r['_viesti']) && ($maalissa || $onStatus)
              );
          }
          ?>
          <?php foreach ($rastiPisteet as $piste): ?>
            <?php
            $splitInfo = $omatSplit[(int) $piste] ?? ['split_sec' => null, 'sija' => null];
            $omaSplit = $splitInfo['split_sec'] !== null ? (int) $splitInfo['split_sec'] : null;
            ?>
            <span class="lb-va"><?= formatLbVaStackHtml(
                $omatVa[$piste] ?? null,
                $omaSplit,
                $splitInfo['sija']
            ) ?></span>
          <?php endforeach; ?>
          <span class="lb-aika">
            <?php if ($onStatus): ?>
              <?= htmlspecialchars($onIltarasti && function_exists('iltarastiTulosTila') ? iltarastiTulosTila($r) : statusLabel($r['status'] ?? 'OK')) ?>
            <?php elseif ($maalissa): ?>
              <?= htmlspecialchars(formatLiveTulosAika($r, true)) ?>
            <?php else: ?>
              <?= liveAikaHtml($r, $live, true) ?>
            <?php endif; ?>
          </span>
          <span class="lb-ero"><?= $ero !== '' ? htmlspecialchars($ero) : '' ?></span>
        </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="lb-empty"><?= htmlspecialchars($tyhjaViesti(
          'Ei joukkueita/kilpailijoita tai rastiväliaikoja ' . $sarjaPaikassa . '.'
      )) ?></div>
    <?php endif; ?>
  </div>
</section>

<?php endforeach; ?>

<?php elseif ($seuraKaikkiSarjat && $seuraSuodatin !== '' && empty($sarjaNaytot) && !empty($sarjat)): ?>
  <div class="lb-paneeli"><div class="lb-empty"><?= htmlspecialchars(
      $onViesti
          ? 'Ei joukkueita seuralla “' . $seuraSuodatin . '”.'
          : 'Ei kilpailijoita seuralla “' . $seuraSuodatin . '”.'
  ) ?></div></div>
<?php elseif (empty($sarjat)): ?>
  <div class="lb-paneeli"><div class="lb-empty">Ei kilpailijoita tai tuloksia.</div></div>
<?php endif; ?>

  </div>
<?php
$fragHtml = ob_get_clean();
liveCacheSet($fragCacheKey, $fragHtml, LIVE_CACHE_TTL_SEC);

if ($fragmentOnly) {
    echo $fragHtml;
    exit;
}

echo $fragHtml;
?>

<script src="../assets/js/seuranta.js?v=<?= TULOSPALVELU_ASSET_V ?>"></script>
<script src="../assets/js/live_refresh.js?v=<?= TULOSPALVELU_ASSET_V ?>"></script>
<script src="../assets/js/tulos_haku.js?v=<?= TULOSPALVELU_ASSET_V ?>"></script>
<?php if ($embedMode): ?>
<script src="../assets/js/embed_nav.js?v=<?= TULOSPALVELU_ASSET_V ?>"></script>
<?php endif; ?>
<script>
(function () {
  function paivitaStickyOffset() {
    var controls = document.querySelector('[data-lb-sticky-controls]');
    if (!controls) return;
    var h = Math.ceil(controls.getBoundingClientRect().height || 0);
    if (h > 0) {
      // Pieni rako kontrollien ja otsikkorivin väliin — ei turhaa tyhjää
      document.documentElement.style.setProperty('--lb-sticky-offset', h + 'px');
    }
  }
  paivitaStickyOffset();
  window.addEventListener('resize', paivitaStickyOffset);
  document.addEventListener('lb-live-replaced', paivitaStickyOffset);
  if (typeof ResizeObserver !== 'undefined') {
    var c = document.querySelector('[data-lb-sticky-controls]');
    if (c) new ResizeObserver(paivitaStickyOffset).observe(c);
  }

  // Säilytä sarja (URL on ensisijainen; localStorage varmistaa päivitysten yli).
  // Näkymää (nakyma) EI palauteta tallenteesta — ylälinkki/chip määrää sivun.
  try {
    var kid = <?= (int) $kilpailu_id ?>;
    var key = 'jah_seuranta_valinta_' + kid;
    var sel = document.getElementById('sarja') || document.getElementById('matka');
    var params = new URLSearchParams(window.location.search);
    var urlSarja = params.get('sarja') || '';
    var urlMatka = params.get('matka') || '';
    var urlNakyma = params.get('nakyma') || 'tulokset';
    var tallennettu = null;
    try { tallennettu = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e2) { tallennettu = null; }

    // Jos URL:ssa ei ole sarjaa/matkaa mutta tallenne on → palaa siihen (säilyttää valinnan).
    // Seurasuodatin: älä palauta sarjaa — näytetään kaikki seuran juoksijat.
    var urlSeura = params.get('seura') || '';
    if (tallennettu && !urlSarja && !urlMatka && !urlSeura && (tallennettu.sarja || tallennettu.matka)) {
      var p = new URLSearchParams(window.location.search);
      // Älä ylikirjoita nakymaa — Online / Tulokset / Rastiväliajat -linkit menevät suoraan.
      if (tallennettu.ryhmittele === 'matka' && tallennettu.matka) {
        p.set('ryhmittele', 'matka');
        p.set('matka', tallennettu.matka);
        p.delete('sarja');
      } else if (tallennettu.sarja) {
        p.set('sarja', tallennettu.sarja);
        p.delete('ryhmittele');
        p.delete('matka');
      }
      if (tallennettu.osuus) p.set('osuus', tallennettu.osuus);
      var next = window.location.pathname + '?' + p.toString();
      if (next !== window.location.pathname + window.location.search) {
        window.location.replace(next);
        return;
      }
    }

    localStorage.setItem(key, JSON.stringify({
      sarja: urlSarja,
      matka: urlMatka,
      nakyma: urlNakyma,
      ryhmittele: params.get('ryhmittele') || '',
      osuus: params.get('osuus') || ''
    }));
    if (sel) {
      sel.addEventListener('change', function () {
        try {
          var p2 = new URLSearchParams(window.location.search);
          p2.set(sel.name, sel.value);
          localStorage.setItem(key, JSON.stringify({
            sarja: p2.get('sarja') || '',
            matka: p2.get('matka') || '',
            nakyma: p2.get('nakyma') || 'tulokset',
            ryhmittele: p2.get('ryhmittele') || '',
            osuus: p2.get('osuus') || ''
          }));
        } catch (e3) {}
      });
    }
  } catch (e) {}
})();
</script>

<?php
tulospalveluLoppu();
pageEnd();
?>
