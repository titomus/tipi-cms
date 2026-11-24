#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$1"
[[ "$TARGET" = /* && -d "$TARGET" && -f "$TARGET/index.php" && ! -L "$TARGET/index.php" ]] || { echo "Installation existante introuvable"; exit 1; }
TARGET="$(cd "$TARGET" && pwd -P)"
grep -q 'const TIPI_VERSION' "$TARGET/index.php" || { echo "Ce fichier ne semble pas être Tipi CMS"; exit 1; }
grep -q 'const TIPI_SETUP_HASH' "$TARGET/index.php" || exit 1
php -l "$HERE/tipi-install.php"
BACKUPS="$HOME/.tipi-cms-updates"
umask 077
mkdir -p "$BACKUPS"
SAVE="$(mktemp -d "$BACKUPS/update-XXXXXXXX")"
cp -p "$TARGET/index.php" "$SAVE/index.php"
TMP="$(mktemp "$TARGET/.tipi-update-XXXXXXXX.php")"
trap 'rm -f "$TMP"' EXIT
cp "$HERE/tipi-install.php" "$TMP"
php -l "$TMP"
chmod --reference="$TARGET/index.php" "$TMP"
mv -f "$TMP" "$TARGET/index.php"
echo "Noyau mis à jour. Aucune donnée éditoriale modifiée."
echo "Sauvegarde privée : $SAVE/index.php"
echo "Rollback : cp -p '$SAVE/index.php' '$TARGET/index.php'"
