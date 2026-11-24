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
SYSTEM="$(dirname "$TARGET")/tipi-system"
# Update only the integration adapter; preserve all OAuth state and modules.
if [[ -e "$SYSTEM" ]]; then
  [[ -d "$SYSTEM" && ! -L "$SYSTEM" && -f "$SYSTEM/bridge.php" && ! -L "$SYSTEM/bridge.php" ]] || { echo "Intégration existante invalide"; exit 1; }
  php -l "$HERE/integration/bridge.php"
  php "$HERE/integration/install-extensions.php" "$(dirname "$TARGET")"
  cp -p "$SYSTEM/bridge.php" "$SAVE/bridge.php"
  BRIDGE_TMP="$(mktemp "$SYSTEM/.bridge-update-XXXXXXXX.php")"
  trap 'rm -f "$TMP" "$BRIDGE_TMP"' EXIT
  cp "$HERE/integration/bridge.php" "$BRIDGE_TMP"
  chmod --reference="$SYSTEM/bridge.php" "$BRIDGE_TMP"
  mv -f "$BRIDGE_TMP" "$SYSTEM/bridge.php"
fi
if ! mv -f "$TMP" "$TARGET/index.php"; then
  if [[ -f "$SAVE/bridge.php" ]]; then cp -p "$SAVE/bridge.php" "$SYSTEM/bridge.php"; fi
  exit 1
fi
if [[ -f "$SAVE/bridge.php" ]]; then
  php "$HERE/integration/migrate-endpoint.php" "$(dirname "$TARGET")" "$SAVE"
fi
if [[ -f "$SAVE/bridge.php" ]]; then echo "Rollback intégration : cp -p '$SAVE/bridge.php' '$SYSTEM/bridge.php'"; fi
echo "Noyau mis à jour. Aucune donnée éditoriale modifiée."
echo "Sauvegarde privée : $SAVE/index.php"
echo "Rollback : cp -p '$SAVE/index.php' '$TARGET/index.php'"
