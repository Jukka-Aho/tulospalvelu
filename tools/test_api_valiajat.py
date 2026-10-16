#!/usr/bin/env python3
"""Protocol checks for bidirectional online-split sync (Hk + Viesti)."""
import json
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))


def test_tapahtuma_yksilo():
    body = {
        "action": "tapahtuma",
        "kilpailu_id": 11,
        "lahde": "HkKisaWin",
        "tapahtumat": [
            {"numero": 101, "piste": 1, "aika_sec": 1234, "lahde": "online"},
            {"numero": 101, "piste": 0, "aika_sec": 3456, "lahde": "online"},
        ],
    }
    raw = json.dumps(body, ensure_ascii=False)
    assert "tapahtuma" in raw
    assert body["tapahtumat"][0]["piste"] == 1
    assert body["tapahtumat"][1]["piste"] == 0
    print("ok tapahtuma yksilo")


def test_tapahtuma_viesti():
    body = {
        "action": "tapahtuma",
        "kilpailu_id": 11,
        "lahde": "ViestiWin",
        "tyyppi": "viesti",
        "tapahtumat": [
            {"numero": 42, "osuus": 2, "piste": 1, "aika_sec": 890, "lahde": "online"},
        ],
    }
    assert body["tapahtumat"][0]["osuus"] == 2
    print("ok tapahtuma viesti")


def apply_valiajat(local, inbound, vastaanotta=True, korvaa=True):
    if not vastaanotta:
        return local
    out = dict(local)
    for va in inbound:
        if va.get("rasti_koodi", 0) > 0:
            continue
        p = va.get("piste")
        if p is None or p < 1:
            continue
        sec = va.get("aika_sec")
        if sec is None or sec <= 0:
            continue
        if not korvaa and out.get(p):
            continue
        out[p] = sec
    return out


def merge_aika_sec(local, inbound, korvaa):
    """Periodic fetch fills empty local times; never apply 0/null. Manual Hae may replace."""
    if inbound is None or inbound <= 0:
        return local
    if local and local > 0 and not korvaa:
        return local
    return inbound


def merge_lasna_tark(tark, lasna_flag, status=None):
    """lasna:true does not clear DNS (T). Only E/P/V/B become present."""
    if not lasna_flag:
        return tark
    if tark in (u"E", u"P", u"V", u"B"):
        return u"-"
    return tark


def lahto_sec_to_ticks(sec, t0=0, SEK=1000):
    if sec < 0 or sec >= 86400:
        return None
    return int((sec - t0 * 3600) * SEK)


def test_valiajat_apply():
    local = {1: 100, 2: 0}
    inbound = [{"piste": 1, "aika_sec": 111}, {"piste": 2, "aika_sec": 222}]
    got = apply_valiajat(local, inbound, True, korvaa=True)
    assert got == {1: 111, 2: 222}
    got2 = apply_valiajat(local, inbound, False)
    assert got2 == local
    periodic = apply_valiajat(local, inbound, True, korvaa=False)
    assert periodic[1] == 100
    assert periodic[2] == 222
    mixed = apply_valiajat(
        local,
        [
            {"piste": 1, "aika_sec": 111},
            {"piste": 1, "aika_sec": 184, "rasti_koodi": 31, "vali_sec": 184},
            {"piste": 2, "aika_sec": 401, "rasti_koodi": 42},
        ],
        True,
        korvaa=True,
    )
    assert mixed == {1: 111, 2: 0}
    print("ok valiajat apply")


def p_aika_va_index(piste):
    """kilptietue::p_aika uses va[piste+1]; va[1] is maali, va[2] is 1st split."""
    return piste + 1


def test_valiajat_wire_index():
    assert p_aika_va_index(0) == 1
    assert p_aika_va_index(1) == 2
    assert p_aika_va_index(3) == 4
    src = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiSaike.cpp"),
               encoding="utf-8", errors="replace").read()
    assert "ApiVaIx" in src
    assert "ApiVaNva" in src
    assert "va[ix].vatulos" in src
    assert "va[p].vatulos" not in src
    assert "va[piste].vatulos" not in src
    # k_pv is 0-based; subtracting 1 sent the previous day's empty splits.
    assert "int ipv = k_pv;" in src
    assert "ipv = k_pv - 1" not in src
    assert "kilp.p_aika(p)" in src
    assert "nva < kilpparam.valuku" in src
    # Drain+log once. A second ApiLahetaTapahtumatNyt in Kasittele
    # always saw an empty queue and hid a successful live send.
    kas = src.split("void __fastcall TApiSaike::Kasittele(void)", 1)[1]
    kas = kas.split("void __fastcall TApiSaike::Execute(void)", 1)[0]
    assert kas.count("ApiLahetaTapahtumatNyt()") == 1
    print("ok valiajat wire index")


