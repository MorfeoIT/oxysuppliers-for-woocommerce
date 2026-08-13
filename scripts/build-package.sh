#!/bin/bash
#
# Builds exactly what gets distributed.
#
#   bash scripts/build-package.sh [destination]
#
# What ships is what `git archive` produces — so the export-ignore rules in
# .gitattributes are the packing list — plus the one runtime dependency, which
# is installed here rather than kept in the repository. Git holds the plugin's
# own code; the box holds what the plugin needs to run.
#
# Run it on Linux. PowerShell's Compress-Archive writes path separators with a
# backslash, and WordPress then finds one file called "plugin\src\Plugin.php"
# instead of a folder.

set -e

SLUG=oxysuppliers-for-woocommerce
REPO=$(cd "$(dirname "$0")/.." && pwd)
DEST=${1:-$REPO/build}

rm -rf "$DEST"
mkdir -p "$DEST/$SLUG"

echo "Estraggo i file tracciati..."
git -C "$REPO" archive --format=tar HEAD | tar -x -C "$DEST/$SLUG"

echo "Installo la dipendenza di runtime..."
cp "$REPO/composer.json" "$REPO/composer.lock" "$DEST/$SLUG/"
(
	cd "$DEST/$SLUG"
	composer install --no-dev --classmap-authoritative --no-interaction --quiet
	rm -f composer.json composer.lock
)

echo
echo "Pronto: $DEST/$SLUG"
du -sh "$DEST/$SLUG"

# A zip, when one is wanted. Checked with unzip -l because the separators are
# the thing that goes wrong.
if command -v zip > /dev/null 2>&1; then
	( cd "$DEST" && zip -rq "$SLUG.zip" "$SLUG" )
	echo "Zip:   $DEST/$SLUG.zip"
	unzip -l "$DEST/$SLUG.zip" | head -5
fi
