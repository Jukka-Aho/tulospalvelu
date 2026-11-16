<?php

function adminShell($active, $pdo = null)
{
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/helpers.php';
    if (is_readable(__DIR__ . '/acl.php')) {
        require_once __DIR__ . '/acl.php';
    }
    require_once __DIR__ . '/kilpailu_context.php';
    require_once __DIR__ . '/pirila_mode.php';
    require_once __DIR__ . '/kisa_luonne.php';
    if (is_readable(__DIR__ . '/palvelu_valitsin.php')) {
        require_once __DIR__ . '/palvelu_valitsin.php';
    }

    if ($pdo instanceof PDO) {
        kisaLuonneVarmista($pdo);
        if (function_exists('kayttajaRoolitVarmista')) {
            kayttajaRoolitVarmista($pdo);
        }
    }
    $kilpailu = ($pdo instanceof PDO) ? haeValittuKilpailu($pdo) : null;
    $pirila = $kilpailu && pirilaSynkkaPaalla($kilpailu);
    $iltarasti = $kilpailu && function_exists('kisaOnIltarasti') && kisaOnIltarasti($kilpailu);
    $kuori = $iltarasti && !empty($GLOBALS['jah_iltarasti_kuori']);
    $vainIltarasti = function_exists('onIltarasti') && onIltarasti() && !onPaakayttaja();
    if (($kuori || $iltarasti || $vainIltarasti) && is_readable(__DIR__ . '/iltarasti.php')) {
        require_once __DIR__ . '/iltarasti.php';
    }

    echo '<aside class="admin-sidebar" id="admin-sidebar" tabindex="-1" aria-label="Hallintavalikko">';
    echo '<style>.active-kilpailu-seura{margin-top:4px;font-size:13px;font-weight:500}'
        . '.active-kilpailu-lisatieto{margin-top:2px;font-size:12px}</style>';
    echo '<div class="admin-sidebar-inner">';
    echo '<div class="admin-sidebar-header">';
    echo '<span class="admin-sidebar-title">Hallinta</span>';
    echo '<button type="button" class="admin-sidebar-close jah-ruksi" id="admin-sidebar-close" aria-label="Sulje valikko" title="Sulje">'
        . (function_exists('jahRuksiSvg') ? jahRuksiSvg() : '×') . '</button>';
    echo '</div>';

    if ($kilpailu) {
        $linkit = julkinenLinkit($kilpailu['id']);

        echo '<div class="admin-section">';
        echo '<div class="section-label">' . ($iltarasti ? 'Tämä tapahtuma' : 'Tämä kilpailu') . '</div>';
        echo '<div class="active-kilpailu">';
        echo '<strong>' . htmlspecialchars($kilpailu['nimi']) . '</strong>';
        $seuraNimiNav = '';
        if (function_exists('kilpailuSeuraNimi') && $pdo instanceof PDO) {
            $seuraNimiNav = kilpailuSeuraNimi($pdo, $kilpailu);
        } else {
            $seuraNimiNav = trim((string) ($kilpailu['seura_nimi'] ?? ''));
        }
        if ($seuraNimiNav !== '') {
            echo '<div class="active-kilpailu-seura">' . htmlspecialchars($seuraNimiNav) . '</div>';
        }
        $kisaLisat = [];
        if (!empty($kilpailu['paiva']) && function_exists('formatPaiva')) {
            $kisaLisat[] = formatPaiva($kilpailu['paiva']);
        }
        if (!empty($kilpailu['paikka'])) {
            $kisaLisat[] = (string) $kilpailu['paikka'];
        }
        if ($kisaLisat !== []) {
            echo '<div class="active-kilpailu-lisatieto muted">'
                . htmlspecialchars(implode(' · ', $kisaLisat)) . '</div>';
        }
        echo '<div class="active-kilpailu-meta">';
        echo '<span class="badge">' . htmlspecialchars(kilpailuTilaLabel($kilpailu['tila'] ?? '')) . '</span>';
        $luonneLabel = kisaLuonneLabel($kilpailu['luonne'] ?? null);
        if ($luonneLabel !== '') {
            echo ' <span class="badge">' . htmlspecialchars($luonneLabel) . '</span>';
        } elseif ($pirila) {
            echo ' <span class="badge">Suunnistus</span>';
        }
        if (!$kuori) {
            echo ' <a class="btn-small-link" href="'
                . ($iltarasti ? '/admin/iltarastit.php' : '/admin/index.php')
                . '">Vaihda</a>';
        }
        echo '</div></div>';

        echo '<nav class="admin-nav kisa-nav">';
        if ($kuori) {
            $kid = (int) $kilpailu['id'];
            $tpUrl = function_exists('iltarastiTulospalveluUrl')
                ? iltarastiTulospalveluUrl($kid)
                : (string) $linkit['emit_lukija'];
            $kioskiUrl = static function (string $sivu) use ($kid, $tpUrl): string {
                if ($sivu === 'tulospalvelu') {
                    return $tpUrl;
                }
                return function_exists('iltarastiKioskiUrl')
                    ? iltarastiKioskiUrl($sivu, $kid)
                    : '/public/iltarastit.php';
            };
            echo '<a href="' . htmlspecialchars($kioskiUrl('asetukset')) . '"'
                . ($active === 'asetukset' || $active === 'radat' ? ' class="active"' : '')
                . '>Tapahtuman asetukset</a>';
            echo '<a href="' . htmlspecialchars($kioskiUrl('osanottajat')) . '"'
                . ($active === 'kilpailijat' ? ' class="active"' : '')
                . '>Osanottajat</a>';
            echo '<a href="' . htmlspecialchars($kioskiUrl('esitykset')) . '"'
                . ($active === 'esitykset' ? ' class="active"' : '')
                . '>Esitykset</a>';
            echo '<a href="' . htmlspecialchars($tpUrl) . '"'
                . ($active === 'tulospalvelu' ? ' class="active"' : '')
                . '>Tulospalvelu</a>';
            echo '<a href="/public/automaatti.php?kilpailu_id=' . (int) $kid . '"'
                . ($active === 'automaatti' ? ' class="active"' : '')
                . '>Automaatti</a>';
            echo '<a href="' . htmlspecialchars($kioskiUrl('tulokset')) . '"'
                . ($active === 'tulokset' || $active === 'valiajat' ? ' class="active"' : '')
                . '>Tulokset</a>';
            $onViestiKuori = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
            if (!$onViestiKuori) {
                if (is_readable(__DIR__ . '/ssl_tulokset.php')) {
                    require_once __DIR__ . '/ssl_tulokset.php';
                }
                if (function_exists('sslTuloksetKuoriLinkki')) {
                    sslTuloksetKuoriLinkki($pdo instanceof PDO ? $pdo : null, $kilpailu, (int) $kid, $active);
                }
            }
            if ($active !== 'iltarastit') {
                echo '<a class="iltarasti-kioski-sulje" href="' . htmlspecialchars($kioskiUrl('hub')) . '">Sulje tapahtuma</a>';
            }
            echo '</nav>';
            echo '</div>';
        } elseif ($iltarasti) {
            $tpUrl = function_exists('iltarastiTulospalveluUrl')
                ? iltarastiTulospalveluUrl((int) $kilpailu['id'])
                : (string) $linkit['emit_lukija'];
            echo '<a href="' . htmlspecialchars($tpUrl) . '">Avaa tapahtuma</a>';
            echo '</nav>';
            echo '</div>';
        } else {
        $naytaPaikanpaa = !$pirila && (
            !empty($kilpailu['paikanpaa_ilmo'])
            || (function_exists('kisaOnJuoksu') && kisaOnJuoksu($kilpailu))
        );
        $nAjanotto = kisaNaytaValikko($kilpailu, 'ajanotto');
        $nEmit = kisaNaytaValikko($kilpailu, 'emit');
        $vk = static function (string $osio): bool {
            return !function_exists('tiliNaytaKisaValikko') || tiliNaytaKisaValikko($osio);
        };
        $vkKaikki = $vk('kaikki');
        $vkAjanotto = $vk('ajanotto');
        $vkLahto = $vk('lahtopaikka');
        $vkIlmo = $vk('ilmoittautuminen');
        if ($vkAjanotto) {
            echo '<div class="section-label section-label--sub">Ajanotto</div>';
            if (!$pirila && $nAjanotto) {
                echo '<a href="' . htmlspecialchars($linkit['mobiili']) . '">Mobiiliajanotto</a>';
                echo '<div class="section-label section-label--sub">Maalinappi + Numerot</div>';
                echo '<a href="' . htmlspecialchars($linkit['nappi']) . '">Maalinappi</a>';
                echo '<a href="' . htmlspecialchars($linkit['numerot']) . '">Numerot</a>';
            } elseif ($pirila && !empty($kilpailu['jaettu_jono'])) {
                echo '<a href="' . htmlspecialchars($linkit['numerot']) . '">Numerot</a>';
            }
            echo '<a href="/admin/ajanotto_loki.php" class="admin-nav-alaryhma-rivi'
                . ($active === 'ajanotto_loki' ? ' active' : '') . '">Ajanotto-loki</a>';
        }
        if ($vkLahto) {
            echo '<div class="section-label section-label--sub">Lähtö</div>';
            if ($pirila || $nEmit) {
                echo '<a href="' . htmlspecialchars($linkit['lahtoluennat']) . '">Lähtöluenta</a>';
            }
            echo '<a href="' . htmlspecialchars($linkit['nimenhuuto']) . '">Nimenhuuto</a>';
            echo '<a href="' . htmlspecialchars($linkit['lahtokello']) . '">Lähtökello</a>';
        }
        if ($vkKaikki) {
            echo '<div class="section-label section-label--sub">Tulostaulu</div>';
            echo '<a class="admin-nav-ext" href="' . htmlspecialchars($linkit['tv']) . '" target="_blank" rel="noopener">Tulostaulu</a>';
            if (function_exists('kisaOnAmpumapaikat') && kisaOnAmpumapaikat($kilpailu)) {
                echo '<a href="' . htmlspecialchars($linkit['ampuma']) . '">Ampumapaikka</a>';
            }
        }
        if ($nEmit && $vkLahto) {
            $onViesti = function_exists('onkoViestiKilpailu') && onkoViestiKilpailu($kilpailu);
            if ($onViesti) {
                echo '<a href="' . htmlspecialchars($linkit['viesti_lukija']) . '">Viesti-lukija</a>';
            } else {
                echo '<a href="' . htmlspecialchars($linkit['emit_lukija']) . '">Emit-lukija</a>';
            }
        }
        if ($nEmit && $vkKaikki) {
            echo '<a href="' . htmlspecialchars($linkit['leimantarkastus'] ?? ($linkit['emit_lukija'] . '&moodi=maali')) . '">Leimantarkastus</a>';
            echo '<a href="' . htmlspecialchars($linkit['mobiili_kilpailija'] ?? ('/public/mobiili_kilpailija.php?kilpailu_id=' . (int) $kilpailu['id'])) . '">Muokkaa kilpailijaa</a>';
        }
        if ($vkKaikki) {
            echo '<div class="section-label section-label--sub">Tulosten vienti</div>';
            echo '<a href="/admin/tulokset_vienti.php"' . ($active === 'vienti' ? ' class="active"' : '') . '>Tulosten vienti</a>';
        }
        if ($vkKaikki || $vkIlmo) {
            echo '<div class="section-label section-label--sub">Valmistelu</div>';
        }
        if ($vkKaikki) {
            echo '<a href="/admin/sarjat.php"' . ($active === 'sarjat' ? ' class="active"' : '') . '>'
                . ($pirila ? 'Sarjat (Pirila)' : 'Sarjat') . '</a>';
            echo '<a href="/admin/lahdot.php"' . ($active === 'lahdot' ? ' class="active"' : '') . '>Lähdöt</a>';
            echo '<a href="/admin/kilpailijat.php"' . ($active === 'kilpailijat' ? ' class="active"' : '') . '>Kilpailijat</a>';
            if (!empty($kilpailu['paikanpaa_ilmo'])) {
                echo '<a href="/admin/kioski_qr.php"' . ($active === 'kioski_qr' ? ' class="active"' : '') . '>QR-koodi</a>';
            }
        }
        if ($vkIlmo) {
            if (!empty($kilpailu['paikanpaa_ilmo'])) {
                $kioskiHref = '';
                if ($pdo instanceof PDO) {
                    require_once __DIR__ . '/sarja_numerot.php';
                    if (function_exists('paikanpaaIlmoHref')) {
                        $kioskiHref = paikanpaaIlmoHref($pdo, $kilpailu);
                    }
                }
                if ($kioskiHref !== '') {
                    echo '<a href="' . htmlspecialchars($kioskiHref) . '">Ilmo paikan päällä</a>';
                }
            }
            if ($naytaPaikanpaa) {
                echo '<a href="/admin/paikanpaa.php"' . ($active === 'paikanpaa' ? ' class="active"' : '') . '>Numeroiden jako</a>';
            }
        }
        if ($vkKaikki && ($pirila || kisaNaytaValikko($kilpailu, 'ratatiedot') || $active === 'radat')) {
            echo '<a href="/admin/radat.php"' . ($active === 'radat' ? ' class="active"' : '') . '>'
                . ($pirila ? 'Radat (Pirila)' : 'Radat') . '</a>';
        }
        echo '</nav>';
        echo '</div>';

        if ($vkKaikki) {
        echo '<div class="admin-section">';
        echo '<div class="section-label">Tulokset</div>';
        echo '<nav class="admin-nav public-nav">';
        echo '<a class="admin-nav-ext" href="' . htmlspecialchars($linkit['lahtolista']) . '" target="_blank" rel="noopener">Lähtölista</a>';
        echo '<a class="admin-nav-ext" href="' . htmlspecialchars($linkit['seuranta']) . '" target="_blank" rel="noopener">'
            . htmlspecialchars(function_exists('seurantaOtsikko') ? seurantaOtsikko($kilpailu) : 'Tulokset')
            . '</a>';
        echo '<a class="admin-nav-ext" href="' . htmlspecialchars($linkit['kilpailu']) . '" target="_blank" rel="noopener">Aloitussivu</a>';
        if (!$pirila) {
            $naytaIlmo = function_exists('naytaIlmoittautuminenValikossa')
                ? naytaIlmoittautuminenValikossa($kilpailu)
                : (!empty($kilpailu['ilmoittautuminen_auki']));
            if ($naytaIlmo) {
                echo '<a class="admin-nav-ext" href="' . htmlspecialchars($linkit['ilmoittautuminen']) . '" target="_blank" rel="noopener">Ilmoittautuminen</a>';
            }
            echo '<a class="admin-nav-ext" href="' . htmlspecialchars($linkit['ilmoittautuneet']) . '" target="_blank" rel="noopener">Ilmoittautuneet</a>';
        }
        echo '</nav>';
        echo '</div>';
        }
        }
    } else {
        echo '<div class="admin-section">';
        echo '<div class="section-label">' . ($vainIltarasti ? 'Tämä tapahtuma' : 'Tämä kilpailu') . '</div>';
        echo '<p class="muted admin-sidebar-hint">Ei valittua ' . ($vainIltarasti ? 'tapahtumaa' : 'kilpailua') . '.</p>';
        echo '<nav class="admin-nav"><a href="'
            . ($vainIltarasti ? '/public/iltarastit.php' : '/admin/index.php')
            . '"' . ($active === 'index' || $active === 'iltarastit' ? ' class="active"' : '') . '>'
            . ($vainIltarasti ? 'Valitse tapahtuma' : 'Valitse kilpailu') . '</a></nav>';
        echo '</div>';
    }

    if ($kuori) {
        echo '<div class="admin-section iltarasti-ulos-osio">';
        echo '<nav class="admin-nav">';
        echo '<a class="iltarasti-julkinen-etusivu" href="/public/index.php">Siirry julkiselle etusivulle</a>';
        echo '<a class="iltarasti-kirjaudu-ulos" href="/auth/logout.php">Kirjaudu ulos</a>';
        echo '</nav></div>';
        echo '</div></aside>';
        echo '<div class="admin-sidebar-backdrop" id="admin-sidebar-backdrop" hidden></div>';
        return;
    }
    if (function_exists('jahPalveluHamburgerOsio')) {
        jahPalveluHamburgerOsio($pdo instanceof PDO ? $pdo : null, (string) $active);
    }
    echo '<div class="admin-section">';
    echo '<div class="section-label">Asetukset</div>';
    echo '<nav class="admin-nav">';
    if (function_exists('iltarastiVoiPalvelun') ? iltarastiVoiPalvelun() : $vainIltarasti) {
        echo '<a href="/public/omat_tapahtumat.php"' . ($active === 'iltarastit' || $active === 'omat_tapahtumat' ? ' class="active"' : '') . '>Omat tapahtumat</a>';
    }
    if ($kilpailu && $pirila && !$vainIltarasti) {
        echo '<a href="/admin/pirila.php"' . ($active === 'pirila' ? ' class="active"' : '') . '>Pirilä-asetukset</a>';
    }
    if (!$vainIltarasti) {
        echo '<a href="/admin/pirila_api_avain.php"' . ($active === 'api_avain' ? ' class="active"' : '') . '>Pirilä api-avain</a>';
        if (!function_exists('tiliSessioSeuraPalvelu') || tiliSessioSeuraPalvelu('gps')) {
            echo '<a href="/gps/hallinta.php"' . ($active === 'gps' ? ' class="active"' : '') . '>GPS-seuranta</a>';
        }
        echo '<a href="/admin/irma.php"' . ($active === 'irma' ? ' class="active"' : '') . '>IRMA-kalenteri</a>';
    }
    if (onPaakayttaja()) {
        echo '<a href="/admin/users.php"' . ($active === 'users' ? ' class="active"' : '') . '>Käyttäjät</a>';
    }
    echo '<a href="/admin/oma_seura.php"' . ($active === 'oma_seura' ? ' class="active"' : '') . '>Oma seura</a>';
    if (onAdmin()) {
        echo '<a href="/admin/seurat.php"' . ($active === 'seurat' ? ' class="active"' : '') . '>Seurojen hallinta</a>';
    }
    echo '<a href="/admin/salasana.php"' . ($active === 'salasana' ? ' class="active"' : '') . '>Salasana</a>';
    echo '</nav>';
    echo '</div>';

    echo '<div class="admin-section">';
    echo '<div class="section-label">Sivusto</div>';
    echo '<nav class="admin-nav">';
    if (!$vainIltarasti) {
        echo '<a href="/admin/kilpailut.php"' . ($active === 'kilpailut' ? ' class="active"' : '') . '>Kilpailulista</a>';
    }
    echo '<a class="admin-nav-ext" href="/public/ohjeet.php" target="_blank" rel="noopener">Ohjeet</a>';
    echo '<a href="/public/index.php">Julkinen etusivu</a>';
    echo '<a href="/auth/logout.php">Ulos</a>';
    echo '</nav>';
    echo '</div>';

    echo '</div></aside>';
    echo '<div class="admin-sidebar-backdrop" id="admin-sidebar-backdrop" hidden></div>';
}

?>
