#!/usr/bin/env python3
"""Kilpailijatiedot: Peruuta muutokset leaves view mode."""
import os
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SRC = os.path.join(ROOT, "TPsource", "V52", "cbHk", "UnitKilpailijatiedot.cpp")


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
    peruuta = method_body(text, "BtnPeruutaClick", "BtnTallennaClick")
    tallenna = method_body(text, "BtnTallennaClick", "PvGridExit")

    assert "Kilp = Kilp1" in peruuta
    assert "naytaTiedot()" in peruuta
    assert "asetaMuokkaustila(false)" in peruuta
    # Must not toggle via BtnSalliClick: that would turn edit back on
    # when Tallenna calls Peruuta after a successful save of a new add.
    assert "BtnSalliClick" not in peruuta
    assert "EdBtnClick" in peruuta

    assert "BtnPeruutaClick" in tallenna
    # A failed save stays in edit mode. Leaving edit happens only through
    # Peruuta, which a successful save calls.
    assert "asetaMuokkaustila(false)" not in tallenna
    assert "BtnSalliClick" not in tallenna
    assert "FocusControl(EdtKilpno)" in tallenna
    print("ok peruuta source")


def test_mode_after_actions():
    """Simulate edit-mode flag around Peruuta/Tallenna."""
    def peruuta(sallimuokkaus, lisays):
        if lisays:
            lisays = False
        sallimuokkaus = False
        return sallimuokkaus, lisays

    def tallenna(sallimuokkaus, lisays, save_ok):
        if save_ok:
            return peruuta(sallimuokkaus, lisays)
        return sallimuokkaus, lisays

    # Old existing-competitor cancel stayed in edit; now view.
    assert peruuta(True, False) == (False, False)
    # New-competitor cancel already left edit; still view.
    assert peruuta(True, True) == (False, False)
    # Successful save of existing: view (and do not toggle back on).
    assert tallenna(True, False, True) == (False, False)
    # Successful save of new add: view, Lisays cleared, not edit again.
    assert tallenna(True, True, True) == (False, False)
    # Failed save stays in edit mode so the number or field can be corrected.
    assert tallenna(True, True, False) == (True, True)
    print("ok peruuta modes")


def main():
    test_source()
    test_mode_after_actions()
    print("all ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
