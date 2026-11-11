#!/usr/bin/env python3
"""Yhdista button, remembered competitions and JAHOnline luonne from Pirila laji."""
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
V52 = os.path.join(ROOT, "TPsource", "V52")
CBHK = os.path.join(V52, "cbHk")
VWIN = os.path.join(V52, "ViestiWin")


def read(path):
    return open(path, encoding="latin-1").read().replace("\r", "")


def test_form():
    dfm = read(os.path.join(CBHK, "ApiYhteydet.dfm"))
    hdr = read(os.path.join(CBHK, "ApiYhteydet.h"))

    btn = re.search(r"object BtnYhteysTesti: TButton(.*?)\n      end", dfm, re.S).group(1)
    assert "Caption = 'Yhdist'#228" in btn
    assert "Testaa" not in dfm

    luo = re.search(r"object BtnLuoKilpailu: TButton(.*?)\n      end", dfm, re.S).group(1)
    assert "Visible = False" in luo
    assert "OnClick = BtnLuoKilpailuClick" in luo
    assert "object LabelLuonne: TLabel" in dfm

    # Every dfm object needs a published field, or the form fails to load.
    published = hdr[hdr.index("__published:"):hdr.index("private:")]
    for name in re.findall(r"^\s*object (\w+):", dfm, re.M):
        if name == "FormApiYhteydet":
            continue
        assert re.search(r"\*%s;" % name, published), name
    for handler in re.findall(r"^\s*On\w+ = (\w+)$", dfm, re.M):
        assert "void __fastcall %s(TObject *Sender);" % handler in published, handler
    print("ok form")


def luonne_map(cpp):
    body = cpp[cpp.index("UnicodeString ApiLuonneLajista(wchar_t laji)"):]
    body = body[:body.index("\n}\n")]
    return dict(re.findall(r"case L'(\w)': return L\"(\w+)\";", body))


def kilplajit(path, decl):
    src = read(path)
    m = re.search(decl, src)
    assert m, path
    return m.group(1) + m.group(2)


def test_luonne():
    cpp = read(os.path.join(CBHK, "ApiYhteydet.cpp"))
    mapping = luonne_map(cpp)
    assert mapping["S"] == "suunnistus"
    assert mapping["H"] == "hiihto"
    assert mapping["J"] == "juoksu"
    assert 'return L"muu";' in cpp

    hk = kilplajit(os.path.join(CBHK, "UnitKilpMaaritys.cpp"),
                   r'lajit\[\] = L"(\w+)", muutlajit\[\] = L"(\w+)"')
    vi = kilplajit(os.path.join(VWIN, "UnitKilpMaaritys.cpp"),
                   r'lajit\[\] = L"(\w+)", muutlajit\[\] = L"(\w+)"')
    for laji in set(hk + vi):
        # 'M' (Muu) falls through to the default.
        assert laji in mapping or laji == "M", laji

    names = cpp[cpp.index("UnicodeString ApiLuonneNimi"):]
    for luonne in mapping.values():
        assert 'L"%s"' % luonne in names, luonne

    doc = open(os.path.join(ROOT, "docs", "jahonline-docs", "kisan-luonne.md"),
               encoding="utf-8").read()
    for laji, luonne in mapping.items():
        assert "| `%s` | `%s` |" % (laji, luonne) in doc, (laji, luonne)
    print("ok luonne")


def test_shared_functions():
    yht = read(os.path.join(CBHK, "ApiYhteydet.cpp"))
    hk = read(os.path.join(CBHK, "ApiSaike.cpp"))
    vi = read(os.path.join(VWIN, "ApiSaike.cpp"))
    # ViestiWin links ApiYhteydet.cpp with its own ApiSaike.cpp.
    assert "UnicodeString ApiPaikallinenKello(void)\n{" in yht
    assert "UnicodeString ApiPaikallinenKello(void)\n{" not in hk
    assert "UnicodeString ApiPaikallinenKello(void)\n{" not in vi
    for fn in ("wchar_t ApiPirilaLaji(void)", "wchar_t ApiPirilaAlalaji(void)",
               "UnicodeString ApiPirilaKilpailuNimi(void)", "UnicodeString ApiPirilaPvm(void)",
               "int ApiPirilaVaiheet(void)"):
        assert fn + "\n{" in hk, fn
        assert fn + "\n{" in vi, fn
    print("ok shared functions")


def test_yhdista_and_memory():
    cpp = read(os.path.join(CBHK, "ApiYhteydet.cpp"))
    yhd = cpp[cpp.index("void __fastcall TFormApiYhteydet::Yhdista(void)"):]
    yhd = yhd[:yhd.index("\n}\n")]
    assert 'action\\":\\"yhdista' in yhd
    assert 'action\\":\\"ping' in yhd
    assert "ApiMuistaYhdistetty(" in yhd

    luo = cpp[cpp.index("void __fastcall TFormApiYhteydet::LuoKilpailu(void)"):]
    luo = luo[:luo.index("\n}\n")]
    assert 'action\\":\\"luo_kilpailu' in luo
    assert 'luonne\\":' in luo
    assert luo.rstrip().endswith("Yhdista();")

    hae = cpp[cpp.index("void __fastcall TFormApiYhteydet::HaeKilpailuLista(void)"):]
    hae = hae[:hae.index("\n}\n")]
    ok_pos = hae.index("ApiJsonStatusOk(vastaus)")
    assert hae.index("yhteysOk = true;") > ok_pos
    assert 'jahonline_yhdistetyt.ini' in cpp
    print("ok yhdista and memory")


def test_wide_hex_escapes():
    """L"...\\xe4a..." reads as one wrong character; split the literal instead."""
    bad = re.compile(r'L"(?:[^"\\]|\\.)*?\\x[0-9a-fA-F]{2}[0-9a-fA-F]')
    for path in (os.path.join(CBHK, "ApiYhteydet.cpp"), os.path.join(CBHK, "ApiSaike.cpp"),
                 os.path.join(VWIN, "ApiSaike.cpp")):
        for n, line in enumerate(read(path).split("\n"), 1):
            assert not bad.search(line), "%s:%d" % (path, n)
    print("ok wide hex escapes")


def main():
    test_form()
    test_luonne()
    test_shared_functions()
    test_yhdista_and_memory()
    test_wide_hex_escapes()
    print("all ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