def test_synkka_merge():
    assert merge_aika_sec(2700, 0, False) == 2700
    assert merge_aika_sec(2700, None, False) == 2700
    assert merge_aika_sec(2700, 2800, False) == 2700
    assert merge_aika_sec(0, 2800, False) == 2800
    assert merge_aika_sec(2700, 2800, True) == 2800
    assert merge_lasna_tark(u"T", True) == u"T"
    assert merge_lasna_tark(u"N", True) == u"N"
    assert merge_lasna_tark(u"E", True) == u"-"
    assert merge_lasna_tark(u"P", True) == u"-"
    assert lahto_sec_to_ticks(36300, 0) == 36300 * 1000

    yht = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiYhteydet.cpp"),
               encoding="utf-8", errors="replace").read()
    assert "ApiSynkkaaHaeKaikki(true)" in yht

    for rel in (
            os.path.join("TPsource", "V52", "cbHk", "ApiSaike.cpp"),
            os.path.join("TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
            ):
        text = open(os.path.join(ROOT, rel), encoding="utf-8", errors="replace").read()
        assert "ApiLueLahtoAika" in text
        assert 'L"pirila_lahto_at"' in text
        assert 'L"lahto_sec"' in text
        assert "bool korvaaKentat" in text
        assert "ApiSynkkaaHaeKaikki(false)" in text
        assert "if (!muuttui)" in text
        assert "aikaSec > 0" in text
        assert "if (aikaSec >= 0)" not in text
        assert "Jono taynna" in text
        assert "lasnaJonoPudotettu" in text
        assert "t == L'E' || t == L'P' || t == L'V' || t == L'B'" in text
        assert "t == L'E' || t == L'P' || t == L'V' || t == L'B' || t == L'T'" not in text
        assert "m == L'E' && onTulos" in text
        assert "m == L'T' && onTulos" not in text
    vi = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    assert "addtall(&kilp, &nd, 0)" in vi
    assert "sarja_nimi" in vi
    print("ok synkka merge")


def test_cpp_json_actions():
    hk = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    vi = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    assert r'\"action\":\"tapahtuma\"' in hk
    assert r'\"action\":\"tapahtuma\"' in vi
    assert r'\"tyyppi\":\"yksilo\"' in hk
    assert r'\"tyyppi\":\"viesti\"' in vi
    assert r'\"osuus\":' in vi
    tap = hk.split("ApiLahetaTapahtumat(", 1)[1]
    tap = tap.split("ApiLahetaTapahtumatNyt", 1)[0]
    assert r'action\":\"tapahtuma\"' in tap
    assert r'\"tyyppi\":\"yksilo\"' in tap
    print("ok cpp json actions")


def test_emit_rasti_koodi_valiajat():
    """JAHOnline stores emit legs in valiajat[] with rasti_koodi, not rastivaliajat[]."""
    for rel in (
            os.path.join("TPsource", "V52", "cbHk", "ApiSaike.cpp"),
            os.path.join("TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
            ):
        text = open(os.path.join(ROOT, rel), encoding="utf-8", errors="replace").read()
        assert "ApiLisaaEmitValiajat" in text, rel
        assert r'\"rasti_koodi\":' in text, rel
        assert r'\"vali_sec\":' in text, rel
        assert "laskeemitvaliajat" in text, rel
        assert "tee_emva" in text, rel
        assert "rastivaliajat" not in text, rel
        assert 'L"rasti_koodi", rkoodi' in text, rel
        assert "rkoodi > 0" in text, rel
        assert "ApiLisaaEmitValiajat(kilp" in text, rel
        assert "valiajat" in text
    hk = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    vi = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    assert "haerata(&kilp)" in hk
    assert "haerata(&kilp, os)" in vi
    assert "if (!rt)" in vi
    body = {
        "action": "synkkaa",
        "kilpailijat": [
            {
                "numero": 101,
                "valiajat": [
                    {"piste": 1, "aika_sec": 612, "sija": 3},
                    {"piste": 1, "aika_sec": 184, "rasti_koodi": 31, "vali_sec": 184},
                ],
            }
        ],
    }
    raw = json.dumps(body, ensure_ascii=False)
    assert "rasti_koodi" in raw
    assert "rastivaliajat" not in raw
    print("ok emit rasti_koodi valiajat")


SEK = 1000  # TPsource/V52/Tp/TpDef.h: SEK = 10*KSEK, KSEK = 10*CSEK, CSEK = 10


def api_tulos_sec(tl):
    """Mirror of ApiTulosSec: Pirilä ticks -> JAHOnline seconds."""
    if tl <= 0:
        return 0
    return int(tl // SEK)


def api_sec_to_ticks(sec):
    """Mirror of ApiSecToTicks: JAHOnline seconds -> Pirilä ticks."""
    if sec <= 0:
        return 0
    if sec >= 100000:
        return int(sec)
    return int(sec) * SEK


def test_aika_sec_units():
    ticks_45 = 45 * 60 * SEK
    assert ticks_45 == 2700000
    assert api_tulos_sec(ticks_45) == 2700
    assert api_sec_to_ticks(2700) == ticks_45
    assert api_sec_to_ticks(2700000) == 2700000  # legacy ticks inbound
    assert api_tulos_sec(1234 * SEK) == 1234  # docs example
    start = 10 * 3600 * SEK
    finish_clock = (10 * 3600 + 45 * 60) * SEK
    assert api_tulos_sec(finish_clock - start) == 2700
    # Sending clock ticks as duration would look like 10h45min.
    assert api_tulos_sec(finish_clock) == 38700
    print("ok aika_sec units")


def test_cpp_converts_ticks_to_seconds():
    hk = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    vi = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
              encoding="utf-8", errors="replace").read()
    for src, name in ((hk, "Hk"), (vi, "Viesti")):
        assert "static int ApiTulosSec(INT32 tl)" in src, name
        assert "tl / SEK" in src, name
        assert "ApiSecToTicks" in src, name
        assert r'\"aika_sec\":" + IntToStr(tsec)' in src, name
        assert r'\"aika_sec\":" + IntToStr((int)tls)' not in src, name
        assert r'\"aika_sec\":" + IntToStr((int)va)' not in src, name
    assert "ApiViestiTulosTicks" in vi
    assert "osTulos" in vi
    assert "ApiViestiInboundMaali" in vi
    assert "ApiViestiTapahtumaSec" in vi
    print("ok cpp converts ticks to seconds")


def test_source_hooks():
    files = {
        "cbHk/ApiSaike.cpp": ["tapahtuma", "lahetaValiajat", "valiajat", "yksilo", "rasti_koodi"],
        "cbHk/UnitAjanotto.cpp": ["IlmoitaTapahtuma"],
        "cbHk/UnitEmit.cpp": ["IlmoitaTapahtuma", "IlmoitaLasna", "nva < kilpparam.valuku"],
        "cbHk/UnitKilpailijaOnline.cpp": ["IlmoitaTapahtuma", "nva < kilpparam.valuku"],
        "cbHk/WinHk.dfm": ["JAHOnline API (synkka)"],
        "ViestiWin/ApiSaike.cpp": ["viesti", "osuus", "tapahtuma", "rasti_koodi"],
        "ViestiWin/UnitAjanotto.cpp": ["IlmoitaTapahtuma"],
        "ViestiWin/UnitEmit.cpp": ["IlmoitaTapahtuma", "IlmoitaLasna"],
        "ViestiWin/UnitJoukkuetiedot.cpp": ["IlmoitaTapahtuma"],
        "ViestiWin/UnitMain.cpp": ["JahonlineApi1Click", "ApiVIntegration", "KilpailuSuljettu"],
        "ViestiWin/UnitMain.dfm": ["JAHOnline API (synkka)"],
        "cbHk/ApiYhteydet.dfm": ["online-rastit", "Lopeta synkka"],
        "cbHk/ApiYhteydet.cpp": ["avaa kilpailu ensin", "LopetaSynkka", "OnKilpailuAvattu"],
        "cbHk/ApiIntegration.cpp": ["KilpailuSuljettu", "if (!kilpailuAvattu)"],
        "cbHk/WinHk.cpp": ["KilpailuSuljettu", "KilpailuAvattu"],
    }
    for rel, needles in files.items():
        path = os.path.join(ROOT, "TPsource", "V52", rel)
        text = open(path, encoding="utf-8", errors="replace").read()
        for n in needles:
            if n not in text:
                raise AssertionError("%s missing %r" % (rel, n))
    print("ok source hooks")


def test_synkka_not_started_without_kilpailu():
    integ = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiIntegration.cpp"),
                 encoding="utf-8", errors="replace").read()
    yht = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiYhteydet.cpp"),
               encoding="utf-8", errors="replace").read()
    assert "if (!kilpailuAvattu)" in integ
    assert "void TApiIntegration::KilpailuSuljettu(void)" in integ
    assert "OnKilpailuAvattu()" in yht
    assert "void __fastcall TFormApiYhteydet::LopetaSynkka(void)" in yht
    assert "BtnLopetaSynkkaClick" in yht
    # Opening a competition starts the thread for a running sync or the JAHOnline bus.
    avattu = integ.split("void TApiIntegration::KilpailuAvattu(void)", 1)[1]
    avattu = avattu.split("void TApiIntegration::KilpailuSuljettu(void)", 1)[0]
    assert "if (apiconfig.kaynnissa || apiconfig.siirtotapa == 1)" in avattu
    assert avattu.find("if (apiconfig.kaynnissa || apiconfig.siirtotapa == 1)") < avattu.find("Alusta()")
    assert "siirtotapa" in yht
    assert "tapahtumat_hae" in yht
    assert "ApiTapahtumaPudotettuOta" in yht
    hkdat = open(os.path.join(ROOT, "TPsource", "V52", "Hk", "HkDat.cpp"),
                 encoding="utf-8", errors="replace").read()
    vdat = open(os.path.join(ROOT, "TPsource", "V52", "Juk", "vdat.cpp"),
                encoding="utf-8", errors="replace").read()
    assert "comfl && !api_siirto_jahonline()" not in hkdat
    assert "if (comfl)" in hkdat
    assert "comfl && !api_siirto_jahonline()" not in vdat
    dfm = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiYhteydet.dfm"),
               encoding="utf-8", errors="replace").read()
    assert "LabelSiirtoOhje" in dfm
    assert "Tapahtumaloki:" in dfm
    assert "hiverkko koneelta koneelle" in dfm
    print("ok synkka lifecycle")


