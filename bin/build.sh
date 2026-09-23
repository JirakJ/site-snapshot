#!/bin/sh
# Builds dist/site-snapshot-<version>.zip ready for "Plugins → Add New → Upload".
set -eu
ROOT=$(dirname "$0")/..
VERSION=$(/usr/bin/sed -n "s/^define( 'SITESNAP_VERSION', '\(.*\)' );/\1/p" "$ROOT/site-snapshot/site-snapshot.php")
for f in $(/usr/bin/find "$ROOT/site-snapshot" -name '*.php'); do php -l "$f" > /dev/null; done
/bin/mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/site-snapshot-$VERSION.zip"
/bin/rm -f "$OUT"
( cd "$ROOT" && /usr/bin/zip -qr "dist/site-snapshot-$VERSION.zip" site-snapshot -x '*.DS_Store' )
echo "$OUT"
