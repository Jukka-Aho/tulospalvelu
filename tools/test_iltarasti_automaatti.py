#!/usr/bin/env python3
"""Iltarastien maaliautomaatti ja rastiväliaikagrafiikka."""
import os
import re
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
JAH = os.path.join(ROOT, "jahonline")
EMIT_JS = os.path.join(JAH, "assets/js/emit_lukija.js")
SI_JS = os.path.join(JAH, "assets/js/si_proto.js")
SEURANTA_JS = os.path.join(JAH, "assets/js/seuranta.js")
EMIT_CSS = os.path.join(JAH, "assets/css/emit_lukija.css")
BOARD_CSS = os.path.join(JAH, "assets/css/live_board.css")
SW = os.path.join(JAH, "sw-jahonline.js")
PWA = os.path.join(JAH, "assets/js/jah-pwa.js")
FIXTURE = os.path.join(ROOT, "tools/fixtures/seuranta-valiajat.html")


def read(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


def test_tiedostot():
    for p in (EMIT_JS, SI_JS, SEURANTA_JS, EMIT_CSS, BOARD_CSS, SW, PWA):
        assert os.path.isfile(p), p
    print("ok tiedostot")


def test_js_syntax():
    for p in (EMIT_JS, SI_JS, SEURANTA_JS, PWA, SW):
        subprocess.check_call(["node", "--check", p])
    print("ok js syntax")


def test_automaatti_lahde():
    js = read(EMIT_JS)
    css = read(EMIT_CSS)
    for pala in (
        "function onkoAutomaatti",
        "function naytaAutomaatti",
        "function automaattiNayttoMalli",
        "function automaattiPuheTeksti",
        "Hylkäysesitys",
        "Tuntematon kortti",
        "urlMoodi",
        "oletus_moodi",
        "window.JahAutomaatti",
        "automaattiSivu",
        "emit-automaatti-sivu",
        "Automaatti",
        "emit-automaatti-yhdista",
        "Yhdistä lukija",
        "SportIdent",
        "function asetaLukijaTyyppi",
        "function usbLukijaSaatavilla",
        "function naytaAutomaattiVirhe",
        "function siirraAutomaattiYlapalkkiin",
        "function luoAutomaattiPuheNappi",
        "function odotaJahUsbSerial",
        "Palvelin ok",
        "Ei verkkoa",
        "automaatti3",
        "JahSiProto",
        "kirjoitaTavut",
    ):
        assert pala in js, pala
    assert "emit-automaatti-nimi" in css
    assert "is-automaatti-moodi" in css
    assert "emit-automaatti-sivu" in css
    assert "emit-automaatti-yhdista" in css
    assert "emit-lukija-tyyppi" in css
    assert "Hylkäysesitys" in js
    assert os.path.isfile(os.path.join(JAH, "public/automaatti.php"))
    pwa = read(PWA)
    assert "automaatti.php" in pwa
    sw = read(SW)
    assert "jahonline-app-v20" in sw
    assert "20261112si" in sw
    assert "si_proto.js" in sw
    assert "CP210xSerialPort" in read(os.path.join(JAH, "assets/js/usb_sarja.js"))
    print("ok automaatti lahde")


def test_grafiikka_lahde():
    js = read(SEURANTA_JS)
    css = read(BOARD_CSS)
    for pala in (
        "function lueVaPaneeli",
        "function piirraVaGrafiikka",
        "function mukavaMaxEro",
        "function oletusValitut",
        "function sidoVaTaulukkoValinnat",
        "lb-va-rivi-valinta",
        "Kärki 5",
        "lb-va-grafiikka",
        "Tilanne rasteilla",
        "window.JahVaGrafiikka",
        "lb-live-replaced",
        "function onkoKapea",
        "lb-va-kaavio-wrap",
        "lb-va-selite--nimet",
    ):
        assert pala in js, pala
    assert ".lb-va-grafiikka" in css
    assert ".lb-va-valinta" in css
    assert ".lb-va-kaavio-wrap" in css
    assert ".lb-va-selite--nimet" in css
    assert ".lb-va-rivi-valinta" in css
    assert "pan-x pan-y" in css
    assert "Kilpailijat kaaviossa" not in js
    print("ok grafiikka lahde")


def extract_fn(src, name):
    m = re.search(r"function %s\([^)]*\) \{" % re.escape(name), src)
    if not m:
        raise AssertionError("ei funktiota " + name)
    start = m.start()
    i = src.find("{", m.end() - 1)
    depth = 0
    for j in range(i, len(src)):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[start : j + 1]
    raise AssertionError("sulku " + name)


def test_parse_aika_txt():
    src = extract_fn(read(SEURANTA_JS), "parseAikaTxt")
    script = src + """
const cases = [
  ['40', 40],
  ['2:10', 130],
  ['18:21', 1101],
  ['1:02:03', 3723],
  ['(1)', 1],
  ['–', null],
  ['', null],
];
for (const [txt, exp] of cases) {
  const got = parseAikaTxt(txt);
  if (got !== exp) {
    throw new Error(JSON.stringify({txt, exp, got}));
  }
}
console.log('ok parseAikaTxt');
"""
    out = subprocess.check_output(["node", "-e", script], text=True)
    assert "ok parseAikaTxt" in out
    print("ok parseAikaTxt")


def test_mukava_max_ero():
    src = extract_fn(read(SEURANTA_JS), "mukavaMaxEro")
    script = src + """
const cases = [[1,10],[10,10],[11,15],[40,45],[61,90],[200,240],[2000,2100]];
for (const [inn, exp] of cases) {
  const got = mukavaMaxEro(inn);
  if (got !== exp) throw new Error(JSON.stringify({inn, exp, got}));
}
console.log('ok mukavaMaxEro');
"""
    out = subprocess.check_output(["node", "-e", script], text=True)
    assert "ok mukavaMaxEro" in out
    print("ok mukavaMaxEro")


def test_automaatti_malli_puhe():
    js = read(EMIT_JS)
    pala = "\n".join(
        [
            extract_fn(js, "kioskiNimi"),
            extract_fn(js, "muotoileAikaSec"),
            extract_fn(js, "iltarastiTilaTiedot"),
            extract_fn(js, "parseAikaTxt"),
            extract_fn(js, "laskeAikaSecLeimoista"),
            extract_fn(js, "automaattiAikaSec"),
            extract_fn(js, "automaattiNayttoMalli"),
            extract_fn(js, "automaattiPuheTeksti"),
        ]
    )
    script = """
var odottaaLeimat = [];
function nollaSecLeimoista() { return null; }
function leimaOnLukija() { return false; }
""" + pala + """
var ok = automaattiNayttoMalli({
  etunimi: 'Anttoni', sukunimi: 'Ristimäki', sarja: 'A',
  keskhyl: 'T', tarkistus: { ok: true, keskhyl: 'T', aika_txt: '18:21', aika_sec: 1101 },
  sijoitus: 1, kilpailija_id: 162
});
if (ok.nimi.indexOf('Ristimäki') < 0) throw new Error('nimi ' + ok.nimi);
if (ok.aika !== '18:21') throw new Error('aika ' + ok.aika);
if (ok.sija !== 1) throw new Error('sija ' + ok.sija);
if (ok.hylkaysesitys) throw new Error('ei esitys');
if (ok.mitali !== 1) throw new Error('mitali');
if (automaattiPuheTeksti(ok).indexOf('sija 1') < 0) throw new Error('puhe ok');

var es = automaattiNayttoMalli({
  etunimi: 'Maija', sukunimi: 'Meikäläinen', sarja: 'B',
  keskhyl: 'E', esitys: true, kilpailija_id: 9,
  tarkistus: { ok: false, keskhyl: 'E', aika_txt: '40:00', puuttuvat: [45, 46] }
});
if (!es.hylkaysesitys) throw new Error('esitys');
if (es.luokka !== 'hylkays') throw new Error('luokka');
var puhe = automaattiPuheTeksti(es);
if (puhe.indexOf('hylkäysesitys') < 0) throw new Error('puhe es ' + puhe);
if (puhe.indexOf('45') < 0) throw new Error('puuttuu');

var tun = automaattiNayttoMalli({ kysy: true, emit: 123456 });
if (!tun.tuntematon) throw new Error('tuntematon');
if (automaattiPuheTeksti(tun).indexOf('Tuntematon') < 0) throw new Error('puhe tun');
console.log('ok malli');
"""
    out = subprocess.check_output(["node", "-e", script], text=True)
    assert "ok malli" in out
    print("ok automaatti malli")


def parse_aika_py(txt):
    if txt is None:
        return None
    s = re.sub(r"\s+", "", str(txt).strip().strip("()"))
    if not s or s in ("–", "-", "—"):
        return None
    m = re.match(r"^(\d+):(\d{2}):(\d{2})$", s)
    if m:
        return int(m.group(1)) * 3600 + int(m.group(2)) * 60 + int(m.group(3))
    m = re.match(r"^(\d+):(\d{2})$", s)
    if m:
        return int(m.group(1)) * 60 + int(m.group(2))
    m = re.match(r"^(\d+)$", s)
    if m:
        return int(m.group(1))
    return None


def test_si_proto():
    script = r"""
const si = require('./jahonline/assets/js/si_proto.js');
function hex(a){return Array.from(a).map(x=>x.toString(16).padStart(2,'0')).join(' ');}
const crc = si.crc16([0xF0, 0x01, 0x4D]);
if (crc[0]!==0x6d || crc[1]!==0x0a) throw new Error('crc SET_MS '+hex(crc));
const crc2 = si.crc16([0xF1, 0x00]);
if (crc2[0]!==0xf1 || crc2[1]!==0x00) throw new Error('crc GET_MS');
const crc3 = si.crc16([0x83, 0x02, 0x00, 0x80]);
if (crc3[0]!==0xbf || crc3[1]!==0x17) throw new Error('crc GET_SYS');
if (si.dekoodaaKorttinro(0,0,0x30,0x39)!==12345) throw new Error('cn 12345');
if (si.dekoodaaKorttinro(0,2,0x03,0x21)!==200801) throw new Error('cn 200801');
if (si.korttityyppi(12345)!=='SI5') throw new Error('tyyppi5');
if (si.korttityyppi(8500000)!=='SI10') throw new Error('tyyppi10');
const data = new Uint8Array(128);
data[4]=0x30; data[5]=0x39; data[6]=0;
data[19]=0x0e; data[20]=0x10; // start 3600
data[23]=3; // next punch index -> 2 punches
data[32]=0; // extra
data[33]=31; data[34]=0x0e; data[35]=0x74; // 31 @ 3700
data[36]=32; data[37]=0x0e; data[38]=0x7e; // 32 @ 3710
const kortti = si.puraKortti(data, 'SI5');
if (kortti.badge!==12345) throw new Error('badge '+kortti.badge);
if (kortti.lahde!=='si') throw new Error('lahde');
const nolla = kortti.leimat.find(l=>l.koodi===0);
if (!nolla || nolla.kello_sec<3600) throw new Error('nolla '+JSON.stringify(nolla));
if (!kortti.leimat.some(l=>l.koodi===31)) throw new Error('rasti 31');
const keh = si.rakennaKomento(0xF0, [0x4D], true);
if (keh[0]!==0xff || keh[1]!==0x02 || keh[keh.length-1]!==0x03) throw new Error('kehys');
console.log('ok si_proto');
"""
    out = subprocess.check_output(["node", "-e", script], cwd=ROOT, text=True)
    assert "ok si_proto" in out
    print("ok si proto")


def test_valiajat_karki():
    html = read(FIXTURE)
    assert "lb-main--valiajat" in html
    paneeli = html.split('class="lb-paneeli"', 1)[1]
    head = re.search(r'class="lb-rivi has-va lb-head".*?</div>', paneeli, re.S).group(0)
    koodit = re.findall(r'class="lb-va-koodi">(\d+)', head)
    assert koodit[0] == "147"
    assert len(koodit) == 13
    rivit = re.findall(r'class="lb-rivi has-va"(?! lb-head).*?</div>\s*</div>', paneeli, re.S)
    assert len(rivit) >= 3
    eka = rivit[0]
    assert "Ristimäki" in eka
    kok = re.findall(r'lb-va-stack-kok.*?<span class="lb-va-aika">([^<]+)', eka, re.S)
    assert parse_aika_py(kok[0]) == 40
    assert parse_aika_py(kok[-1]) == 18 * 60 + 21
    toka = rivit[1]
    assert "Aho Tiitus" in toka
    kok2 = re.findall(r'lb-va-stack-kok.*?<span class="lb-va-aika">([^<]+)', toka, re.S)
    assert parse_aika_py(kok2[0]) == 61
    assert parse_aika_py(kok[0]) < parse_aika_py(kok2[0])
    print("ok valiajat karki")


def test_docs():
    docs = read(os.path.join(ROOT, "docs/jahonline-docs/iltarastit.md"))
    assert "Automaatti" in docs
    assert "Hylkäysesitys" in docs
    assert "Tilanne rasteilla" in docs or "rastitilanne" in docs.lower() or "grafiikka" in docs
    assert "SportIdent" in docs
    print("ok docs")


def main():
    test_tiedostot()
    test_js_syntax()
    test_automaatti_lahde()
    test_grafiikka_lahde()
    test_parse_aika_txt()
    test_mukava_max_ero()
    test_automaatti_malli_puhe()
    test_si_proto()
    test_valiajat_karki()
    test_docs()
    print("kaikki ok")


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as e:
        sys.stderr.write("komento epäonnistui: %s\n" % e)
        sys.exit(1)
    except AssertionError as e:
        sys.stderr.write("assert: %s\n" % e)
        sys.exit(1)
