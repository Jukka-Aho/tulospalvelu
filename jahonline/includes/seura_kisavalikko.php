<?php

/**
 * Seuran / oman kisan lisävalikot roolin mukaan.
 * Käyttää ACL:ää (paakayttaja_id + rooli + kisa_tyyppi).
 */

require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/kisa_luonne.php';

/**
 * Näytetäänkö Seura-osio tälle kilpailulle nykyiselle käyttäjälle.
 */
function voiNahdaSeuraOsio(array $kilpailu): bool
{
    if (empty($_SESSION['user'])) {
        return false;
    }

    return voiHallitaKilpailua($kilpailu)
        || voiAjanottaaKilpailua($kilpailu)
        || voiLahtoluennataKilpailua($kilpailu)
        || (function_exists('voiLahtotoimiaKilpailua') && voiLahtotoimiaKilpailua($kilpailu))
        || (function_exists('voiAmpumapaikkaKilpailua') && voiAmpumapaikkaKilpailua($kilpailu))
        || voiKatsellaTvKilpailua($kilpailu);
}

/**
 * Seura-osion linkit yhdelle kilpailulle.
 *
 * @return list<array{href:string,label:string,primary?:bool,hint?:string}>
 */
function seuraKisavalikkoLinkit(array $kilpailu, bool $public = true): array
{
    if (!voiNahdaSeuraOsio($kilpailu)) {
        return [];
    }

    $id = (int) ($kilpailu['id'] ?? 0);
    if ($id <= 0) {
        return [];
    }

    $linkit = function_exists('julkinenLinkit')
        ? julkinenLinkit($id, $public)
        : [];

    $out = [];

    if (voiAjanottaaKilpailua($kilpailu) && kisaNaytaValikko($kilpailu, 'ajanotto')) {
        $out[] = [
            'href' => $linkit['mobiili'] ?? ('/public/mobiili_ajanotto.php?kilpailu_id=' . $id),
            'label' => 'Ajanotto (ruudukko)',
            'hint' => 'Mobiiliajanotto',
        ];
        $out[] = [
            'href' => $linkit['nappi'] ?? ('/public/mobiili_ajanotto.php?kilpailu_id=' . $id . '&tila=nappi'),
            'label' => 'Maalinappi',
            'hint' => 'Maaliaika napilla',
        ];
        $out[] = [
            'href' => $linkit['numerot'] ?? ('/public/mobiili_ajanotto.php?kilpailu_id=' . $id . '&tila=numerot'),
            'label' => 'Numerot',
            'hint' => 'Numerosyöttö järjestyksessä',
        ];
    }

    if (voiLahtoluennataKilpailua($kilpailu) && kisaNaytaValikko($kilpailu, 'emit')) {
        $out[] = [
            'href' => $linkit['lahtoluennat'] ?? ('/public/lahtoluennat.php?kilpailu_id=' . $id),
            'label' => 'Lähtöluenta',
            'hint' => 'Lähtöluenta HID / OTG',
        ];
        $onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
        $out[] = [
            'href' => $onViesti
                ? ($linkit['viesti_lukija'] ?? ('/public/viesti_lukija.php?kilpailu_id=' . $id))
                : ($linkit['emit_lukija'] ?? ('/public/emit_lukija.php?kilpailu_id=' . $id)),
            'label' => $onViesti ? 'Viesti-lukija' : 'Emit-lukija',
            'hint' => $onViesti ? 'Osuuksien lähtö, vaihto ja maali' : 'Sisäänluku ja leimantarkastus',
        ];
        $out[] = [
            'href' => $linkit['leimantarkastus'] ?? ('/public/leimantarkastus.php?kilpailu_id=' . $id),
            'label' => 'Leimantarkastus',
            'hint' => 'USB-lukija: rastit ja aika',
        ];
        $out[] = [
            'href' => $linkit['mobiili_kilpailija'] ?? ('/public/mobiili_kilpailija.php?kilpailu_id=' . $id),
            'label' => 'Muokkaa kilpailijaa',
            'hint' => 'Nimi, status ja emit-leimat',
        ];
    }

    if (voiHallitaKilpailua($kilpailu) || (function_exists('voiLahtotoimiaKilpailua') && voiLahtotoimiaKilpailua($kilpailu))) {
        $out[] = [
            'href' => $linkit['nimenhuuto'] ?? ('/public/nimenhuuto.php?kilpailu_id=' . $id),
            'label' => 'Nimenhuuto',
            'hint' => 'Lue ääneen lähdön nimet',
        ];
        $out[] = [
            'href' => $linkit['lahtokello'] ?? ('/public/lahtokello.php?kilpailu_id=' . $id),
            'label' => 'Lähtökello',
            'hint' => 'Piippaa lähtöihin',
        ];
    }

    if (
        function_exists('kisaOnAmpumapaikat') && kisaOnAmpumapaikat($kilpailu)
        && function_exists('voiAmpumapaikkaKilpailua') && voiAmpumapaikkaKilpailua($kilpailu)
    ) {
        $out[] = [
            'href' => $linkit['ampuma'] ?? ('/public/ampumapaikka.php?kilpailu_id=' . $id),
            'label' => 'Ampumapaikka',
            'hint' => 'Ampumasakot',
        ];
    }

    if (voiKatsellaTvKilpailua($kilpailu)) {
        $out[] = [
            'href' => $linkit['tv'] ?? ('/public/tv.php?kilpailu_id=' . $id),
            'label' => 'TV-näyttö',
        ];
    }

    // Seuranta kaikille seuran toimijoille (live → Online-tulokset, muuten Tulokset)
    $out[] = [
        'key' => 'seuranta',
        'href' => $linkit['seuranta'] ?? ('/public/kilpailu.php?kilpailu_id=' . $id . '&osio=online'),
        'label' => function_exists('seurantaOtsikko') ? seurantaOtsikko($kilpailu) : 'Tulokset',
    ];

    if (voiHallitaKilpailua($kilpailu)) {
        $out[] = [
            'href' => $linkit['lahtolista'] ?? ('/public/lahtolista.php?kilpailu_id=' . $id),
            'label' => 'Lähtöajat',
        ];
        if (
            (function_exists('naytaIlmoittautuminenValikossa') && naytaIlmoittautuminenValikossa($kilpailu))
            || (function_exists('ilmoittautuminenAuki') && ilmoittautuminenAuki($kilpailu))
        ) {
            $out[] = [
                'href' => $linkit['ilmoittautuminen'] ?? ('/public/ilmoittautuminen.php?kilpailu_id=' . $id),
                'label' => 'Ilmoittautuminen',
            ];
            $out[] = [
                'href' => $linkit['ilmoittautuneet'] ?? ('/public/ilmoittautuneet.php?kilpailu_id=' . $id),
                'label' => 'Ilmoittautuneet',
            ];
        } elseif (!(function_exists('pirilaSynkkaPaalla') ? pirilaSynkkaPaalla($kilpailu) : (($kilpailu['kisa_tyyppi'] ?? 'normaali') === 'pirila'))) {
            $out[] = [
                'href' => $linkit['ilmoittautuneet'] ?? ('/public/ilmoittautuneet.php?kilpailu_id=' . $id),
                'label' => 'Ilmoittautuneet',
            ];
        }
        $out[] = [
            'href' => '/admin/index.php?kilpailu_id=' . $id,
            'label' => 'Hallinta',
            'hint' => 'Sarjat, kilpailijat, ajat',
        ];
    }

    return $out;
}

