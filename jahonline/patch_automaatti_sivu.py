#!/usr/bin/env python3
"""Palvelimen PHP: Automaatti omaksi kioskisivuksi."""
from pathlib import Path

DEST = Path("/var/www/jahonline")
VER = "20261112si"


def sub(txt, a, b, pakko=False):
    if a not in txt:
        if pakko:
            raise SystemExit("ei loydy: " + a[:80])
        return txt, 0
    return txt.replace(a, b), txt.count(a)


def write_if(p: Path, txt: str, orig: str):
    if txt != orig:
        p.write_text(txt, encoding="utf-8")
        print("paivitetty", p)
        return True
    return False


def patch_emit_lukija():
    p = DEST / "public/emit_lukija.php"
    txt = p.read_text(encoding="utf-8")
    orig = txt
    txt, _ = sub(txt, "$emitCssV = '20261111auto';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261111kioski';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261111yhdista';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261111pwa';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261111net';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261111graf';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261112asema';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(txt, "$emitCssV = '20261112si';", "$emitCssV = '%s';" % VER)
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261111auto",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261111kioski",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261111yhdista",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261111pwa",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261111net",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261111graf",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.js?v=20261112asema",
        "emit_lukija.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "emit_lukija.css?v=20261112asema",
        "emit_lukija.css?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "if (window.JAH_EMIT_VERSIO === 'puhe2') {",
        "if (window.JAH_EMIT_VERSIO === 'automaatti3') {",
    )
    txt, _ = sub(
        txt,
        "if (window.JAH_EMIT_VERSIO === 'automaatti1') {",
        "if (window.JAH_EMIT_VERSIO === 'automaatti3') {",
    )
    txt, _ = sub(
        txt,
        "if (window.JAH_EMIT_VERSIO === 'automaatti2') {",
        "if (window.JAH_EMIT_VERSIO === 'automaatti3') {",
    )
    txt, _ = sub(
        txt,
        "if (sessionStorage.getItem('jah-emit-puhe2')) {",
        "if (sessionStorage.getItem('jah-emit-automaatti3')) {",
    )
    txt, _ = sub(
        txt,
        "if (sessionStorage.getItem('jah-emit-automaatti2')) {",
        "if (sessionStorage.getItem('jah-emit-automaatti3')) {",
    )
    txt, _ = sub(
        txt,
        "sessionStorage.setItem('jah-emit-puhe2', '1');",
        "sessionStorage.setItem('jah-emit-automaatti3', '1');",
    )
    txt, _ = sub(
        txt,
        "sessionStorage.setItem('jah-emit-automaatti2', '1');",
        "sessionStorage.setItem('jah-emit-automaatti3', '1');",
    )
    if "si_proto.js" not in txt:
        txt, _ = sub(
            txt,
            '<script src="../assets/js/emit_lukija.js',
            '<script src="../assets/js/si_proto.js?v=' + VER + '"></script>\n<script src="../assets/js/emit_lukija.js',
            pakko=True,
        )
    txt, _ = sub(
        txt,
        "usb_sarja_boot.js?v=20261015puhe",
        "usb_sarja_boot.js?v=" + VER,
    )
    txt, _ = sub(
        txt,
        "usb_sarja_boot.js?v=20261112asema",
        "usb_sarja_boot.js?v=" + VER,
    )
    merkk = "asetaValittuKilpailu($kilpailu_id);"
    lisays = """asetaValittuKilpailu($kilpailu_id);
$automaattiSivu = !empty($emitAutomaattiSivu);
if (!$automaattiSivu && (($_GET['moodi'] ?? '') === 'automaatti')) {
    header('Location: automaatti.php?kilpailu_id=' . (int) $kilpailu_id, true, 302);
    exit;
}
"""
    if "$automaattiSivu" not in txt:
        txt, n = sub(txt, merkk, lisays, pakko=True)
    txt, _ = sub(
        txt,
        "iltarastiKuoriOpts($pdo, $kilpailu, 'tulospalvelu', 'lahtoluennat-page emit-lukija-page emit-iltarasti')",
        "iltarastiKuoriOpts($pdo, $kilpailu, !empty($automaattiSivu) ? 'automaatti' : 'tulospalvelu', 'lahtoluennat-page emit-lukija-page emit-iltarasti' . (!empty($automaattiSivu) ? ' emit-automaatti-sivu' : ''))",
    )
    txt, _ = sub(
        txt,
        """        <button type="button" class="emit-moodi" data-moodi="maali">Leimantarkastus</button>
        <button type="button" class="emit-moodi" data-moodi="automaatti">Automaatti</button>
""",
        """        <button type="button" class="emit-moodi" data-moodi="maali">Leimantarkastus</button>
""",
    )
    txt, _ = sub(
        txt,
        "    iltarasti: <?= $iltarasti ? 'true' : 'false' ?>,\n    alkuMoodi:",
        "    iltarasti: <?= $iltarasti ? 'true' : 'false' ?>,\n"
        "    automaattiSivu: <?= !empty($automaattiSivu) ? 'true' : 'false' ?>,\n    alkuMoodi:",
    )
    txt, _ = sub(
        txt,
        "in_array(($_GET['moodi'] ?? ''), ['lahto', 'maali', 'automaatti'], true)",
        "in_array(($_GET['moodi'] ?? ''), ['lahto', 'maali'], true)",
    )
    write_if(p, txt, orig)


def patch_nav():
    p = DEST / "includes/admin_nav.php"
    txt = p.read_text(encoding="utf-8")
    orig = txt
    vanha = """            echo '<a href="' . htmlspecialchars($tpUrl) . '"'
                . ($active === 'tulospalvelu' ? ' class="active"' : '')
                . '>Tulospalvelu</a>';
"""
    uusi = """            echo '<a href="' . htmlspecialchars($tpUrl) . '"'
                . ($active === 'tulospalvelu' ? ' class="active"' : '')
                . '>Tulospalvelu</a>';
            echo '<a href="/public/automaatti.php?kilpailu_id=' . (int) $kid . '"'
                . ($active === 'automaatti' ? ' class="active"' : '')
                . '>Automaatti</a>';
"""
    if ">Automaatti</a>" not in txt:
        txt, _ = sub(txt, vanha, uusi, pakko=True)
    write_if(p, txt, orig)


def patch_helpers():
    p = DEST / "includes/helpers.php"
    txt = p.read_text(encoding="utf-8")
    orig = txt
    if "'automaatti' => $prefix . 'automaatti.php'" not in txt:
        txt, _ = sub(
            txt,
            "        'leimantarkastus' => $prefix . 'leimantarkastus.php' . $q,\n",
            "        'leimantarkastus' => $prefix . 'leimantarkastus.php' . $q,\n"
            "        'automaatti' => $prefix . 'automaatti.php' . $q,\n",
            pakko=True,
        )
    write_if(p, txt, orig)


def patch_iltarasti():
    p = DEST / "includes/iltarasti.php"
    txt = p.read_text(encoding="utf-8")
    orig = txt
    vanha = """        'tulospalvelu' => iltarastiTulospalveluUrl($kilpailuId),
        default => '/public/iltarastit.php',"""
    uusi = """        'tulospalvelu' => iltarastiTulospalveluUrl($kilpailuId),
        'automaatti' => '/public/automaatti.php?kilpailu_id=' . (int) $kilpailuId,
        default => '/public/iltarastit.php',"""
    if "'automaatti' =>" not in txt:
        txt, _ = sub(txt, vanha, uusi, pakko=True)
    write_if(p, txt, orig)


def cache_bust():
    repls = [
        ("emit_lukija.js?v=20261111auto", "emit_lukija.js?v=" + VER),
        ("emit_lukija.js?v=20261111kioski", "emit_lukija.js?v=" + VER),
        ("emit_lukija.js?v=20261111yhdista", "emit_lukija.js?v=" + VER),
        ("emit_lukija.js?v=20261111pwa", "emit_lukija.js?v=" + VER),
        ("emit_lukija.js?v=20261111net", "emit_lukija.js?v=" + VER),
        ("emit_lukija.js?v=20261111graf", "emit_lukija.js?v=" + VER),
        ("emit_lukija.js?v=20261112asema", "emit_lukija.js?v=" + VER),
        ("si_proto.js?v=20261112asema", "si_proto.js?v=" + VER),
        ("usb_sarja_boot.js?v=20261015puhe", "usb_sarja_boot.js?v=" + VER),
        ("usb_sarja.js?v=20261015puhe", "usb_sarja.js?v=" + VER),
        ("emit_lukija.css?v=20261111auto", "emit_lukija.css?v=" + VER),
        ("emit_lukija.css?v=20261111kioski", "emit_lukija.css?v=" + VER),
        ("emit_lukija.css?v=20261111yhdista", "emit_lukija.css?v=" + VER),
        ("emit_lukija.css?v=20261111pwa", "emit_lukija.css?v=" + VER),
        ("emit_lukija.css?v=20261111net", "emit_lukija.css?v=" + VER),
        ("emit_lukija.css?v=20261111graf", "emit_lukija.css?v=" + VER),
        ("seuranta.js?v=20261111auto", "seuranta.js?v=" + VER),
        ("seuranta.js?v=20261111kioski", "seuranta.js?v=" + VER),
        ("seuranta.js?v=20261111yhdista", "seuranta.js?v=" + VER),
        ("seuranta.js?v=20261111pwa", "seuranta.js?v=" + VER),
        ("seuranta.js?v=20261111net", "seuranta.js?v=" + VER),
        ("seuranta.js?v=20261111graf", "seuranta.js?v=" + VER),
        ("live_board.css?v=20261111auto", "live_board.css?v=" + VER),
        ("live_board.css?v=20261111kioski", "live_board.css?v=" + VER),
        ("live_board.css?v=20261111yhdista", "live_board.css?v=" + VER),
        ("live_board.css?v=20261111pwa", "live_board.css?v=" + VER),
        ("live_board.css?v=20261111net", "live_board.css?v=" + VER),
        ("live_board.css?v=20261111graf", "live_board.css?v=" + VER),
        ("live_board.css?v=20261112asema", "live_board.css?v=" + VER),
        ("emit_lukija.css?v=20261112asema", "emit_lukija.css?v=" + VER),
        ("seuranta.js?v=20261112asema", "seuranta.js?v=" + VER),
        ("jah-pwa.js?v=20261112asema", "jah-pwa.js?v=" + VER),
        ("sw-jahonline.js?v=20261112asema", "sw-jahonline.js?v=" + VER),
        ("TULOSPALVELU_ASSET_V = '20261112asema'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("$emitCssV = '20261112asema'", "$emitCssV = '%s'" % VER),
        ("jah-pwa.js?v=20261111auto", "jah-pwa.js?v=" + VER),
        ("jah-pwa.js?v=20261111kioski", "jah-pwa.js?v=" + VER),
        ("jah-pwa.js?v=20261111yhdista", "jah-pwa.js?v=" + VER),
        ("jah-pwa.js?v=20261111pwa", "jah-pwa.js?v=" + VER),
        ("jah-pwa.js?v=20261111net", "jah-pwa.js?v=" + VER),
        ("jah-pwa.js?v=20261111graf", "jah-pwa.js?v=" + VER),
        ("sw-jahonline.js?v=20261111auto", "sw-jahonline.js?v=" + VER),
        ("sw-jahonline.js?v=20261111kioski", "sw-jahonline.js?v=" + VER),
        ("sw-jahonline.js?v=20261111yhdista", "sw-jahonline.js?v=" + VER),
        ("sw-jahonline.js?v=20261111pwa", "sw-jahonline.js?v=" + VER),
        ("sw-jahonline.js?v=20261111net", "sw-jahonline.js?v=" + VER),
        ("sw-jahonline.js?v=20261111graf", "sw-jahonline.js?v=" + VER),
        ("TULOSPALVELU_ASSET_V = '20261111auto'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("TULOSPALVELU_ASSET_V = '20261111kioski'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("TULOSPALVELU_ASSET_V = '20261111yhdista'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("TULOSPALVELU_ASSET_V = '20261111pwa'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("TULOSPALVELU_ASSET_V = '20261111net'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("TULOSPALVELU_ASSET_V = '20261111graf'", "TULOSPALVELU_ASSET_V = '%s'" % VER),
        ("$emitCssV = '20261111auto'", "$emitCssV = '%s'" % VER),
        ("$emitCssV = '20261111kioski'", "$emitCssV = '%s'" % VER),
        ("$emitCssV = '20261111yhdista'", "$emitCssV = '%s'" % VER),
        ("$emitCssV = '20261111pwa'", "$emitCssV = '%s'" % VER),
        ("$emitCssV = '20261111net'", "$emitCssV = '%s'" % VER),
        ("$emitCssV = '20261111graf'", "$emitCssV = '%s'" % VER),
        ("jahonline-app-v13", "jahonline-app-v19"),
        ("jahonline-app-v14", "jahonline-app-v19"),
        ("jahonline-app-v15", "jahonline-app-v19"),
        ("jahonline-app-v16", "jahonline-app-v19"),
        ("jahonline-app-v17", "jahonline-app-v19"),
        ("jahonline-app-v18", "jahonline-app-v20"),
        ("jahonline-app-v19", "jahonline-app-v20"),
    ]
    n = 0
    for root in [DEST / "public", DEST / "admin", DEST / "includes", DEST / "assets"]:
        if not root.exists():
            continue
        for p in root.rglob("*"):
            if p.suffix.lower() not in {".php", ".js", ".css", ".html"}:
                continue
            txt = p.read_text(encoding="utf-8", errors="ignore")
            orig = txt
            for a, b in repls:
                txt = txt.replace(a, b)
            if txt != orig:
                p.write_text(txt, encoding="utf-8")
                n += 1
                print("cache", p)
    print("cache tiedostoja", n)


if __name__ == "__main__":
    patch_emit_lukija()
    patch_nav()
    patch_helpers()
    patch_iltarasti()
    cache_bust()
    print("ok php")
