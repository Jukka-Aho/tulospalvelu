#!/bin/bash
# Vie Automaatti-kioski + rastigrafiikan valinta jahonline-palvelimelle.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${JAHONLINE_HOST:-root@46.225.174.104}"
DEST="${JAHONLINE_DEST:-/var/www/jahonline}"
VER="20261112asema"
KEY="${JAHONLINE_SSH_KEY:-$HOME/.ssh/hetzner_key}"
SSH=(ssh -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
SCP=(scp -q -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
if [ -f "$KEY" ]; then
  SSH+=(-i "$KEY")
  SCP+=(-i "$KEY")
fi

"${SSH[@]}" "$HOST" "mkdir -p '$DEST/assets/js' '$DEST/assets/css' '$DEST/public' '$DEST/deploy/bak-kioski-$VER'"
"${SSH[@]}" "$HOST" "cp -a '$DEST/assets/js/emit_lukija.js' '$DEST/assets/js/seuranta.js' '$DEST/assets/js/jah-pwa.js' '$DEST/assets/css/emit_lukija.css' '$DEST/assets/css/live_board.css' '$DEST/sw-jahonline.js' '$DEST/public/emit_lukija.php' '$DEST/includes/admin_nav.php' '$DEST/includes/helpers.php' '$DEST/includes/iltarasti.php' '$DEST/includes/tulospalvelu.php' '$DEST/deploy/bak-kioski-$VER/' 2>/dev/null || true"
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
"${SCP[@]}" "$ROOT/jahonline/public/automaatti.php" "$HOST:$DEST/public/automaatti.php"
"${SCP[@]}" "$ROOT/jahonline/patch_automaatti_sivu.py" "$HOST:/tmp/patch_automaatti_sivu.py"
"${SSH[@]}" "$HOST" "python3 /tmp/patch_automaatti_sivu.py; chown www-data:www-data '$DEST/public/automaatti.php'"
echo "valmis $VER"
