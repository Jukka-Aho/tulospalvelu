#!/usr/bin/env python3
"""ViestiWin must not see the 3-arg HK ApiIlmoitaTapahtuma (bcc32 E2227)."""
import os
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
CBHK = os.path.join(ROOT, "TPsource", "V52", "cbHk")
VWIN = os.path.join(ROOT, "TPsource", "V52", "ViestiWin")


def read(path):
    return open(path, encoding="utf-8", errors="replace").read()


def test_headers():
    integ = read(os.path.join(CBHK, "ApiIntegration.h"))
    integ_cpp = read(os.path.join(CBHK, "ApiIntegration.cpp"))
    hk = read(os.path.join(CBHK, "ApiHkIntegration.h"))
    vi = read(os.path.join(VWIN, "ApiVIntegration.h"))
    hk_saike = read(os.path.join(CBHK, "ApiSaike.h"))
    vi_saike = read(os.path.join(VWIN, "ApiSaike.h"))

    assert '#include "ApiSaike.h"' not in integ
    assert "class TApiSaike;" in integ
    assert '#include "ApiSaike.h"' in integ_cpp
    assert '#include "ApiSaike.h"' in hk
    assert '#include "ApiSaike.h"' in vi

    saike_pos = vi.find('#include "ApiSaike.h"')
    integ_pos = vi.find('#include "ApiIntegration.h"')
    assert saike_pos >= 0 and integ_pos >= 0
    assert saike_pos < integ_pos

    assert "void ApiIlmoitaTapahtuma(int kilpno, int piste, int aikaSec);" in hk_saike
    assert "void ApiIlmoitaTapahtuma(int kilpno, int osuus, int piste, int aikaSec);" in vi_saike
    assert "ApiIlmoitaTapahtuma(kilpno, osuus, piste, aikaSec)" in vi
    assert "ApiIlmoitaTapahtuma(kilpno, piste, aikaSec)" in hk
    print("ok headers")


def compile_snippet(include_first, include_second, call, expect_ok):
    """bcc32 looks in the including file's directory; same-name headers share a guard."""
    d = tempfile.mkdtemp(prefix="api_ilmoita_")
    open(os.path.join(d, "hk.h"), "w").write(
        "#ifndef ApiSaikeH\n#define ApiSaikeH\n"
        "void ApiIlmoitaTapahtuma(int, int, int);\n#endif\n"
    )
    open(os.path.join(d, "vi.h"), "w").write(
        "#ifndef ApiSaikeH\n#define ApiSaikeH\n"
        "void ApiIlmoitaTapahtuma(int, int, int, int);\n#endif\n"
    )
    src = os.path.join(d, "call.cpp")
    open(src, "w").write(
        '#include "%s"\n#include "%s"\nvoid f() { %s }\n'
        % (include_first, include_second, call)
    )
    r = subprocess.run(
        ["g++", "-fsyntax-only", "-x", "c++", src],
        cwd=d, stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    ok = r.returncode == 0
    if ok != expect_ok:
        raise AssertionError(
            "compile %s then %s expected_ok=%s got %s\n%s"
            % (include_first, include_second, expect_ok, ok,
               r.stderr.decode("utf-8", "replace"))
        )


def test_include_order_compile():
    four = "ApiIlmoitaTapahtuma(1, 2, 3, 4);"
    three = "ApiIlmoitaTapahtuma(1, 2, 3);"
    # Old Viesti include order: shared header pulled HK 3-arg first.
    compile_snippet("hk.h", "vi.h", four, False)
    compile_snippet("hk.h", "vi.h", three, True)
    # Fixed order: Viesti 4-arg first, HK header skipped by include guard.
    compile_snippet("vi.h", "hk.h", four, True)
    print("ok include order compile")


def test_numerot_selaimesta():
    hk = read(os.path.join(CBHK, "ApiSaike.cpp"))
    vi = read(os.path.join(VWIN, "ApiSaike.cpp"))
    hdr = read(os.path.join(CBHK, "ApiYhteydet.h"))
    assert "numerotSelaimesta" in hdr
    assert 'tyyppi":"leima"' in hk.replace('\\"', '"')
    assert 'tyyppi":"leima"' in vi.replace('\\"', '"')
    assert "if (comfl)" in read(os.path.join(CBHK, "..", "Hk", "HkDat.cpp")).replace("\r", "")
    print("ok numerot selaimesta")


def main():
    test_headers()
    test_numerot_selaimesta()
    test_include_order_compile()
    print("all ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
