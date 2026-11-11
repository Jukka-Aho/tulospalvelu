#!/bin/bash
# Vie Automaatti + rastigrafiikka jahonline-palvelimelle.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${JAHONLINE_HOST:-root@46.225.174.104}"
DEST="${JAHONLINE_DEST:-/var/www/jahonline}"
VER="20261111auto"
KEY="${JAHONLINE_SSH_KEY:-$HOME/.ssh/hetzner_key}"
SSH=(ssh -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
SCP=(scp -q -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
if [ -f "$KEY" ]; then
  SSH+=(-i "$KEY")
  SCP+=(-i "$KEY")
fi

"${SSH[@]}" "$HOST" "mkdir -p '$DEST/assets/js' '$DEST/assets/css' '$DEST/deploy/bak-automaatti-$VER'"
"${SSH[@]}" "$HOST" "cp -a '$DEST/assets/js/emit_lukija.js' '$DEST/assets/js/seuranta.js' '$DEST/assets/js/jah-pwa.js' '$DEST/assets/css/emit_lukija.css' '$DEST/assets/css/live_board.css' '$DEST/sw-jahonline.js' '$DEST/public/emit_lukija.php' '$DEST/includes/tulospalvelu.php' '$DEST/deploy/bak-automaatti-$VER/' 2>/dev/null || true"
"${SCP[@]}" \
  "$ROOT/jahonline/assets/js/emit_lukija.js" \
  "$ROOT/jahonline/assets/js/seuranta.js" \
  "$ROOT/jahonline/assets/js/jah-pwa.js" \
  "$HOST:$DEST/assets/js/"
"${SCP[@]}" \
  "$ROOT/jahonline/assets/css/emit_lukija.css" \
  "$ROOT/jahonline/assets/css/live_board.css" \
  "$HOST:$DEST/assets/css/"
"${SCP[@]}" "$ROOT/jahonline/sw-jahonline.js" "$HOST:$DEST/"

"${SSH[@]}" "$HOST" bash -s <<EOF
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
    ("TULOSPALVELU_ASSET_V = '20261024ilta2'", "TULOSPALVELU_ASSET_V = '20261111auto'"),
    ("\$emitCssV = '20261107ilmo'", "\$emitCssV = '20261111auto'"),
    (
        "in_array((\$_GET['moodi'] ?? ''), ['lahto', 'maali'], true)",
        "in_array((\$_GET['moodi'] ?? ''), ['lahto', 'maali', 'automaatti'], true)",
    ),
]
nappi_vanha = '        <button type="button" class="emit-moodi" data-moodi="maali">Leimantarkastus</button>'
nappi_uusi = nappi_vanha + '\n        <button type="button" class="emit-moodi" data-moodi="automaatti">Automaatti</button>'
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
        if p.name == "emit_lukija.php" and 'data-moodi="automaatti"' not in txt:
            txt = txt.replace(nappi_vanha, nappi_uusi)
        if txt != orig:
            p.write_text(txt, encoding="utf-8")
            changed += 1
            print("paivitetty", p)
print("tiedostoja", changed)
PY
EOF
echo "valmis $VER"
