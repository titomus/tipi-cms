#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export HOME="$TMP/home"
mkdir -p "$HOME" "$TMP/site/public" "$TMP/no-network"
cp "$HERE/tipi-install.php" "$TMP/site/public/index.php"
for tool in git curl wget composer; do printf '#!/bin/sh\nexit 99\n' >"$TMP/no-network/$tool"; chmod +x "$TMP/no-network/$tool"; done
export PATH="$TMP/no-network:$PATH"
php "$HERE/scripts/verify-codegraft.php"
printf '%s\n' 'test-password-123456789' 'test-password-123456789' | bash "$HERE/scripts/install-codegraft.sh" "$TMP/site" https://cms.example.test
test -f "$TMP/site/tipi-system/codegraft/standalone/config.php"
test ! -e "$TMP/site/public/codegraft"
cmp "$HERE/tipi-install.php" "$TMP/site/public/index.php"
php "$HERE/tests/business.php" "$TMP/site"
echo "Bundled installation OK"