/**
 * Roolin mukainen PWA-työkalu (etusivun / topbarin pikavalinta).
 *
 * @return array{href:string,label:string,desc:string}|null
 */
function seuraRooliTyokalu(): ?array
{
    if (empty($_SESSION['user'])) {
        return null;
    }

    $rooli = kayttajaRooli();

    return match ($rooli) {
        'lahtopaikka' => [
            'href' => '/public/lahto_valinta.php',
            'label' => 'Emit-lukija',
            'desc' => 'Valitse kilpailu ja lue Emit-kortti lähtöluentaan.',
        ],
        'lahtotoiminnat' => [
            'href' => '/public/lahto_valinta.php',
            'label' => 'Lähtötoiminnat',
            'desc' => 'Lähtöluenta, nimenhuuto ja lähtökello.',
        ],
        'ampumapaikat' => [
            'href' => '/public/ampuma_valinta.php',
            'label' => 'Ampumapaikat',
            'desc' => 'Kirjaa ampumasakot kisan ampumapaikalla.',
        ],
        'ajanottaja' => [
            'href' => '/public/ajanotto_valinta.php',
            'label' => 'Ajanotto',
            'desc' => 'Ruudukko, maalinappi tai numerosyöttö oman seuran juoksukisoihin.',
        ],
        'tv' => [
            'href' => '/public/tv_valinta.php',
            'label' => 'TV-näyttö',
            'desc' => 'Avaa live-tulokset TV- tai tablettinäytölle.',
        ],
        'paakayttaja', 'admin' => [
            'href' => '/admin/index.php',
            'label' => 'Hallinta',
            'desc' => 'Kilpailut, käyttäjät, seurat ja tulospalvelun työkalut.',
        ],
        default => (function_exists('voiKisahallintaan') && voiKisahallintaan())
            ? [
                'href' => '/admin/index.php',
                'label' => 'Hallinta',
                'desc' => 'Oman seuran kilpailujen hallinta.',
            ]
            : null,
    };
}

