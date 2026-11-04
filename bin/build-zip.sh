#!/usr/bin/env bash
# Builds dist/ean-isbn-barcodes-<version>.zip for installing in WordPress.
# The ZIP contains the folder "ean-isbn-barcodes/" and leaves out everything in .distignore.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="ean-isbn-barcodes"
cd "$ROOT"

VERSION="$(sed -n "s/^define( 'EANBC_VERSION', '\(.*\)' );/\1/p" "$SLUG.php")"
if [ -z "$VERSION" ]; then
	echo "Version not found in $SLUG.php" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
cleanup() { rm -r -- "${STAGE:?}"; }
trap cleanup EXIT

mkdir -p "$STAGE/$SLUG" "$ROOT/dist"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/$SLUG/"

ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f -- "$ZIP"
(cd "$STAGE" && zip -r -X -q "$ZIP" "$SLUG")
echo "$ZIP"
