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
# composer.json and composer.lock stay in the package on purpose. A plugin that
# ships a vendor/ directory without saying what is in it is a plugin a reviewer
# has to take on trust — and Plugin Check says so out loud.
cp "$REPO/composer.json" "$REPO/composer.lock" "$DEST/$SLUG/"
(
	cd "$DEST/$SLUG"
	composer install --no-dev --classmap-authoritative --no-interaction --quiet
)

echo "Tolgo i font che il documento non usa..."
# Dompdf ships three DejaVu families and the document uses one. The serif and
# monospace families are 3.4 MB nobody downloads on purpose.
#
# DejaVu Sans STAYS: it is what carries the accents, and a supplier called
# "Però" coming out as "Per" would be worse than a bigger download. A theme
# that overrides the template and asks for a serif face will get one of the
# built-in PDF fonts instead, which covers Latin but not Greek or Cyrillic.
FONTS="$DEST/$SLUG/vendor/dompdf/dompdf/lib/fonts"

if [ -d "$FONTS" ]; then
	rm -f "$FONTS"/DejaVuSerif* "$FONTS"/DejaVuSansMono*
fi

echo
echo "Pronto: $DEST/$SLUG"
du -sh "$DEST/$SLUG"

# The one font that must survive the trimming above.
if [ ! -f "$FONTS/DejaVuSans.ttf" ]; then
	echo "ERRORE: manca DejaVu Sans, gli accenti non avrebbero di che disegnarsi" >&2
	exit 1
fi

# A zip, when one is wanted. Checked with unzip -l because the separators are
# the thing that goes wrong.
if command -v zip > /dev/null 2>&1; then
	( cd "$DEST" && zip -rq "$SLUG.zip" "$SLUG" )
	echo "Zip:   $DEST/$SLUG.zip"
	unzip -l "$DEST/$SLUG.zip" | head -5
fi
