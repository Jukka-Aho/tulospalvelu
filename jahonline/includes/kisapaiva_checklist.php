<?php

/**
 * Kisapäivän valmiuschecklist kilpailulle.
 *
 * @return array{ok:bool, kohdat:array<int,array{id:string,ok:bool,otsikko:string,vihje:string,href?:string}>}
 */
function kisapaivaChecklist(PDO $pdo, array $kilpailu): array
{
    $kid = (int) ($kilpailu['id'] ?? 0);
    $pirila = function_exists('pirilaSynkkaPaalla') && pirilaSynkkaPaalla($kilpailu);
    $linkit = function_exists('julkinenLinkit') ? julkinenLinkit($kid) : [];

    $sarjat = 0;
    $kilpailijat = 0;
    $numerolliset = 0;
    $lahdot = 0;
    $lahdotAika = 0;
    $lahdotVaaraPaiva = 0;
    $duplikaatit = 0;
    $maalit = 0;
    $leimat = 0;
    $jononumerot = 0;
    $testattu = false;

    if ($kid > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sarjat WHERE kilpailu_id = ?');
        $st->execute([$kid]);
        $sarjat = (int) $st->fetchColumn();

        $st = $pdo->prepare('SELECT COUNT(*) FROM kilpailijat WHERE kilpailu_id = ?');
        $st->execute([$kid]);
        $kilpailijat = (int) $st->fetchColumn();

        $st = $pdo->prepare('
            SELECT COUNT(*) FROM kilpailijat
            WHERE kilpailu_id = ? AND numero IS NOT NULL AND numero > 0
        ');
        $st->execute([$kid]);
        $numerolliset = (int) $st->fetchColumn();

        $st = $pdo->prepare('
            SELECT COUNT(*) FROM (
                SELECT numero FROM kilpailijat
                WHERE kilpailu_id = ? AND numero IS NOT NULL AND numero > 0
                GROUP BY numero HAVING COUNT(*) > 1
            ) t
        ');
        $st->execute([$kid]);
        $duplikaatit = (int) $st->fetchColumn();

        $st = $pdo->prepare('SELECT COUNT(*) FROM lahdot WHERE kilpailu_id = ?');
        $st->execute([$kid]);
        $lahdot = (int) $st->fetchColumn();

        $st = $pdo->prepare("
            SELECT COUNT(*) FROM lahdot
            WHERE kilpailu_id = ? AND lahtoaika > '1000-01-01'
        ");
        $st->execute([$kid]);
        $lahdotAika = (int) $st->fetchColumn();

        if (function_exists('lahtoAikaPoikkeaaKisapaivasta')) {
            $st = $pdo->prepare('SELECT lahtoaika FROM lahdot WHERE kilpailu_id = ?');
            $st->execute([$kid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lahtoAt) {
                if (lahtoAikaPoikkeaaKisapaivasta($kilpailu, (string) $lahtoAt)) {
                    $lahdotVaaraPaiva++;
                }
            }
        }

        $st = $pdo->prepare('
            SELECT COUNT(*) FROM ajat a
            JOIN kilpailijat k ON k.id = a.kilpailija_id
            WHERE k.kilpailu_id = ?
        ');
        $st->execute([$kid]);
        $maalit = (int) $st->fetchColumn();

        try {
            if (is_readable(__DIR__ . '/maali_jono.php')) {
                require_once __DIR__ . '/maali_jono.php';
                if (function_exists('maaliJonoVarmistaTaulut')) {
                    maaliJonoVarmistaTaulut($pdo);
                }
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM maali_leimat WHERE kilpailu_id = ?');
            $st->execute([$kid]);
            $leimat = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM maali_jononumerot WHERE kilpailu_id = ?');
            $st->execute([$kid]);
            $jononumerot = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            $leimat = 0;
            $jononumerot = 0;
        }

        try {
            if (is_readable(__DIR__ . '/ajanotto_audit.php')) {
                require_once __DIR__ . '/ajanotto_audit.php';
                if (function_exists('ajanottoAuditVarmistaTaulut')) {
                    ajanottoAuditVarmistaTaulut($pdo);
                }
                $st = $pdo->prepare("
                    SELECT COUNT(*) FROM ajanotto_audit
                    WHERE kilpailu_id = ?
                      AND tapahtuma IN ('leima_lisays','numero_lisays','aika_lisays')
                ");
                $st->execute([$kid]);
                $testattu = ((int) $st->fetchColumn()) > 0 || $leimat > 0 || $jononumerot > 0 || $maalit > 0;
            } else {
                $testattu = $leimat > 0 || $jononumerot > 0 || $maalit > 0;
            }
        } catch (Throwable $e) {
            $testattu = $leimat > 0 || $jononumerot > 0 || $maalit > 0;
        }
    }

    $tokenOk = !empty($kilpailu['api_token']);
    $kohdat = [];

    $kohdat[] = [
        'id' => 'sarjat',
        'ok' => $sarjat > 0,
        'otsikko' => 'Sarjat luotu',
        'vihje' => $sarjat > 0 ? "{$sarjat} sarjaa" : 'Lisää vähintään yksi sarja',
        'href' => 'sarjat.php',
    ];
    $kohdat[] = [
        'id' => 'kilpailijat',
        'ok' => $kilpailijat > 0,
        'otsikko' => 'Kilpailijoita listalla',
        'vihje' => $kilpailijat > 0 ? "{$kilpailijat} kilpailijaa ({$numerolliset} numerolla)" : 'Lisää kilpailijoita',
        'href' => 'kilpailijat.php',
    ];
    $kohdat[] = [
        'id' => 'numerot',
        'ok' => $numerolliset > 0 && $duplikaatit === 0,
        'otsikko' => 'Kilpailunumerot kunnossa',
        'vihje' => $duplikaatit > 0
            ? "Duplikaatteja: {$duplikaatit} numeroa"
            : ($numerolliset > 0 ? 'Ei duplikaatteja' : 'Anna kilpailunumeroita ajanottoa varten'),
        'href' => 'kilpailijat.php',
    ];
    $kohdat[] = [
        'id' => 'lahdot',
        'ok' => $lahdot > 0 && $lahdotAika === $lahdot && $lahdotVaaraPaiva === 0,
        'otsikko' => 'Lähtöajat asetettu',
        'vihje' => $lahdot === 0
            ? 'Luo lähtö ja aseta aika'
            : ($lahdotVaaraPaiva > 0
                ? "{$lahdotVaaraPaiva} lähtöä ei ole kisapäivänä — nettoajat voivat olla päiviä pitkiä"
                : ($lahdotAika < $lahdot ? "{$lahdotAika}/{$lahdot} lähdöllä on aika" : "{$lahdot} lähtöä OK")),
        'href' => 'lahdot.php',
    ];

    if (!$pirila) {
        $kohdat[] = [
            'id' => 'token',
            'ok' => $tokenOk,
            'otsikko' => 'API-avain ajanottoon',
            'vihje' => $tokenOk ? 'Token asetettu' : 'Luo / päivitä API-avain kilpailuasetuksista',
            'href' => 'kilpailut.php',
        ];
        $kohdat[] = [
            'id' => 'maalinappi',
            'ok' => $tokenOk && $numerolliset > 0,
            'otsikko' => 'Maalinappi + Numerot valmiina',
            'vihje' => 'Avaa kahdelle laitteelle ennen maalia',
            'href' => $linkit['nappi'] ?? null,
        ];
        $kohdat[] = [
            'id' => 'testi',
            'ok' => $testattu,
            'otsikko' => 'Testaa leima + numero',
            'vihje' => $testattu
                ? ($leimat || $jononumerot
                    ? "Testijälki: {$leimat} leimaa, {$jononumerot} nroa"
                    : 'Ajanottoa on jo käytetty')
                : 'Paina yksi testileima Maalinapilla ja syötä testi-numero Numerot-ikkunassa',
            'href' => $linkit['nappi'] ?? null,
            'vaadittu' => false,
        ];
    } else {
        $kohdat[] = [
            'id' => 'pirila',
            'ok' => true,
            'otsikko' => 'Pirilä-synkka käytössä',
            'vihje' => 'Maalit tulevat Pirilästä',
            'href' => 'pirila.php',
        ];
    }

    $kohdat[] = [
        'id' => 'seuranta',
        'ok' => $sarjat > 0,
        'otsikko' => function_exists('seurantaOtsikko') ? seurantaOtsikko($kilpailu) : 'Tulokset',
        'vihje' => $maalit > 0 ? "{$maalit} maalia kirjattu" : 'Avaa seuranta kisapäivänä',
        'href' => $linkit['seuranta'] ?? null,
        'vaadittu' => false,
    ];
    $kohdat[] = [
        'id' => 'tv',
        'ok' => $sarjat > 0,
        'otsikko' => 'TV-näkymä',
        'vihje' => 'Viimeksi maalissa + sarjatulokset',
        'href' => $linkit['tv'] ?? null,
        'vaadittu' => false,
    ];

    // Valmis lähtöön = pakolliset kohdat OK (testi/seuranta/tv eivät estä)
    $valmisLahtoon = true;
    $ok = true;
    foreach ($kohdat as $k) {
        $vaadittu = !array_key_exists('vaadittu', $k) || !empty($k['vaadittu']);
        if (empty($k['ok'])) {
            $ok = false;
            if ($vaadittu) {
                $valmisLahtoon = false;
            }
        }
    }

    return [
        'ok' => $ok,
        'valmis_lahtoon' => $valmisLahtoon,
        'kohdat' => $kohdat,
        'pirila' => $pirila,
    ];
}
