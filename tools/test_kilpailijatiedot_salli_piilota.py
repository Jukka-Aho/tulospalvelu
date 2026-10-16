#!/usr/bin/env python3
"""Kilpailijatiedot: Salli muokkaus is hidden while editing."""
import os
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SRC = os.path.join(ROOT, "TPsource", "V52", "cbHk", "UnitKilpailijatiedot.cpp")
HDR = os.path.join(ROOT, "TPsource", "V52", "cbHk", "UnitKilpailijatiedot.h")
DOC = os.path.join(ROOT, "docs-site", "docs", "hkkisawin", "hkluku7.md")


def method_body(text, name, next_name):
    key = "TFormKilpailijatiedot::" + name
    i = text.find(key)
    if i < 0:
        raise AssertionError(name + " missing")
    j = text.find("TFormKilpailijatiedot::" + next_name, i + 1)
    if j < 0:
        raise AssertionError(next_name + " after " + name + " missing")
    return text[i:j]


def test_source():
    text = open(SRC, encoding="utf-8", errors="replace").read()
    hdr = open(HDR, encoding="utf-8", errors="replace").read()
    nayta = method_body(text, "naytaTiedot", "tallennaTiedot")
    aseta = method_body(text, "asetaMuokkaustila", "BtnSalliClick")
    salli = method_body(text, "BtnSalliClick", "BtnPeruutaClick")
    peruuta = method_body(text, "BtnPeruutaClick", "BtnTallennaClick")
    tallenna = method_body(text, "BtnTallennaClick", "PvGridExit")
    close = method_body(text, "FormClose", "BtnSuljeClick")
    lisays = method_body(text, "Liskilpailija1Click", "Poistakilpailija1Click")
    f9_grid = method_body(text, "PvGridKeyDown", "FormResize")
    f9_edt = method_body(text, "EdtKeyDown", "EdtKilpnoKeyPress")

    vis = "BtnSalli->Visible = sallimuokkausvalinta && !sallimuokkaus;"
    assert vis in nayta
    assert vis in aseta
    assert 'BtnSalli->Caption = L"Hakuun ja katseluun"' not in aseta
    assert "GBHaku->Visible = false;" in aseta
    assert "ActiveControl == BtnSalli" in aseta
    assert "FocusControl(EdtSukunimi)" in aseta
    assert 'BtnSalli->Caption = L"Salli muokkaus"' in aseta

    assert "if (!sallimuokkaus)" in salli
    assert "asetaMuokkaustila(true)" in salli
    assert "asetaMuokkaustila(!sallimuokkaus)" not in salli
    assert "asetaMuokkaustila(false)" not in salli

    assert "asetaMuokkaustila(false)" in peruuta
    assert "Kilp = Kilp1" in peruuta
    assert "BtnSalliClick" not in peruuta
    assert "asetaMuokkaustila(false)" not in tallenna
    assert "BtnPeruutaClick" in tallenna
    assert "BtnSalliClick" not in tallenna
    assert "asetaMuokkaustila(false)" in close
    assert "asetaMuokkaustila(true)" in lisays
    assert "naytaTiedot()" in lisays

    # F9: view -> enter edit via Salli; edit -> save via Tallenna.
    for body in (f9_grid, f9_edt):
        assert "if (sallimuokkaus)" in body
        assert "BtnTallennaClick" in body
        assert "BtnSalliClick" in body

    assert "void __fastcall asetaMuokkaustila(bool paalle);" in hdr
    print("ok salli source")


def test_docs():
    doc = open(DOC, encoding="utf-8").read()
    assert "Painike *Salli muokkaus* piilotetaan" in doc
    assert "Hakuun ja katseluun" not in doc
    print("ok salli docs")


