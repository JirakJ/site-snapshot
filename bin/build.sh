#!/bin/sh
# Builds dist/site-snapshot-<version>.zip ready for "Plugins → Add New → Upload".
# Uses `git archive`, so only committed files end up in the ZIP – never local
# tool state, editor files or anything gitignored. Usage: bin/build.sh [git-ref]
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd)
REF=${1:-HEAD}
VERSION=$(git -C "$ROOT" show "$REF:site-snapshot/site-snapshot.php" | /usr/bin/sed -n "s/^define( 'SITESNAP_VERSION', '\(.*\)' );/\1/p")
TMP=$(mktemp -d)
trap '/bin/rm -rf "$TMP"' EXIT
git -C "$ROOT" archive "$REF" site-snapshot | tar -x -C "$TMP"
for f in $(/usr/bin/find "$TMP/site-snapshot" -name '*.php'); do php -l "$f" > /dev/null; done
/bin/mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/site-snapshot-$VERSION.zip"
/bin/rm -f "$OUT"
( cd "$TMP" && /usr/bin/zip -qrX "$OUT" site-snapshot )
echo "$OUT"
