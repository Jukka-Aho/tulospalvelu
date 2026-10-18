#!/usr/bin/env python3
"""Osanottajat: API-synkka ei välähdytä ikkunaa eikä hylkää muokkauksia."""
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SAIKE = os.path.join(ROOT, "TPsource", "V52", "cbHk", "ApiSaike.cpp")


def body(text, key, end_key):
    i = text.find(key)
    if i < 0:
        raise AssertionError(key + " missing")
    j = text.find(end_key, i + len(key))
    if j < 0:
        raise AssertionError(end_key + " missing")
    return text[i:j]


def test_sakko_detects_change():
    text = open(SAIKE, encoding="latin-1").read()
    b = body(text, "static bool ApiAsetaSakko(", "int ApiSovellaKilpailijatJson(")
    assert "wmemcpy(ennen, kilp.pv[vaihe].asakot" in b
    assert "return wmemcmp(ennen, kilp.pv[vaihe].asakot" in b
    assert "static void ApiAsetaSakko(" not in text
    # Every caller must use the result instead of marking the record dirty unconditionally.
    calls = [c for c in re.findall(r"[^\n]*ApiAsetaSakko\([^\n]*", text)
             if "static bool ApiAsetaSakko(" not in c]
    assert len(calls) == 2, calls
    for c in calls:
        assert c.strip().startswith("if (") and "&& ApiAsetaSakko(" in c, c
    print("ok sakko change detection")


def test_maa_detects_change():
    text = open(SAIKE, encoding="latin-1").read()
    b = body(text, "static void ApiSovellaTapahtumaJson(const UnicodeString& o)\n", "\n}\n")
    assert "if (!maa.IsEmpty() && UnicodeString(kilp.maa) != maa.SubString(1, 3))" in b
    print("ok maa change detection")


def test_refresh_without_flicker():
    text = open(SAIKE, encoding="latin-1").read()
    b = body(text, "void ApiPaivitaOsanottajaNakyma(void)", "void __fastcall TApiSaike::PaivitaOsanottajat")
    edit = b.find("g->Options.Contains(goEditing)")
    clear = b.find("gOsanottajaLika = 0;\n\tint rr")
    redraw_off = b.find("WM_SETREDRAW, FALSE")
    nayta = b.find("naytaTiedot()")
    redraw_on = b.find("WM_SETREDRAW, TRUE")
    assert edit >= 0 and clear > edit, "edit mode must keep the dirty flag"
    assert 0 <= redraw_off < nayta < redraw_on
    assert "__finally" in b and "g->Invalidate()" in b
    assert "int top = g->TopRow;" in b and "g->TopRow = top;" in b
    print("ok refresh without flicker")


def main():
    test_sakko_detects_change()
    test_maa_detects_change()
    test_refresh_without_flicker()
    print("all ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