def lasna_hk(tark):
    return tark not in (u"P", u"E", u"V")


def status_merkki(keskhyl, on_lasna, on_tulos):
    if keskhyl == u"T":
        return u"OK"
    if keskhyl == u"H":
        return u"DSQ"
    if keskhyl == u"K":
        return u"DNF"
    if keskhyl in (u"E", u"P", u"V"):
        return u"DNS"
    if on_lasna and not on_tulos:
        return u"LASNA"
    if on_lasna:
        return u"OK"
    return u"DNS"


def api_ilmoittautunut_emit_lasna(tark, status, keskhyl, on_emit):
    """N → läsnä (-) on start-gate emit or explicit keskhyl '-', not on LASNA echo."""
    if tark != u"N":
        return tark
    if on_emit:
        return u"-"
    if keskhyl is not None and keskhyl[:1] == u"-":
        return u"-"
    return tark


def teejari_sanitize_tark(tark, allowed=u"-KOHEVPTIXMBN"):
    """HkIx teejari on open: unknown tark letters become '-' and are written back."""
    if not tark or tark not in allowed:
        return u"-"
    return tark


def parse_lasna_text(text):
    """Grid first-letter parse: esItys (E+S→I) vs Ilmoittautunut (I+L→N)."""
    if not text:
        return u"-"
    t = text.upper()
    if t[0] == u"E":
        if len(t) > 1 and t[1] == u"S":
            return u"I"
        return u"E"
    if t[0] == u"I":
        if len(t) > 1 and t[1] == u"L":
            return u"N"
        return u"I"
    if t[0] == u"L":
        return u"-"
    return t[0]


