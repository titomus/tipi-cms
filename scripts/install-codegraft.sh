#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "$1" && pwd -P)"
ORIGIN="$2"
PIN=e128e4bb045df11111729a4b13724976f40b7cdc
[[ -f "$ROOT/public/index.php" && ! -L "$ROOT/public/index.php" ]] || { echo "Installation Tipi CMS absente"; exit 1; }
grep -q "const TIPI_VERSION = '0.3.0-preview'" "$ROOT/public/index.php" || { echo "Mettre à jour Tipi CMS en 0.3 avant CodeGraft"; exit 1; }
[[ "$ORIGIN" =~ ^https://[a-z0-9][a-z0-9.-]+$ ]] || exit 1
php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' || { echo "Activer pdo_sqlite (CLI et Web)"; exit 1; }
[[ ! -e "$ROOT/tipi-system" && ! -L "$ROOT/tipi-system" && ! -L "$ROOT/app" && ! -L "$ROOT/app/McpActions" ]] || { echo "Système existant : ne pas écraser"; exit 1; }
umask 077
WORK="$(mktemp -d)"
STAGE="$(mktemp -d "$ROOT/.tipi-system-stage-XXXXXXXX")"
trap 'rm -rf "$WORK" "$STAGE"' EXIT
# Authenticated GitHub clone using your existing credentials, no token in the CMS.
git clone --quiet --no-checkout https://github.com/titomus/CodeGraft-Framework.git "$WORK/framework"
git -C "$WORK/framework" checkout --quiet --detach "$PIN"
test "$(git -C "$WORK/framework" rev-parse HEAD)" = "$PIN"
cp -R "$WORK/framework/codegraft" "$STAGE/codegraft"
test ! -e "$STAGE/codegraft/standalone/config.php"
mkdir "$STAGE/contracts"
for f in Http.php ActionSchema.php Actions.php Data.php; do cp "$WORK/framework/src/$f" "$STAGE/contracts/$f"; done
cp "$HERE/integration/bridge.php" "$STAGE/bridge.php"
printf '%s\n' '<?php require_once __DIR__."/codegraft/bootstrap.php";' >"$STAGE/bootstrap.php"
find "$STAGE" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
mkdir -p "$ROOT/app/McpActions"
VAULT="$HOME/.tipi-cms-codegraft/$(printf '%s' "$ROOT" | sha256sum | cut -c1-24)"
[[ ! -e "$VAULT" && ! -L "$VAULT" ]] || { echo "Coffre existant, arrêt sans écrasement"; exit 1; }
read -r -s -p "Nouveau mot de passe CodeGraft (16 à 72 caractères) : " PW; echo
read -r -s -p "Confirmer : " PW2; echo
[[ "$PW" = "$PW2" && "$(printf '%s' "$PW" | wc -c)" -ge 16 && "$(printf '%s' "$PW" | wc -c)" -le 72 ]] || { echo "Mot de passe invalide"; exit 1; }
mv -T "$STAGE" "$ROOT/tipi-system"
if ! printf '%s\n' "$PW" | php "$HERE/integration/init-codegraft.php" "$ROOT" "$VAULT" "$ORIGIN"; then
  unset PW PW2
  echo "Initialisation incomplète : conserver les fichiers et le coffre pour diagnostic ; aucune donnée du CMS modifiée."
  exit 1
fi
unset PW PW2
if [[ ! -e "$ROOT/app/McpActions/Contacts.php" ]]; then cp -n "$HERE/examples/Contacts.php" "$ROOT/app/McpActions/Contacts.php"; fi
echo "MCP OAuth : $ORIGIN/codegraft-api/"
echo "Admin CodeGraft : $ORIGIN/codegraft/standalone/admin.php"
echo "Approbations métier : $ORIGIN/admin?connections=1"
echo "Le module exemple Contacts reste inactif tant que le propriétaire ne l'approuve pas."