/**
 * Tulosta Seura-paneeli kilpailusivulle.
 *
 * @param array{compact?:bool} $opts compact=true → kapea palkki (online-kuori)
 */
function seuraKisavalikkoHtml(array $kilpailu, bool $public = true, array $opts = []): void
{
    $items = seuraKisavalikkoLinkit($kilpailu, $public);
    if ($items === []) {
        return;
    }

    $rooli = rooliLabel(kayttajaRooli());
    $compact = !empty($opts['compact']);

    if ($compact) {
        echo '<nav class="lb-seura-strip" aria-label="Seuran kisatyökalut">';
        echo '<span class="lb-seura-strip-label">Seura'
            . ($rooli !== '' ? ' · ' . htmlspecialchars($rooli, ENT_QUOTES, 'UTF-8') : '')
            . '</span>';
        echo '<div class="lb-seura-strip-linkit">';
        foreach ($items as $item) {
            $label = (string) ($item['label'] ?? '');
            // Seuranta on jo ylävalikossa kuoressa
            if (($item['key'] ?? '') === 'seuranta') {
                continue;
            }
            $title = !empty($item['hint']) ? ' title="' . htmlspecialchars((string) $item['hint'], ENT_QUOTES, 'UTF-8') . '"' : '';
            echo '<a class="lb-seura-strip-link" href="' . htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') . '"'
                . $title . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</a>';
        }
        echo '</div>';
        echo '</nav>';

        return;
    }

    echo '<div class="lb-paneeli lb-hub lb-hub--seura">';
    echo '<div class="lb-hub-otsikko">Seura</div>';
    echo '<p class="lb-hub-ohje">Oman seuran kisatyökalut'
        . ($rooli !== '' ? ' · ' . htmlspecialchars($rooli, ENT_QUOTES, 'UTF-8') : '')
        . '</p>';
    echo '<div class="lb-hub-linkit">';
    foreach ($items as $item) {
        $cls = 'lb-hub-link' . (!empty($item['primary']) ? ' lb-hub-link--primary' : '');
        $title = !empty($item['hint']) ? ' title="' . htmlspecialchars((string) $item['hint'], ENT_QUOTES, 'UTF-8') . '"' : '';
        echo '<a class="' . $cls . '" href="' . htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') . '"'
            . $title . '>'
            . htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8')
            . '</a>';
    }
    echo '</div>';
    echo '</div>';
}