def test_ilmoittautunut_tark():
    assert lasna_hk(u"-")
    assert lasna_hk(u"T")
    assert lasna_hk(u"N")
    assert not lasna_hk(u"P")
    assert not lasna_hk(u"E")
    assert status_merkki(u"N", True, False) == u"LASNA"
    assert status_merkki(u"-", True, False) == u"LASNA"
    assert status_merkki(u"N", True, True) == u"OK"
    assert status_merkki(u"T", True, True) == u"OK"
    assert status_merkki(u"H", True, True) == u"DSQ"
    assert status_merkki(u"K", True, False) == u"DNF"
    assert status_merkki(u"E", False, False) == u"DNS"
    assert parse_lasna_text(u"esItys") == u"I"
    assert parse_lasna_text(u"Ilmoittautunut") == u"N"
    assert parse_lasna_text(u"Ilmoitt.") == u"N"
    assert parse_lasna_text(u"I") == u"I"
    assert parse_lasna_text(u"Läsnä") == u"-"

    # Restart/fetch echo: LASNA without keskhyl must keep N (same as published ilmoittautunut).
    assert api_ilmoittautunut_emit_lasna(u"N", u"LASNA", None, False) == u"N"
    assert api_ilmoittautunut_emit_lasna(u"N", u"PRESENT", None, False) == u"N"
    assert api_ilmoittautunut_emit_lasna(u"N", u"OK", None, False) == u"N"
    assert api_ilmoittautunut_emit_lasna(u"N", u"LASNA", u"N", False) == u"N"
    assert api_ilmoittautunut_emit_lasna(u"N", u"LASNA", u"-", False) == u"-"
    assert api_ilmoittautunut_emit_lasna(u"N", u"OK", None, True) == u"-"
    assert api_ilmoittautunut_emit_lasna(u"-", u"LASNA", None, False) == u"-"

    assert teejari_sanitize_tark(u"N") == u"N"
    assert teejari_sanitize_tark(u"-") == u"-"
    assert teejari_sanitize_tark(u"K") == u"K"
    assert teejari_sanitize_tark(u"") == u"-"
    assert teejari_sanitize_tark(u"Z") == u"-"

    hk_tls = open(os.path.join(ROOT, "TPsource", "V52", "Hk", "HkTls.cpp"),
                  encoding="utf-8", errors="replace").read()
    assert 'wcswcind(kh, L"-TIHKOEVPXMBN")' in hk_tls
    assert "tark(i_pv) == L'N'" in hk_tls
    assert "tark(i_pv) != L'N'" not in hk_tls

    vkilp = open(os.path.join(ROOT, "TPsource", "V52", "Juk", "vkilp.cpp"),
                 encoding="utf-8", errors="replace").read()
    assert 'stschind(trk, "-TIKHEVPN")' in vkilp
    assert 'stschind(ch, "TI-N")' in vkilp
    assert 'return(L"Ilmoitt.")' in vkilp
    vdat = open(os.path.join(ROOT, "TPsource", "V52", "Juk", "vdat.cpp"),
                encoding="utf-8", errors="replace").read()
    assert '"-TIKHEPN"' in vdat
    vix = open(os.path.join(ROOT, "TPsource", "V52", "Juk", "VIx.cpp"),
               encoding="utf-8", errors="replace").read()
    assert '"-KHEPITN"' in vix
    hk_ix = open(os.path.join(ROOT, "TPsource", "V52", "Hk", "HkIx.cpp"),
                 encoding="utf-8", errors="replace").read()
    # teejari on open used to rewrite N → '-' because N was missing from this charset.
    assert 'wcswcind(kilp.tark(k_pv), L"-KOHEVPTIXMBN")' in hk_ix

    for rel in (
            os.path.join("TPsource", "V52", "cbHk", "ApiSaike.cpp"),
            os.path.join("TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
            ):
        text = open(os.path.join(ROOT, rel), encoding="utf-8", errors="replace").read()
        assert 'case L\'N\': return L"ILMOITTAUTUNUT"' not in text
        assert 'case L\'T\': return L"OK"' in text
        assert 'case L\'H\': return L"DSQ"' in text
        assert 'case L\'K\': return L"DNF"' in text
        assert 'CompareIC(L"ILMOITTAUTUNUT")' in text
        assert "ApiIlmoittautunutEmitLasna" in text
        assert "ApiStatusEmitLasna" not in text
        assert "m == L'-'" in text
        assert "status LASNA alone" in text
        assert "ApiStatusEmitLasna(status)" not in text

    emit_hk = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "UnitEmit.cpp"),
                   encoding="utf-8", errors="replace").read()
    assert 'TarkKoodit[] = L"--TIKHEVPMXBN"' in emit_hk
    assert "t == L'N'" in emit_hk
    dfm = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "UnitEmit.dfm"),
               encoding="utf-8", errors="replace").read()
    assert dfm.rstrip().endswith("") or "Ilmoittautunut" in dfm
    # New combo item must be last so existing ItemIndex values stay valid.
    items = re.findall(r"'([^']*)'", dfm.split("object TarkVal:", 1)[1].split("end", 1)[0])
    assert items[-1] == "Ilmoittautunut"
    assert items[-2] == "Havaittu"

    emit_v = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "UnitEmit.cpp"),
                  encoding="utf-8", errors="replace").read()
    assert 'koodit[] = L"--TIKHEVPN"' in emit_v
    vdfm = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "UnitEmit.dfm"),
                encoding="utf-8", errors="replace").read()
    vitems = re.findall(r"'([^']*)'", vdfm.split("object TarkVal:", 1)[1].split("end", 1)[0])
    assert vitems[-1] == "Ilmoittautunut"

    oo = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "UnitOsanottajat.cpp"),
              encoding="utf-8", errors="replace").read()
    # Grid text comes from tark_selite(); typing IL... stores status N.
    assert "tark_selite(" in oo
    assert "set_tark(L'N'" in oo
    assert "THKIEVPXMBN" in oo
    khto = open(os.path.join(ROOT, "TPsource", "V52", "tputilv2", "khtostr.cpp"),
                encoding="utf-8", errors="replace").read()
    assert "case L'N'" in khto
    assert "Ilmoittautunut" in khto

    hk_menu = open(os.path.join(ROOT, "TPsource", "V52", "cbHk", "WinHk.dfm"),
                   encoding="utf-8", errors="replace").read()
    assert "NaytaMaastossa1" in hk_menu
    assert "maastossa olevat kilpailijat" in hk_menu
    v_menu = open(os.path.join(ROOT, "TPsource", "V52", "ViestiWin", "UnitMain.dfm"),
                  encoding="utf-8", errors="replace").read()
    assert "NaytaMaastossa1" in v_menu
    assert "maastossa olevat kilpailijat" in v_menu

    for rel in (
            os.path.join("TPsource", "V52", "cbHk", "UnitMaastossa.cpp"),
            os.path.join("TPsource", "V52", "ViestiWin", "UnitMaastossa.cpp"),
            ):
        text = open(os.path.join(ROOT, rel), encoding="utf-8", errors="replace").read()
        assert "haeKilpailijat" in text
        assert "Lpaikka" in text
        assert "MaastossaMaxNva" in text
        assert "ilman tulosta" in text
        assert "Viim.va" not in text
        raw = open(os.path.join(ROOT, rel), "rb").read()
        # bcc32 reads source as CP1252; UTF-8 ä/ö in L"..." show as garbage.
        assert b'L"L\xe4ht\xf6"' in raw
        assert b"L\xc3\xa4ht" not in raw

    for rel in (
            os.path.join("TPsource", "V52", "cbHk", "ApiSaike.cpp"),
            os.path.join("TPsource", "V52", "ViestiWin", "ApiSaike.cpp"),
            os.path.join("TPsource", "V52", "cbHk", "ApiYhteydet.cpp"),
            ):
        raw = open(os.path.join(ROOT, rel), "rb").read()
        assert b"L\xe4het" in raw
        assert b"L\xc3\xa4het" not in raw
    print("ok ilmoittautunut tark")


def main():
    test_tapahtuma_yksilo()
    test_tapahtuma_viesti()
    test_valiajat_apply()
    test_valiajat_wire_index()
    test_synkka_merge()
    test_cpp_json_actions()
    test_emit_rasti_koodi_valiajat()
    test_source_hooks()
    test_synkka_not_started_without_kilpailu()
    test_aika_sec_units()
    test_cpp_converts_ticks_to_seconds()
    test_ilmoittautunut_tark()
    print("all ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
