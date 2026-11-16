<?php

require_once __DIR__ . '/../includes/tili.php';
if (is_readable(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
}

function fail($msg)
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function ok($msg)
{
    echo "ok: $msg\n";
}

$vanha = $_SESSION ?? [];
$_SESSION = ['role' => 'iltarasti', 'user_id' => 10];

$seuraOm = ['id' => 3, 'jasen_rooli' => 'omistaja'];
$seuraAd = ['id' => 3, 'jasen_rooli' => 'admin'];
$seuraJa = ['id' => 3, 'jasen_rooli' => 'jasen'];
$omistaja = ['id' => 1, 'user_id' => 8, 'rooli' => 'omistaja'];
$admin = ['id' => 2, 'user_id' => 11, 'rooli' => 'admin'];
$jasen = ['id' => 3, 'user_id' => 9, 'rooli' => 'jasen'];

tiliVoiMuokataSeuraJasenta($seuraOm, $jasen, 8) || fail('omistaja muokkaa jasenta');
tiliVoiMuokataSeuraJasenta($seuraOm, $admin, 8) || fail('omistaja muokkaa adminia');
tiliVoiMuokataSeuraJasenta($seuraAd, $jasen, 11) || fail('admin muokkaa jasenta');
tiliVoiMuokataSeuraJasenta($seuraAd, $omistaja, 11) && fail('admin ei muokkaa omistajaa');
tiliVoiMuokataSeuraJasenta($seuraAd, $admin, 12) && fail('admin ei muokkaa toista adminia');
tiliVoiMuokataSeuraJasenta($seuraJa, $jasen, 9) && fail('jasen ei muokkaa');
tiliOnSeuranOmistaja($seuraOm) || fail('on omistaja');
tiliOnSeuranOmistaja($seuraAd) && fail('admin ei ole omistaja ilman sivuston paa');
tiliVoiLuodaSeuran($seuraOm) || fail('omistaja voi lisata seuran');
tiliVoiLuodaSeuran($seuraJa) && fail('jasen ei lisaa seuraa');
tiliVoiLuodaSeuran(null) || fail('ilman seuraa voi luoda');
ok('oikeudet');

$_SESSION['role'] = 'admin';
tiliOnSeuranOmistaja($seuraAd) || fail('sivuston paa voi lopettaa');
tiliVoiLuodaSeuran($seuraJa) || fail('sivuston paa voi lisata seuran');
tiliVoiMuokataSeuraJasenta($seuraJa, $omistaja, 1) || fail('sivuston paa muokkaa omistajaa');
ok('sivuston paa');

$_SESSION = [
    'role' => 'ajanottaja',
    'user_id' => 99,
    'seura_id' => 3,
    'seura_rooli' => 'jasen',
    'seura_kisa_valikot' => 'ajanotto',
];
tiliNaytaKisaValikko('ajanotto') || fail('jasen ajanotto nakyy');
tiliNaytaKisaValikko('lahtopaikka') && fail('jasen lahtopaikka piilossa');
tiliNaytaKisaValikko('ilmoittautuminen') && fail('jasen ilmo piilossa');
tiliNaytaKisaValikko('kaikki') && fail('jasen ei kaikkia');
$_SESSION['seura_kisa_valikot'] = 'ajanotto,lahtopaikka';
tiliNaytaKisaValikko('lahtopaikka') || fail('useita valikoita');
$_SESSION['seura_kisa_valikot'] = 'kaikki';
tiliNaytaKisaValikko('ajanotto') || fail('kaikki avaa ajanoton');
tiliNaytaKisaValikko('ilmoittautuminen') || fail('kaikki avaa ilmon');
$_SESSION['seura_kisa_valikot'] = '';
tiliNaytaKisaValikko('ajanotto') && fail('tyhja ei avaa');
$_SESSION['seura_rooli'] = 'admin';
tiliNaytaKisaValikko('lahtopaikka') || fail('seuran admin nayttaa kaiken');
$_SESSION['role'] = 'admin';
$_SESSION['seura_rooli'] = 'jasen';
$_SESSION['seura_kisa_valikot'] = '';
tiliNaytaKisaValikko('ajanotto') || fail('sivuston admin nayttaa kaiken');
$_SESSION = [
    'role' => 'paakayttaja',
    'user_id' => 12,
    'seura_id' => 6,
    'seura_rooli' => 'omistaja',
    'seura_palvelut' => 'iltarastit',
    'seura_palvelu_kilpailut' => 1,
    'seura_palvelu_gps' => 0,
    'seura_palvelu_iltarastit' => 1,
];
tiliVoiPalvelun('tulokset') || fail('omistaja tulokset vaikka jasenys iltarastit');
tiliVoiPalvelun('gps') && fail('omistaja ei gps jos seura ei gps');
$_SESSION['seura_rooli'] = 'jasen';
$_SESSION['role'] = 'ajanottaja';
tiliVoiPalvelun('tulokset') && fail('jasen iltarastit ei tuloksia');
tiliKisaValikotMerkkijonoksi(['ajanotto', 'kaikki', 'lahtopaikka']) === 'kaikki' || fail('kaikki nielaisee muut');
tiliSeuraNimiTasmaa('Jukolan Seura', 'jukolan  seura') || fail('nimi tasmaa');
tiliSeuraNimiTasmaa('Jukolan Seura', 'toinen') && fail('vaara nimi');
ok('kisa valikot');

$_SESSION = $vanha;

$oma = file_get_contents(__DIR__ . '/../admin/oma_seura.php');
str_contains($oma, 'vaihda_seura') || fail('ui vaihda');
str_contains($oma, 'Lisää seura') || fail('ui lisaa');
str_contains($oma, 'muokkaa_jasen') || fail('ui muokkaa');
str_contains($oma, 'lopeta_seura') || fail('ui lopeta');
str_contains($oma, 'Poista seurasta') || fail('ui poista seurasta');
str_contains($oma, 'poista_maksu') && fail('oma_seura ei maksuja');
str_contains($oma, 'id="seura-maksut"') && fail('oma_seura ei maksukehysta');
str_contains($oma, 'Ei maksuja.') && fail('oma_seura ei tyhjaa maksulistaa');
$seurat = file_get_contents(__DIR__ . '/../admin/seurat.php');
str_contains($seurat, 'poista_seura') || fail('ui poista seura');
str_contains($seurat, 'poista_maksu') && fail('seurat ei nayta kilpailijoiden maksuja');
str_contains($seurat, 'poista_seuran_maksut') && fail('seurat ei poista seuran maksuja');
str_contains($seurat, 'Kilpailijoiden maksut') && fail('seurat ei nayta kilpailijoiden maksut -osiota');
str_contains($seurat, 'name="ei_laskutusta"') || fail('seurat ei laskutusta valinta');
str_contains($seurat, 'Ei laskutusta') || fail('seurat ei laskutusta teksti');
str_contains($seurat, 'tiliTallennaSeuraEiLaskutusta') || fail('seurat tallenna ei laskutusta');
strpos($seurat, 'name="ei_laskutusta"') > strpos($seurat, 'name="rastilippu_liitanta"') || fail('ei laskutusta rastilipun jalkeen');
strpos($seurat, 'name="viesti_kilpailu"') > strpos($seurat, 'name="palvelu_iltarastit"') || fail('viesti iltarastien jalkeen');
str_contains($seurat, 'tiliTallennaSeuraViesti') || fail('seurat tallenna viesti');
str_contains($oma, 'name="ei_laskutusta"') && fail('oma_seura ei admin-laskutusvalintaa');
str_contains($oma, 'tiliKisaValikotKenttaHtml') || fail('oma_seura kisa valikot kentta');
str_contains($oma, 'tiliIltarastiOikeusKenttaHtml') || fail('oma_seura iltarasti ruksi');
str_contains($oma, 'select name="palvelut"') && fail('oma_seura ei palvelut alasvetoa');
str_contains($oma, 'jasen-taulu') || fail('oma_seura jasen asettelu');
str_contains($oma, 'jasen-poista') || fail('oma_seura poista punainen');
str_contains($oma, 'jasen-taulu-toiminnot') || fail('oma_seura napit samassa sarakkeessa');
str_contains($oma, '$seura && $voiHallita') || fail('jasenlista vain adminille');
str_contains($oma, 'if ($voiHallita):') || fail('jasen-ui vain adminille');
str_contains($oma, 'if ($illatPaalla && $voiHallita):') || fail('oma_seura rastilippu vain paa');
preg_match('/if \(\$illatPaalla\):[\s\S]{0,200}iltarastit\.php/', $oma) && fail('oma_seura jasen ei rastilippu-avainta');
$tiliUi = file_get_contents(__DIR__ . '/../includes/tili.php');
str_contains($tiliUi, 'name="kisa_valikot[]"') || fail('kisa valikot checkbox');
str_contains($tiliUi, 'name="iltarasti_oikeus"') || fail('iltarasti ruksi tili');
str_contains($tiliUi, 'type="radio"') && str_contains($tiliUi, 'kisa_valikot[]') && fail('kisa valikot ei radio');
str_contains($tiliUi, "value=\"' . htmlspecialchars(\$avain") || str_contains($tiliUi, "value=\"kaikki\"") || fail('valikko avaimet');
str_contains($tiliUi, "'kaikki' => 'Kaikki'") || fail('valikko kaikki');
str_contains($tiliUi, "'ajanotto' => 'Ajanotto'") || fail('valikko ajanotto');
str_contains($tiliUi, "'lahtopaikka' => 'Lähtöpaikka'") || fail('valikko lahtopaikka');
str_contains($tiliUi, "'ilmoittautuminen' => 'Ilmoittautuminen'") || fail('valikko ilmoittautuminen');
str_contains($oma, 'name="lopeta_seura_nimi"') || fail('lopeta kirjoita nimi');
str_contains($oma, 'Haluan lopettaa seuran') || fail('lopeta vahvistusnappi');
str_contains($oma, "onsubmit=\"return confirm('Lopetetaanko") && fail('lopeta ei pelkkaa confirmia');
tiliSeuraEiLaskutusta(['ei_laskutusta' => 1]) || fail('ei laskutusta paalla');
tiliSeuraEiLaskutusta(['ei_laskutusta' => 0]) && fail('ei laskutusta kiinni');
tiliSeuraEiLaskutusta([]) && fail('ei laskutusta puuttuu');
$users = file_get_contents(__DIR__ . '/../admin/users.php');
str_contains($users, 'tiliPoistaKayttaja') || fail('ui users poisto');
ok('ui');

$db = __DIR__ . '/../db.php';
if (!is_readable($db)) {
    echo "all tests passed\n";
    exit(0);
}

require_once $db;
if (!isset($pdo) || !$pdo instanceof PDO) {
    echo "all tests passed\n";
    exit(0);
}

tiliVarmistaTaulut($pdo);
$tunnus = 'jah_test_sh_' . bin2hex(random_bytes(3));
$email1 = $tunnus . '@example.test';
$email2 = $tunnus . '.b@example.test';
$pass = 'Abcdefg1!';
$seuraA = 0;
$seuraB = 0;
$uidA = 0;
$uidB = 0;
$maksuKisa = 0;
$laskuKisa = 0;

try {
    if (function_exists('kayttajaRoolitVarmista')) {
        kayttajaRoolitVarmista($pdo);
    }
    $pdo->prepare('INSERT INTO users (username, password, role, email) VALUES (?, ?, ?, ?)')
        ->execute([$tunnus, password_hash($pass, PASSWORD_DEFAULT), 'iltarasti', $email1]);
    $uidA = (int) $pdo->lastInsertId();
    $luo = tiliLuoSeuraKayttajalle($pdo, $uidA, 'Testiseura ' . $tunnus, 'ilmainen');
    $seuraA = (int) $luo['seura_id'];
    $seuraA > 0 || fail('luo seura A');

    $pdo->prepare('INSERT INTO users (username, password, role, email, paakayttaja_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$tunnus . 'b', password_hash($pass, PASSWORD_DEFAULT), 'iltarasti', $email2, $uidA]);
    $uidB = (int) $pdo->lastInsertId();
    tiliAsetaKayttajanSeura($pdo, $uidB, $seuraA, 'jasen', 'iltarastit');

    $seuraRivi = tiliHaeSeuraKayttajalle($pdo, $uidA);
    $seuraRivi && (int) $seuraRivi['id'] === $seuraA || fail('aktiivinen A');
    $st = $pdo->prepare("SELECT * FROM seura_jasenet WHERE seura_id = ? AND user_id = ? AND tila = 'aktiivinen'");
    $st->execute([$seuraA, $uidB]);
    $jasenB = $st->fetch(PDO::FETCH_ASSOC);
    $jasenB || fail('jasen B');

    tiliPaivitaSeuraJasen(
        $pdo,
        $seuraRivi,
        $jasenB,
        $uidA,
        'uusi.' . $email2,
        'admin',
        'iltarastit'
    );
    $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $st->execute([$uidB]);
    $st->fetchColumn() === 'uusi.' . $email2 || fail('email paivittyi');
    $st = $pdo->prepare('SELECT rooli FROM seura_jasenet WHERE id = ?');
    $st->execute([(int) $jasenB['id']]);
    $st->fetchColumn() === 'admin' || fail('rooli paakayttajaksi');
    tiliPaivitaSeuraJasen(
        $pdo,
        $seuraRivi,
        $jasenB,
        $uidA,
        'uusi.' . $email2,
        'admin',
        'iltarastit',
        'ajanotto,lahtopaikka'
    );
    $st = $pdo->prepare('SELECT kisa_valikot FROM seura_jasenet WHERE id = ?');
    $st->execute([(int) $jasenB['id']]);
    $st->fetchColumn() === 'ajanotto,lahtopaikka' || fail('kisa valikot tallessa');
    ok('muokkaa jasen');

    $luoB = tiliLuoSeuraKayttajalle($pdo, $uidA, 'Toinen ' . $tunnus, 'ilmainen');
    $seuraB = (int) $luoB['seura_id'];
    $seuraB > 0 || fail('luo seura B');
    tiliVaihdaAktiivinenSeura($pdo, $uidA, $seuraA);
    $nyt = tiliHaeSeuraKayttajalle($pdo, $uidA);
    $nyt && (int) $nyt['id'] === $seuraA || fail('vaihda takaisin A');
    ok('vaihda seura');

    $st = $pdo->prepare("SELECT * FROM seura_jasenet WHERE seura_id = ? AND user_id = ?");
    $st->execute([$seuraA, $uidB]);
    $jasenB = $st->fetch(PDO::FETCH_ASSOC);
    tiliPoistaJasenSeurasta($pdo, $seuraRivi, $jasenB, $uidA);
    $st = $pdo->prepare("SELECT tila FROM seura_jasenet WHERE id = ?");
    $st->execute([(int) $jasenB['id']]);
    $st->fetchColumn() === 'poistettu' || fail('poistettu seurasta');
    ok('poista seurasta');

    $seuraBNimi = 'Toinen ' . $tunnus;
    try {
        tiliLopetaSeura($pdo, $seuraB, $uidA);
        fail('lopeta ilman vahvistusta');
    } catch (RuntimeException $e) {
        str_contains($e->getMessage(), 'Haluan lopettaa') || fail('lopeta vaatii napin');
    }
    try {
        tiliLopetaSeura($pdo, $seuraB, $uidA, 'vaara nimi', true);
        fail('lopeta vaaralla nimella');
    } catch (RuntimeException $e) {
        str_contains($e->getMessage(), 'seuran nimi') || fail('lopeta vaatii nimen');
    }
    tiliLopetaSeura($pdo, $seuraB, $uidA, $seuraBNimi, true);
    $st = $pdo->prepare('SELECT tila FROM seurat WHERE id = ?');
    $st->execute([$seuraB]);
    $st->fetchColumn() === 'suljettu' || fail('seura B suljettu');
    ok('lopeta seura');

    require_once __DIR__ . '/../includes/maksu.php';
    seuraHaeMaksut($pdo, $seuraA) === [] || fail('tyhja seura ilman maksuja');
    $pdo->prepare("INSERT INTO kilpailut (nimi, paiva, tila, kisa_tyyppi, seura_id, testikisa)
        VALUES (?, CURDATE(), 'kaynnissa', 'normaali', ?, 1)")
        ->execute(['Maksutesti ' . $tunnus, $seuraA]);
    $maksuKisa = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO sarjat (kilpailu_id, nimi, jarjestys) VALUES (?, 'H21', 1)")
        ->execute([$maksuKisa]);
    $maksuSarja = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO kilpailijat (kilpailu_id, sarja_id, nimi, etunimi, sukunimi, status, maksu_tila, maksu_eur, maksu_viite)
        VALUES (?, ?, 'Maksaja Testi', 'Maksaja', 'Testi', 'OK', 'odottaa', 12.50, '12345')")
        ->execute([$maksuKisa, $maksuSarja]);
    $maksuKilp = (int) $pdo->lastInsertId();
    $lista = seuraHaeMaksut($pdo, $seuraA);
    count($lista) === 1 || fail('yksi maksu listassa');
    seuraPoistaMaksu($pdo, $seuraA, $maksuKilp) || fail('poista maksu');
    seuraHaeMaksut($pdo, $seuraA) === [] || fail('lista tyhja poiston jalkeen');
    $st = $pdo->prepare('SELECT maksu_tila, maksu_eur, maksu_viite, id FROM kilpailijat WHERE id = ?');
    $st->execute([$maksuKilp]);
    $jaljella = $st->fetch(PDO::FETCH_ASSOC);
    $jaljella && (int) $jaljella['id'] === $maksuKilp || fail('kilpailija jai');
    ($jaljella['maksu_tila'] ?? '') === 'ei_vaadita' || fail('maksu nollattu');
    ($jaljella['maksu_eur'] === null || $jaljella['maksu_eur'] === '') || fail('eur nollattu');
    ($jaljella['maksu_viite'] === null || $jaljella['maksu_viite'] === '') || fail('viite nollattu');
    seuraPoistaMaksu($pdo, $seuraA, $maksuKilp) && fail('toinen poisto ei onnistu');
    $pdo->prepare("UPDATE kilpailijat SET maksu_tila = 'odottaa', maksu_eur = 5 WHERE id = ?")
        ->execute([$maksuKilp]);
    seuraPoistaKaikkiMaksut($pdo, $seuraA) === 1 || fail('poista kaikki');
    seuraHaeMaksut($pdo, $seuraA) === [] || fail('kaikki poistettu');
    $pdo->prepare('DELETE FROM kilpailijat WHERE kilpailu_id = ?')->execute([$maksuKisa]);
    $pdo->prepare('DELETE FROM sarjat WHERE kilpailu_id = ?')->execute([$maksuKisa]);
    $pdo->prepare('DELETE FROM kilpailut WHERE id = ?')->execute([$maksuKisa]);
    $maksuKisa = 0;
    ok('seuran maksut');

    $pdo->prepare("UPDATE seurat SET rastilippu_liitanta = 1, laskutus_email = ? WHERE id = ?")
        ->execute(['lasku.' . $email1, $seuraA]);
    $pdo->prepare("INSERT INTO kilpailut (nimi, paiva, tila, kisa_tyyppi, seura_id, testikisa)
        VALUES (?, CURDATE(), 'kaynnissa', 'normaali', ?, 1)")
        ->execute(['Iltarasti lasku ' . $tunnus, $seuraA]);
    $laskuKisa = (int) $pdo->lastInsertId();
    try {
        $pdo->prepare("UPDATE kilpailut SET luonne = 'iltarasti', rastilippu_event_id = 424242 WHERE id = ?")
            ->execute([$laskuKisa]);
    } catch (Throwable $e) {
        fail('iltarasti lasku kisa: ' . $e->getMessage());
    }
    $lasku = tiliRastilippuLaskutus($pdo, $seuraA, tiliLaskutusVuosi());
    (int) $lasku['yhdistettyja'] >= 1 || fail('lasku tapahtuma');
    abs((float) $lasku['summa_euro'] - 5.0) < 0.01 || fail('lasku 5e');
    empty($lasku['ei_laskutusta']) || fail('lasku ei viela ohitettu');
    $loytyi = false;
    foreach (tiliRastilippuLaskutusKaikki($pdo, tiliLaskutusVuosi()) as $rivi) {
        if ((int) $rivi['seura_id'] === $seuraA) {
            $loytyi = true;
            break;
        }
    }
    $loytyi || fail('laskurivi ennen ohitusta');
    tiliTallennaSeuraEiLaskutusta($pdo, $seuraA, true);
    $lasku = tiliRastilippuLaskutus($pdo, $seuraA, tiliLaskutusVuosi());
    !empty($lasku['ei_laskutusta']) || fail('ei laskutusta lippu');
    abs((float) $lasku['summa_euro']) < 0.01 || fail('summa nolla ei laskutusta');
    (int) $lasku['yhdistettyja'] >= 1 || fail('tapahtumat näkyvät silti');
    foreach (tiliRastilippuLaskutusKaikki($pdo, tiliLaskutusVuosi()) as $rivi) {
        if ((int) $rivi['seura_id'] === $seuraA) {
            fail('ei laskurivia ei-laskutettavalle');
        }
    }
    $pdo->prepare('DELETE FROM kilpailut WHERE id = ?')->execute([$laskuKisa]);
    $laskuKisa = 0;
    ok('ei laskutusta');

    $_SESSION = ['role' => 'admin', 'user_id' => $uidA];
    $tulos = tiliPoistaSeuraJaKayttajat($pdo, $seuraA, $uidA);
    $tulos['kayttajia'] >= 1 || fail('poisti seuran kayttajia');
    $st = $pdo->prepare('SELECT id FROM seurat WHERE id = ?');
    $st->execute([$seuraA]);
    $st->fetchColumn() && fail('seura A poistettu');
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$uidB]);
    $st->fetchColumn() && fail('jasen B poistettu kayttajana');
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$uidA]);
    $st->fetchColumn() || fail('omistaja A sai jaada (actor)');
    ok('poista seura ja kayttajat');
    $seuraA = 0;
} catch (Throwable $e) {
    fail('db: ' . $e->getMessage());
} finally {
    $_SESSION = $vanha;
    try {
        if ($maksuKisa > 0) {
            $pdo->prepare('DELETE FROM kilpailijat WHERE kilpailu_id = ?')->execute([$maksuKisa]);
            $pdo->prepare('DELETE FROM sarjat WHERE kilpailu_id = ?')->execute([$maksuKisa]);
            $pdo->prepare('DELETE FROM kilpailut WHERE id = ?')->execute([$maksuKisa]);
        }
        if ($laskuKisa > 0) {
            $pdo->prepare('DELETE FROM kilpailut WHERE id = ?')->execute([$laskuKisa]);
        }
        if ($seuraA > 0) {
            $pdo->prepare('DELETE FROM seura_jasenet WHERE seura_id = ?')->execute([$seuraA]);
            $pdo->prepare('DELETE FROM seurat WHERE id = ?')->execute([$seuraA]);
        }
        if ($seuraB > 0) {
            $pdo->prepare('DELETE FROM seura_jasenet WHERE seura_id = ?')->execute([$seuraB]);
            $pdo->prepare('DELETE FROM seurat WHERE id = ?')->execute([$seuraB]);
        }
        if ($uidB > 0) {
            $pdo->prepare('UPDATE users SET paakayttaja_id = NULL WHERE paakayttaja_id = ?')->execute([$uidB]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uidB]);
        }
        if ($uidA > 0) {
            $pdo->prepare('UPDATE users SET paakayttaja_id = NULL WHERE paakayttaja_id = ?')->execute([$uidA]);
            $pdo->prepare('DELETE FROM seura_jasenet WHERE user_id = ?')->execute([$uidA]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uidA]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "cleanup: " . $e->getMessage() . "\n");
    }
}

echo "all tests passed\n";