class Form(object):
    def __init__(self, sallimuokkausvalinta=True):
        self.sallimuokkausvalinta = sallimuokkausvalinta
        self.sallimuokkaus = False
        self.Lisays = False
        self.BtnSalli_Visible = sallimuokkausvalinta
        self.BtnSalli_Caption = "Salli muokkaus"
        self.BtnPeruuta_Visible = False
        self.BtnTallenna_Visible = False
        self.BtnPaivita_Visible = True
        self.GBHaku_Visible = True
        self.focus = "EdtKilpno"
        self.kilp = "orig"
        self.kilp1 = "orig"
        self.dkilp = 1

    def naytaTiedot(self):
        self.BtnSalli_Visible = self.sallimuokkausvalinta and not self.sallimuokkaus
        self.BtnPeruuta_Visible = self.sallimuokkaus
        self.BtnTallenna_Visible = self.sallimuokkaus
        self.BtnPaivita_Visible = not self.sallimuokkaus

    def asetaMuokkaustila(self, paalle, from_salli=False):
        self.sallimuokkaus = paalle
        self.BtnSalli_Visible = self.sallimuokkausvalinta and not self.sallimuokkaus
        self.BtnPeruuta_Visible = self.sallimuokkaus
        self.BtnTallenna_Visible = self.sallimuokkaus
        self.BtnPaivita_Visible = not self.sallimuokkaus
        if self.sallimuokkaus:
            self.GBHaku_Visible = False
            if from_salli and self.focus == "BtnSalli":
                self.focus = "EdtSukunimi"
        else:
            self.BtnSalli_Caption = "Salli muokkaus"
            self.GBHaku_Visible = True

    def BtnSalliClick(self):
        if not self.sallimuokkaus:
            self.asetaMuokkaustila(True, from_salli=True)

    def BtnPeruutaClick(self):
        if self.Lisays:
            self.Lisays = False
        self.kilp = self.kilp1
        self.naytaTiedot()
        self.asetaMuokkaustila(False)

    def BtnTallennaClick(self, save_ok):
        if save_ok:
            self.kilp1 = self.kilp
            self.BtnPeruutaClick()
        self.focus = "EdtKilpno"

    def FormClose(self, yes, save_ok=True):
        if not self.sallimuokkaus:
            return "closed"
        if yes:
            if self.Lisays or self.kilp != self.kilp1:
                if not save_ok:
                    return "stayed_edit"
            self.Lisays = False
        else:
            if self.Lisays:
                self.Lisays = False
            else:
                self.kilp = self.kilp1
        self.asetaMuokkaustila(False)
        return "closed"

    def Liskilpailija(self):
        self.dkilp = 0
        self.kilp = ""
        self.kilp1 = ""
        self.Lisays = True
        self.asetaMuokkaustila(True)
        self.naytaTiedot()

    def f9(self, save_ok=True):
        if self.sallimuokkaus:
            self.BtnTallennaClick(save_ok)
        else:
            self.BtnSalliClick()

    def assert_view(self):
        assert self.sallimuokkaus is False
        assert self.BtnSalli_Visible is self.sallimuokkausvalinta
        assert self.BtnSalli_Caption == "Salli muokkaus"
        assert self.BtnPeruuta_Visible is False
        assert self.BtnTallenna_Visible is False
        assert self.BtnPaivita_Visible is True
        assert self.GBHaku_Visible is True

    def assert_edit(self):
        assert self.sallimuokkaus is True
        assert self.BtnSalli_Visible is False
        assert self.BtnPeruuta_Visible is True
        assert self.BtnTallenna_Visible is True
        assert self.BtnPaivita_Visible is False
        assert self.GBHaku_Visible is False


def test_modes():
    f = Form()
    f.assert_view()

    # 1. Salli muokkaus -> edit, button gone, search panel gone.
    f.focus = "BtnSalli"
    f.BtnSalliClick()
    f.assert_edit()
    assert f.focus == "EdtSukunimi"

    # 2. Refresh while editing must not bring Salli back.
    f.naytaTiedot()
    f.assert_edit()

    # 3. Clicking Salli again (if it were visible) must not leave edit
    #    and must not restore Kilp.
    f.kilp = "edited"
    f.BtnSalliClick()
    f.assert_edit()
    assert f.kilp == "edited"

    # 4. Peruuta restores data and returns to view with Salli visible.
    f.BtnPeruutaClick()
    f.assert_view()
    assert f.kilp == "orig"

    # 5. Tallenna success: view, data kept.
    f.BtnSalliClick()
    f.kilp = "saved"
    f.BtnTallennaClick(True)
    f.assert_view()
    assert f.kilp == "saved"
    assert f.kilp1 == "saved"

    # 6. Tallenna failure stays in edit so the error can be fixed immediately.
    f.BtnSalliClick()
    f.kilp = "fail"
    f.BtnTallennaClick(False)
    f.assert_edit()
    assert f.kilp == "fail"

    # 7. New competitor: enter edit then naytaTiedot still hides Salli.
    f = Form()
    f.Liskilpailija()
    f.assert_edit()
    assert f.Lisays is True
    f.BtnPeruutaClick()
    f.assert_view()
    assert f.Lisays is False

    # 8. Successful save of new add must not re-enter edit (Peruuta, not toggle).
    f = Form()
    f.Liskilpailija()
    f.kilp = "new"
    f.BtnTallennaClick(True)
    f.assert_view()
    assert f.Lisays is False

    # 9. FormClose Yes with failed save stays in edit, Salli still hidden.
    f = Form()
    f.BtnSalliClick()
    f.kilp = "edited"
    assert f.FormClose(True, save_ok=False) == "stayed_edit"
    f.assert_edit()

    # 10. FormClose Yes success -> view, Salli back.
    assert f.FormClose(True, save_ok=True) == "closed"
    f.assert_view()

    # 11. FormClose No discards edits, Salli back.
    f = Form()
    f.BtnSalliClick()
    f.kilp = "edited"
    assert f.FormClose(False) == "closed"
    f.assert_view()
    assert f.kilp == "orig"

    # 12. Close already in view: no change.
    assert f.FormClose(True) == "closed"
    f.assert_view()

    # 13. F9 view enters edit; F9 edit saves.
    f = Form()
    f.f9()
    f.assert_edit()
    f.kilp = "f9"
    f.f9(True)
    f.assert_view()
    assert f.kilp == "f9"

    # 14. No edit permission: Salli never visible, even after leaving edit.
    f = Form(sallimuokkausvalinta=False)
    f.assert_view()
    f.asetaMuokkaustila(True)
    f.assert_edit()
    f.asetaMuokkaustila(False)
    f.assert_view()

    print("ok salli modes")


def main():
    test_source()
    test_docs()
    test_modes()
    print("all ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
