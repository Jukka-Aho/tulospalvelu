#!/bin/bash
# Vie Automaatti + rastigrafiikka jahonline-palvelimelle.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${JAHONLINE_HOST:-root@46.225.174.104}"
DEST="${JAHONLINE_DEST:-/var/www/jahonline}"
VER="20261111auto"

ssh "$HOST" "mkdir -p '$DEST/assets/js' '$DEST/assets/css'"
scp -q \
  "$ROOT/jahonline/assets/js/emit_lukija.js" \
  "$ROOT/jahonline/assets/js/seuranta.js" \
  "$ROOT/jahonline/assets/js/jah-pwa.js" \
  "$HOST:$DEST/assets/js/"
scp -q \
  "$ROOT/jahonline/assets/css/emit_lukija.css" \
  "$ROOT/jahonline/assets/css/live_board.css" \
  "$HOST:$DEST/assets/css/"
scp -q "$ROOT/jahonline/sw-jahonline.js" "$HOST:$DEST/"

ssh "$HOST" bash -s <<EOF
set -euo pipefail
cd '$DEST'
python3 - <<'PY'
from pathlib import Path
repls = [
    ("emit_lukija.js?v=20261107ilmo", "emit_lukija.js?v=20261111auto"),
    ("emit_lukija.css?v=20261107ilmo", "emit_lukija.css?v=20261111auto"),
    ("seuranta.js?v=20261024ilta2", "seuranta.js?v=20261111auto"),
    ("live_board.css?v=20261024ilta2", "live_board.css?v=20261111auto"),
    ("jah-pwa.js?v=20261107ilmo", "jah-pwa.js?v=20261111auto"),
    ("jah-pwa.js?v=20261107radat", "jah-pwa.js?v=20261111auto"),
    ("sw-jahonline.js?v=20261107ilmo", "sw-jahonline.js?v=20261111auto"),
    ("jahonline-app-v12", "jahonline-app-v13"),
]
roots = [Path("public"), Path("admin"), Path("includes"), Path("assets")]
changed = 0
for root in roots:
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
            changed += 1
            print("paivitetty", p)
print("tiedostoja", changed)
PY
EOF
echo "valmis $VER"
