<?php

/** Iltarastin osanottajalista. Odottaa: $pdo, $kilpailu, $kilpailu_id. */

require_once __DIR__ . '/iltarasti.php';

$viesti = trim((string) ($_GET['viesti'] ?? ''));
$virhe = '';
$muokattava_id = isset($_GET['muokkaa']) ? (int) $_GET['muokkaa'] : 0;
$valittuSarja = (int) ($_POST['sarja'] ?? $_GET['sarja'] ?? 0);

if (function_exists('iltarastiSiivoaHaamuSarjat')) {
    try {
        iltarastiSiivoaHaamuSarjat($pdo, $kilpailu_id);
    } catch (Throwable $e) {
    }
}

$st = $pdo->prepare('
    SELECT s.id, s.nimi
    FROM sarjat s
    WHERE s.kilpailu_id = ?
      AND (
        EXISTS (
            SELECT 1 FROM radat r
            WHERE r.kilpailu_id = s.kilpailu_id
              AND (r.tunnus = s.nimi OR (s.rata_nimi IS NOT NULL AND s.rata_nimi <> \'\' AND r.tunnus = s.rata_nimi))
        )
        OR EXISTS (SELECT 1 FROM kilpailijat k WHERE k.sarja_id = s.id)
      )
    ORDER BY s.jarjestys, s.nimi, s.id
');
$st->execute([$kilpailu_id]);
$radat = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && function_exists('iltarastiTarkistaMaastossaJoillaLeimat')) {
    try {
        iltarastiTarkistaMaastossaJoillaLeimat($pdo, $kilpailu);
    } catch (Throwable $e) {
    }
}

$ohjaaOsanottajiin = static function (string $viesti, int $sarjaId = 0): void {
    $q = ['viesti' => $viesti];
    if ($sarjaId > 0) {
        $q['sarja'] = $sarjaId;
    }
    header('Location: kilpailijat.php?' . http_build_query($q));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tarkistaCsrf((string) ($_POST['csrf'] ?? ''))) {
        die('Virheellinen pyyntö');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update' || $action === 'tarkista_uudelleen') {
            $id = (int) ($_POST['id'] ?? 0);
            $stH = $pdo->prepare('SELECT * FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
            $stH->execute([$id, $kilpailu_id]);
            $rivi = $stH->fetch(PDO::FETCH_ASSOC);
            if (!$rivi) {
                throw new RuntimeException('Henkilöä ei löydy.');
            }
            $sarjaId = (int) ($_POST['sarja_id'] ?? 0);
            if ($sarjaId < 1) {
                $sarjaId = (int) ($rivi['sarja_id'] ?? 0);
            }
            $vanhaSarja = (int) ($rivi['sarja_id'] ?? 0);
            if ($action === 'update') {
                $nimet = iltarastiNimetSyotteesta((string) ($_POST['etunimi'] ?? ''), (string) ($_POST['sukunimi'] ?? ''));
                $seura = trim((string) ($_POST['seura'] ?? ''));
                $emit = (int) ($_POST['emit_koodi'] ?? 0);
                if ($id < 1 || $sarjaId < 1) {
                    throw new RuntimeException('Henkilö tai rata puuttuu.');
                }
                if ($sarjaId !== $vanhaSarja) {
                    $vaihto = iltarastiVaihdaRata($pdo, $kilpailu, $rivi, $sarjaId);
                    if (empty($vaihto['ok'])) {
                        throw new RuntimeException((string) ($vaihto['message'] ?? 'Radan vaihto epäonnistui.'));
                    }
                    $rivi['sarja_id'] = $sarjaId;
                }
                $pdo->prepare('
                    UPDATE kilpailijat
                    SET nimi = ?, etunimi = ?, sukunimi = ?, seura = ?, emit_koodi = ?
                    WHERE id = ? AND kilpailu_id = ?
                ')->execute([
                    $nimet['nimi'],
                    $nimet['etunimi'] !== '' ? $nimet['etunimi'] : null,
                    $nimet['sukunimi'],
                    $seura !== '' ? $seura : null,
                    $emit > 0 ? $emit : null,
                    $id,
                    $kilpailu_id,
                ]);
                if ($emit > 0 && function_exists('emitHenkilotTallenna')) {
                    emitHenkilotTallenna(
                        $pdo,
                        $emit,
                        (string) $nimet['etunimi'],
                        (string) $nimet['sukunimi'],
                        $seura,
                        (int) ($kilpailu['seura_id'] ?? 0),
                        function_exists('kayttajaId') ? (int) (kayttajaId() ?? 0) : 0
                    );
                }
                $stH->execute([$id, $kilpailu_id]);
                $rivi = $stH->fetch(PDO::FETCH_ASSOC) ?: $rivi;
                $tulos = iltarastiTallennaOsanottajaTulos(
                    $pdo,
                    $kilpailu,
                    $rivi,
                    (string) ($_POST['tulos'] ?? ''),
                    (string) ($_POST['aika'] ?? '')
                );
                $msg = 'Henkilö tallennettu. ' . (string) ($tulos['message'] ?? '');
                if ($sarjaId !== $vanhaSarja) {
                    $t = iltarastiTarkistaKilpailijaUudelleen($pdo, $kilpailu, $rivi);
                    $msg .= ' Rata vaihdettu.';
                    if (!empty($t['message'])) {
                        $msg .= ' ' . $t['message'];
                    }
                }
                $ohjaaOsanottajiin($msg, $sarjaId);
            }
            $t = iltarastiTarkistaKilpailijaUudelleen($pdo, $kilpailu, $rivi);
            $msg = 'Tarkistus tehty.';
            if (!empty($t['message'])) {
                $msg .= ' ' . $t['message'];
            }
            $ohjaaOsanottajiin($msg, $sarjaId);
        }
        if ($action === 'vaihda_rata') {
            $id = (int) ($_POST['id'] ?? 0);
            $stH = $pdo->prepare('SELECT * FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
            $stH->execute([$id, $kilpailu_id]);
            $rivi = $stH->fetch(PDO::FETCH_ASSOC);
            if (!$rivi) {
                throw new RuntimeException('Henkilöä ei löydy.');
            }
            $sarjaId = (int) ($_POST['arvaus_sarja_id'] ?? $_POST['sarja_id'] ?? 0);
            $vaihto = iltarastiVaihdaRata($pdo, $kilpailu, $rivi, $sarjaId);
            if (empty($vaihto['ok'])) {
                throw new RuntimeException((string) ($vaihto['message'] ?? 'Radan vaihto epäonnistui.'));
            }
            $rivi['sarja_id'] = $sarjaId;
            $t = iltarastiTarkistaKilpailijaUudelleen($pdo, $kilpailu, $rivi);
            $msg = (string) ($vaihto['message'] ?? 'Rata vaihdettu.');
            if (!empty($t['message'])) {
                $msg .= ' ' . $t['message'];
            }
            $ohjaaOsanottajiin($msg, $sarjaId);
        }
        if ($action === 'pois') {
            $id = (int) ($_POST['id'] ?? 0);
            $stH = $pdo->prepare('SELECT * FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
            $stH->execute([$id, $kilpailu_id]);
            $rivi = $stH->fetch(PDO::FETCH_ASSOC);
            if (!$rivi) {
                throw new RuntimeException('Henkilöä ei löydy.');
            }
            $t = iltarastiKirjaaPois(
                $pdo,
                $kilpailu,
                (int) ($rivi['sarja_id'] ?? 0),
                (string) ($rivi['etunimi'] ?? ''),
                (string) ($rivi['sukunimi'] ?? ''),
                (string) ($rivi['seura'] ?? ''),
                (int) ($rivi['emit_koodi'] ?? 0),
                $id
            );
            if (empty($t['ok'])) {
                throw new RuntimeException((string) ($t['message'] ?? 'Merkintä epäonnistui.'));
            }
            $ohjaaOsanottajiin((string) $t['message'], (int) ($rivi['sarja_id'] ?? 0));
        }
        if ($action === 'paivita_ilmoittautuneet') {
            if (!function_exists('rastilippuPaivitaIlmoittautumiset') || !function_exists('rastilippuKilpailuYhdistetty') || !rastilippuKilpailuYhdistetty($kilpailu)) {
                throw new RuntimeException('Tapahtumaa ei ole yhdistetty Rastilippuun.');
            }
            $t = rastilippuPaivitaIlmoittautumiset($pdo, $kilpailu, true);
            $ohjaaOsanottajiin((string) ($t['viesti'] ?? 'Ilmoittautuneet päivitetty.'), $valittuSarja);
        }
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $stH = $pdo->prepare('SELECT sarja_id FROM kilpailijat WHERE id = ? AND kilpailu_id = ?');
            $stH->execute([$id, $kilpailu_id]);
            $sarjaId = (int) ($stH->fetchColumn() ?: 0);
            if (function_exists('rastilippuPoistaKilpailijanTulos')) {
                rastilippuPoistaKilpailijanTulos($pdo, $kilpailu, $id);
            }
            $pdo->prepare('DELETE FROM kilpailijat WHERE id = ? AND kilpailu_id = ?')->execute([$id, $kilpailu_id]);
            if (function_exists('rastilippuJonota')) {
                rastilippuJonota($pdo, $kilpailu);
            }
            $ohjaaOsanottajiin('Henkilö poistettu.', $sarjaId);
        }
    } catch (Throwable $e) {
        $virhe = $e->getMessage();
    }
}

$st = $pdo->prepare('
    SELECT k.*, s.nimi AS rata, a.aika_sec AS tulos_aika_sec
    FROM kilpailijat k
    JOIN sarjat s ON s.id = k.sarja_id
    LEFT JOIN ajat a ON a.kilpailija_id = k.id
    WHERE k.kilpailu_id = ?
    ORDER BY k.sukunimi, k.etunimi, k.nimi, k.id
');
$st->execute([$kilpailu_id]);
$henkilot = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ryhmat = iltarastiRyhmitteleSarjoittain($radat, $henkilot);

$rlIlmo = [];
$rlIlmoTeksti = '';
$rlYhdistetty = function_exists('rastilippuKilpailuYhdistetty') && rastilippuKilpailuYhdistetty($kilpailu);
if ($rlYhdistetty && function_exists('rastilippuPaivitaIlmoittautumiset')) {
    try {
        rastilippuPaivitaIlmoittautumiset($pdo, $kilpailu, false);
        $tuore = function_exists('haeKilpailu') ? haeKilpailu($pdo, $kilpailu_id) : null;
        if (is_array($tuore)) {
            $kilpailu = $tuore;
        }
    } catch (Throwable $e) {
    }
    $rlIlmoTeksti = trim((string) ($kilpailu['rastilippu_ilmo_viesti'] ?? ''));
    $kirjatut = [];
    foreach ($henkilot as $h) {
        $emit = (int) ($h['emit_koodi'] ?? 0);
        if ($emit > 0) {
            $kirjatut['e' . $emit] = true;
        }
        $avain = function_exists('emitHenkilotAvain')
            ? emitHenkilotAvain((string) ($h['etunimi'] ?? ''), (string) ($h['sukunimi'] ?? ''))
            : (strtolower((string) ($h['sukunimi'] ?? '')) . '|' . strtolower((string) ($h['etunimi'] ?? '')));
        if ($avain !== '|') {
            $kirjatut['n' . $avain] = true;
        }
    }
    foreach (rastilippuHaeIlmoittautumiset($pdo, $kilpailu_id) as $r) {
        $naytto = rastilippuIlmoittautuminenNayttoon($r);
        $emit = (int) $naytto['emit'];
        $avain = function_exists('emitHenkilotAvain')
            ? emitHenkilotAvain($naytto['etunimi'], $naytto['sukunimi'])
            : (strtolower($naytto['sukunimi']) . '|' . strtolower($naytto['etunimi']));
        $jo = ($emit > 0 && isset($kirjatut['e' . $emit])) || ($avain !== '|' && isset($kirjatut['n' . $avain]));
        $naytto['kirjattu'] = $jo;
        $rlIlmo[] = $naytto;
    }
}

$muokattava = null;
$muokattavaLeimat = [];
$muokattavaAikaSec = null;
$muokattavaLeimaHtml = '';
$muokattavaArvaus = null;
$muokattavaArvausKysymys = '';
if ($muokattava_id > 0) {
    foreach ($henkilot as $h) {
        if ((int) $h['id'] === $muokattava_id) {
            $muokattava = $h;
            $valittuSarja = (int) $h['sarja_id'];
            break;
        }
    }
    if ($muokattava) {
        if (!function_exists('emitLukijaHaeLeimatKannasta')) {
            require_once __DIR__ . '/emit_lukija.php';
        }
        $muokattavaLeimat = emitLukijaHaeLeimatKannasta($pdo, (int) $muokattava['id']);
        if (!empty($muokattava['tulos_aika_sec']) && (int) $muokattava['tulos_aika_sec'] > 0) {
            $muokattavaAikaSec = (int) $muokattava['tulos_aika_sec'];
        } elseif (!empty($muokattava['aika_sec']) && (int) $muokattava['aika_sec'] > 0) {
            $muokattavaAikaSec = (int) $muokattava['aika_sec'];
        }
        if ($muokattavaLeimat !== []) {
            if (!function_exists('leimaTarkistaRata')) {
                require_once __DIR__ . '/leimantarkastus.php';
            }
            $rataKoodit = [];
            if (function_exists('leimaHaeRataKilpailijalle')) {
                $rata = leimaHaeRataKilpailijalle($pdo, $kilpailu_id, $muokattava);
                $rataKoodit = is_array($rata['koodit'] ?? null) ? $rata['koodit'] : [];
            }
            if ($rataKoodit !== []) {
                $tark = leimaTarkistaRata(
                    $rataKoodit,
                    $muokattavaLeimat,
                    is_array($rata['sammunut'] ?? null) ? $rata['sammunut'] : []
                );
                $muokattavaLeimaHtml = leimaKorttiNayttoHtml($tark);
            }
            if (iltarastiTulosKirjain($muokattava) === 'E' && function_exists('iltarastiArvaaRata')) {
                $muokattavaArvaus = iltarastiArvaaRata(
                    $pdo,
                    $kilpailu_id,
                    (int) ($muokattava['sarja_id'] ?? 0),
                    $muokattavaLeimat
                );
                if ($muokattavaArvaus && function_exists('iltarastiArvausKysymys')) {
                    $muokattavaArvausKysymys = iltarastiArvausKysymys($muokattavaArvaus);
                }
            }
        }
    }
}

$sarjaIdt = array_map(static fn (array $r): int => (int) $r['id'], $ryhmat);
if ($valittuSarja < 1 || !in_array($valittuSarja, $sarjaIdt, true)) {
    $valittuSarja = $sarjaIdt[0] ?? 0;
}

$osanottajaNimi = static function (array $h): string {
    $nimi = trim((string) ($h['sukunimi'] ?? '') . ' ' . (string) ($h['etunimi'] ?? ''));
    if ($nimi === '') {
        $nimi = trim((string) ($h['nimi'] ?? ''));
    }

    return $nimi;
};

$osanottajaAika = static function (array $h): string {
    $sec = (int) ($h['tulos_aika_sec'] ?? $h['aika_sec'] ?? 0);
    if ($sec <= 0) {
        return '';
    }

    return function_exists('formatAikaSec') ? formatAikaSec($sec) : (string) $sec;
};

$sivuUrl = static function (array $extra = []) use ($valittuSarja): string {
    $q = [];
    $sarja = (int) ($extra['sarja'] ?? $valittuSarja);
    if ($sarja > 0) {
        $q['sarja'] = $sarja;
    }
    if (!empty($extra['muokkaa'])) {
        $q['muokkaa'] = (int) $extra['muokkaa'];
    }
    return 'kilpailijat.php' . ($q !== [] ? '?' . http_build_query($q) : '');
};

$opts = iltarastiKuoriOpts($pdo, $kilpailu, 'kilpailijat');
$opts['extraHead'] = '<style>
.osanottaja-valilehdet{display:flex;flex-wrap:wrap;gap:.35rem;margin:.2rem 0 .9rem}
.osanottaja-valilehti{min-height:2.5rem;padding:.35rem .8rem;font-size:.88rem;border-radius:.45rem;border:2px solid var(--border,#cfc6b6);background:var(--card);color:var(--text-muted,#6b5a3c);font-weight:650;cursor:pointer}
.osanottaja-valilehti.is-valittu{background:var(--primary);border-color:var(--primary);color:var(--on-primary);font-weight:800;box-shadow:inset 0 -3px 0 color-mix(in srgb,#000 28%,var(--primary))}
.osanottaja-sarja[hidden]{display:none}
.osanottaja-maastossa{margin:0 0 .55rem;font-size:1.08rem;font-weight:750}
.osanottaja-tyhja{margin:.4rem 0 0;color:var(--text-muted,#6b5a3c)}
.osanottaja-nimet{list-style:none;padding:0;margin:.35rem 0 0}
.osanottaja-nimet li{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem .7rem;padding:.55rem .1rem;border-bottom:1px solid var(--border,#d8d0c4)}
.osanottaja-nimi{flex:1 1 10rem;font-size:1.05rem;font-weight:700;line-height:1.25}
.osanottaja-tila{font-size:.85rem;font-weight:650;color:var(--text-muted,#6b5a3c)}
.osanottaja-aika{font-size:.95rem;font-weight:750;font-variant-numeric:tabular-nums}
.osanottaja-toimet{display:flex;flex-wrap:wrap;gap:.35rem;margin-left:auto}
.osanottaja-toimet form{margin:0}
.osanottaja-muokkaa{max-width:32rem;margin:0 0 1.1rem}
.osanottaja-tulos{margin:.15rem 0 .7rem;font-size:1.12rem;font-weight:800}
.osanottaja-leimat{margin:.2rem 0 .8rem}
.osanottaja-leimat summary{display:inline-flex;align-items:center;min-height:2.5rem;padding:.35rem .8rem;font-weight:700;cursor:pointer;border:2px solid var(--border,#cfc6b6);border-radius:.45rem;background:var(--card);list-style:none}
.osanottaja-leimat summary::-webkit-details-marker{display:none}
.osanottaja-leimat ol{list-style:none;padding:0;margin:.55rem 0 0}
.osanottaja-leimat li{display:flex;flex-wrap:wrap;gap:.4rem;padding:.35rem 0;border-bottom:1px solid var(--border,#d8d0c4)}
.osanottaja-leimat .emit-leima-nro{min-width:1.6em;font-variant-numeric:tabular-nums;font-weight:800}
.osanottaja-leimat .emit-leima-ok{color:inherit}
.osanottaja-leimat .emit-leima-virhe{color:#b42318}
.osanottaja-leimat .emit-leima-extra{color:#8a6d00}
.osanottaja-leimat .emit-leima-sammunut{color:#6b5a3c}
.osanottaja-leimat-tyhja{margin:.55rem 0 0;color:var(--text-muted,#6b5a3c)}
.osanottaja-arvaus{grid-column:1/-1;margin:.15rem 0 .7rem;padding:.55rem .7rem;border:2px solid #c49a22;border-radius:.45rem;background:#fff3cd;color:#1c1408}
.osanottaja-arvaus p{margin:0 0 .5rem;font-weight:750;line-height:1.35}
.osanottaja-arvaus button{min-height:2.6rem}
.osanottaja-ilmo{margin:0 0 1.2rem;padding:.7rem .2rem 1rem;border-bottom:1px solid var(--border,#d8d0c4)}
.osanottaja-ilmo h2{margin:0 0 .35rem;font-size:1.05rem}
.osanottaja-ilmo-paivita{margin:.35rem 0 .5rem}
.osanottaja-maksu-ei{color:#b42318}
</style>';
pageStart('Osanottajat', $opts);
?>
<h1>Osanottajat<?= $henkilot !== [] ? ' (' . count($henkilot) . ')' : '' ?></h1>
<p class="muted">Uudet henkilöt kirjataan <a href="<?= htmlspecialchars(iltarastiTulospalveluUrl((int) $kilpailu['id'])) ?>">tulospalvelussa</a>.</p>
<?php if ($viesti !== ''): ?><p class="notice"><?= htmlspecialchars($viesti) ?></p><?php endif; ?>
<?php if ($virhe !== ''): ?><p class="error"><?= htmlspecialchars($virhe) ?></p><?php endif; ?>

<?php if ($rlYhdistetty): ?>
    <section class="osanottaja-ilmo">
        <h2>Rastilipun ilmoittautuneet<?= $rlIlmo !== [] ? ' (' . count($rlIlmo) . ')' : '' ?></h2>
        <?php if ($rlIlmoTeksti !== ''): ?>
            <p class="muted"><?= htmlspecialchars($rlIlmoTeksti) ?></p>
        <?php endif; ?>
        <form method="post" class="osanottaja-ilmo-paivita">
            <?= csrfKentta() ?>
            <input type="hidden" name="action" value="paivita_ilmoittautuneet">
            <input type="hidden" name="sarja" value="<?= (int) $valittuSarja ?>">
            <button type="submit" class="btn-secondary">Päivitä Rastilipusta</button>
        </form>
        <?php if ($rlIlmo === []): ?>
            <p class="osanottaja-tyhja">Ei ilmoittautuneita Rastilipussa. He näkyvät tässä, kun joku ilmoittautuu tapahtumaan.</p>
        <?php else: ?>
            <ul class="osanottaja-nimet">
                <?php foreach ($rlIlmo as $i):
                    $nimi = trim($i['sukunimi'] . ' ' . $i['etunimi']);
                    if ($nimi === '') {
                        $nimi = $i['nimi'];
                    }
                    $maksu = function_exists('rastilippuIlmoMaksuTeksti') ? rastilippuIlmoMaksuTeksti($i) : (string) ($i['maksu'] ?? '');
                    ?>
                    <li>
                        <span class="osanottaja-nimi"><?= htmlspecialchars($nimi) ?></span>
                        <?php if (!empty($i['seura'])): ?>
                            <span class="osanottaja-tila"><?= htmlspecialchars((string) $i['seura']) ?></span>
                        <?php endif; ?>
                        <?php if ((int) $i['emit'] > 0): ?>
                            <span class="osanottaja-tila"><?= (int) $i['emit'] ?></span>
                        <?php endif; ?>
                        <?php if ($maksu !== ''): ?>
                            <span class="osanottaja-tila<?= ($i['maksu_ok'] === false) ? ' osanottaja-maksu-ei' : '' ?>"><?= htmlspecialchars($maksu) ?></span>
                        <?php endif; ?>
                        <span class="osanottaja-tila"><?= !empty($i['kirjattu']) ? 'Kirjattu' : 'Ei vielä kirjattu' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($muokattava): ?>
    <form method="post" class="osanottaja-muokkaa" id="osanottaja-lomake">
        <?= csrfKentta() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $muokattava['id'] ?>">
        <input type="hidden" name="sarja_id" value="<?= (int) $muokattava['sarja_id'] ?>">
        <h2>Muokkaa</h2>
        <div class="form-grid">
        <?php
        $tulosKirjain = iltarastiTulosKirjain($muokattava);
        $tulosAika = '';
        if ($muokattavaAikaSec !== null && $muokattavaAikaSec > 0) {
            $tulosAika = function_exists('formatAikaSec') ? formatAikaSec($muokattavaAikaSec) : (string) $muokattavaAikaSec;
        }
        ?>
        <label>Tarkistuksen tulos
            <select name="tulos" required>
                <?php foreach (iltarastiTulosKirjaimet() as $kirjain => $nimi): ?>
                    <option value="<?= htmlspecialchars($kirjain) ?>"<?= $tulosKirjain === $kirjain ? ' selected' : '' ?>>
                        <?= htmlspecialchars($nimi) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Aika <input name="aika" inputmode="numeric" placeholder="45:12" value="<?= htmlspecialchars($tulosAika) ?>"></label>
        <?php if ($muokattavaArvausKysymys !== '' && !empty($muokattavaArvaus['id'])): ?>
            <div class="osanottaja-arvaus">
                <p><?= htmlspecialchars($muokattavaArvausKysymys) ?></p>
                <input type="hidden" name="arvaus_sarja_id" value="<?= (int) $muokattavaArvaus['id'] ?>">
                <button type="submit" name="action" value="vaihda_rata">Kyllä, vaihda tälle radalle</button>
            </div>
        <?php endif; ?>
        <details class="osanottaja-leimat"<?= $muokattavaLeimat !== [] ? ' open' : '' ?>>
            <summary>Näytä leimat<?= $muokattavaLeimat !== [] ? ' (' . count($muokattavaLeimat) . ')' : '' ?></summary>
            <?php if ($muokattavaLeimat === []): ?>
                <p class="osanottaja-leimat-tyhja">Ei tallennettuja leimoja. Lue kortti leimantarkastuksessa, niin leimat tulevat tähän.</p>
            <?php elseif ($muokattavaLeimaHtml !== ''): ?>
                <div class="emit-leimat"><?= $muokattavaLeimaHtml ?></div>
            <?php else: ?>
                <ol>
                    <?php foreach ($muokattavaLeimat as $leima):
                        $koodi = (int) ($leima['koodi'] ?? 0);
                        $sec = (int) ($leima['aika_sec'] ?? $leima['kello_sec'] ?? 0);
                        $kello = sprintf('%02d:%02d:%02d', intdiv($sec, 3600) % 24, intdiv($sec % 3600, 60), $sec % 60);
                        ?>
                        <li>
                            <strong><?= $koodi === 248 ? 'Maali' : (int) $koodi ?></strong>
                            <span><?= htmlspecialchars($kello) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php
            $leimaLuku = ['kilpailu_id' => $kilpailu_id, 'id' => (int) $muokattava['id']];
            if ((int) ($muokattava['numero'] ?? 0) > 0) {
                $leimaLuku['numero'] = (int) $muokattava['numero'];
            }
            ?>
            <p><a href="emit_leimat.php?<?= htmlspecialchars(http_build_query($leimaLuku)) ?>">Muokkaa leimoja</a></p>
        </details>
        <label>Sukunimi <input name="sukunimi" required maxlength="100" value="<?= htmlspecialchars((string) ($muokattava['sukunimi'] ?? '')) ?>"></label>
        <label>Etunimi <input name="etunimi" maxlength="100" value="<?= htmlspecialchars((string) ($muokattava['etunimi'] ?? '')) ?>"></label>
        <label>Seura <input name="seura" maxlength="120" value="<?= htmlspecialchars((string) ($muokattava['seura'] ?? '')) ?>"></label>
        <label>Rata
            <select name="sarja_id" required>
                <?php foreach ($radat as $r): ?>
                    <option value="<?= (int) $r['id'] ?>"<?= (int) $muokattava['sarja_id'] === (int) $r['id'] ? ' selected' : '' ?>>
                        <?= htmlspecialchars((string) $r['nimi']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Kilpailukortti <input name="emit_koodi" inputmode="numeric" value="<?= htmlspecialchars((string) ($muokattava['emit_koodi'] ?? '')) ?>"></label>
        <p class="muted">Radan vaihto tai rastikoodien korjaus laskee leimat ja väliajat uudelleen. Kortin numero vaikuttaa seuraavaan lukukertaan; jo tallennetut leimat pysyvät tällä henkilöllä.</p>
        </div>
        <div class="form-actions">
            <button type="submit">Tallenna</button>
            <button type="submit" name="action" value="tarkista_uudelleen" class="btn-secondary">Tarkista leimat uudelleen</button>
            <?php if (iltarastiOnMaastossa($muokattava)): ?>
                <button type="submit" name="action" value="pois" class="btn-secondary">Pois maastosta</button>
            <?php endif; ?>
            <button type="submit" name="action" value="delete" class="btn-secondary" onclick="return confirm('Poistetaanko henkilö?');">Poista</button>
            <a class="btn-secondary" href="<?= htmlspecialchars($sivuUrl(['sarja' => (int) $muokattava['sarja_id']])) ?>">Peruuta</a>
        </div>
    </form>
<?php endif; ?>

<?php if ($ryhmat === []): ?>
    <p class="muted">Ei ratoja vielä. Lisää radat tapahtuman asetuksissa tai kirjaa tulospalvelun sisäänluvussa.</p>
<?php else: ?>
    <div class="osanottaja-valilehdet" role="tablist">
        <?php foreach ($ryhmat as $ryhma):
            $sid = (int) $ryhma['id'];
            $valittu = $sid === $valittuSarja;
            ?>
            <button type="button" class="osanottaja-valilehti<?= $valittu ? ' is-valittu' : '' ?>"
                role="tab" data-sarja="<?= $sid ?>"
                aria-selected="<?= $valittu ? 'true' : 'false' ?>"
                aria-controls="osanottaja-sarja-<?= $sid ?>"
                id="osanottaja-tab-<?= $sid ?>"><?= htmlspecialchars((string) $ryhma['nimi']) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($ryhmat as $ryhma):
        $sid = (int) $ryhma['id'];
        $valittu = $sid === $valittuSarja;
        ?>
        <section class="osanottaja-sarja" id="osanottaja-sarja-<?= $sid ?>"
            data-sarja="<?= $sid ?>" role="tabpanel"
            aria-labelledby="osanottaja-tab-<?= $sid ?>"<?= $valittu ? '' : ' hidden' ?>>
            <p class="osanottaja-maastossa">Maastossa <?= (int) $ryhma['maastossa'] ?></p>
            <?php
            $rivit = [];
            foreach ($ryhma['henkilot'] as $h) {
                $nimi = $osanottajaNimi($h);
                if ($nimi !== '') {
                    $rivit[] = [$h, $nimi];
                }
            }
            if ($rivit === []): ?>
                <p class="osanottaja-tyhja">Ei osanottajia tällä radalla.</p>
            <?php else: ?>
                <ul class="osanottaja-nimet">
                    <?php foreach ($rivit as [$h, $nimi]):
                        $ulos = iltarastiOnMaastossa($h);
                        $aikaNaytto = $osanottajaAika($h);
                        ?>
                        <li>
                            <span class="osanottaja-nimi"><?= htmlspecialchars($nimi) ?></span>
                            <span class="osanottaja-tila"><?= htmlspecialchars(iltarastiTulosTila($h + ['luonne' => 'iltarasti'])) ?></span>
                            <?php if ($aikaNaytto !== ''): ?>
                                <span class="osanottaja-aika"><?= htmlspecialchars($aikaNaytto) ?></span>
                            <?php endif; ?>
                            <span class="osanottaja-toimet">
                                <a class="btn-small btn-secondary" href="<?= htmlspecialchars($sivuUrl(['muokkaa' => (int) $h['id'], 'sarja' => $sid])) ?>">Muokkaa</a>
                                <?php if ($ulos): ?>
                                    <form method="post" onsubmit="return confirm('Merkitäänkö pois maastosta ilman aikaa?');">
                                        <?= csrfKentta() ?>
                                        <input type="hidden" name="action" value="pois">
                                        <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                        <input type="hidden" name="sarja" value="<?= $sid ?>">
                                        <button type="submit" class="btn-small btn-secondary">Pois maastosta</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <script>
    (function () {
        var tabs = document.querySelectorAll('.osanottaja-valilehti');
        var panels = document.querySelectorAll('.osanottaja-sarja');
        function nayta(id) {
            id = String(id);
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-sarja') === id;
                t.classList.toggle('is-valittu', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (p) {
                p.hidden = p.getAttribute('data-sarja') !== id;
            });
            try {
                var u = new URL(location.href);
                u.searchParams.set('sarja', id);
                u.searchParams.delete('muokkaa');
                history.replaceState(null, '', u);
            } catch (e) {}
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () { nayta(t.getAttribute('data-sarja')); });
        });
    })();
    </script>
<?php endif; ?>
<?php
pageEnd();
