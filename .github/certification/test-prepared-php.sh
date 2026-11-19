#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT
HELPER="$SCRIPT_DIR/prepared-php.sh"
bash "$HELPER" capture "$SCRATCH/captured"

# Relocation includes spaces and an ampersand to exercise literal INI paths.
RESTORED="$SCRATCH/relocated runtime & modules"
cp -R -- "$SCRATCH/captured" "$RESTORED"
bash "$HELPER" activate "$RESTORED"
INFO="$(PHPRC="$RESTORED/php.ini" PHP_INI_SCAN_DIR="$RESTORED/ini" "$RESTORED/bin/php" -r '
    $modules = get_loaded_extensions(); sort($modules);
    echo json_encode(["version"=>PHP_VERSION,"modules"=>$modules,"binary"=>PHP_BINARY,
        "extensions"=>ini_get("extension_dir"),"ini"=>php_ini_loaded_file()], JSON_THROW_ON_ERROR);')"
jq -e --arg root "$RESTORED" --argjson expected "$(cat "$RESTORED/manifest.json")" '
    .version == $expected.version and .modules == $expected.modules
    and .binary == ($root + "/bin/php") and .extensions == ($root + "/extensions")
    and .ini == ($root + "/php.ini")
' <<<"$INFO" >/dev/null
CHILD="$(jq -r '.binary' <<<"$INFO")"
[[ "$(PHPRC="$RESTORED/php.ini" PHP_INI_SCAN_DIR="$RESTORED/ini" "$CHILD" -r 'echo PHP_VERSION;')" == "$(jq -r '.version' <<<"$INFO")" ]]
CHILD_MODULES="$(PHPRC="$RESTORED/php.ini" PHP_INI_SCAN_DIR="$RESTORED/ini" "$CHILD" -r '$modules=get_loaded_extensions(); sort($modules); echo json_encode($modules, JSON_THROW_ON_ERROR);')"
jq -e --argjson expected "$INFO" '. == $expected.modules' <<<"$CHILD_MODULES" >/dev/null

# Activation must use the captured executable even if PATH selects another PHP.
mkdir "$SCRATCH/host-bin"
printf '#!/bin/sh\necho incompatible-host-PHP\n' > "$SCRATCH/host-bin/php"
chmod +x "$SCRATCH/host-bin/php"
PATH="$SCRATCH/host-bin:$PATH" bash "$HELPER" activate "$RESTORED"

cp -R -- "$SCRATCH/captured" "$SCRATCH/changed-binary"
printf 'not the captured executable' > "$SCRATCH/changed-binary/bin/php"
if bash "$HELPER" activate "$SCRATCH/changed-binary" > "$SCRATCH/output" 2>&1; then
    echo 'Accepted a changed PHP executable.' >&2
    exit 1
fi
grep -q 'captured identity' "$SCRATCH/output"

cp -R -- "$SCRATCH/captured" "$SCRATCH/wrong-version"
jq '.version = "8.5.999"' "$SCRATCH/wrong-version/manifest.json" > "$SCRATCH/manifest.json"
mv -- "$SCRATCH/manifest.json" "$SCRATCH/wrong-version/manifest.json"
if bash "$HELPER" activate "$SCRATCH/wrong-version" > "$SCRATCH/output" 2>&1; then
    echo 'Accepted a different PHP patch identity.' >&2
    exit 1
fi
grep -q 'version or loaded modules differ' "$SCRATCH/output"

cp -R -- "$SCRATCH/captured" "$SCRATCH/missing-modules"
rm -rf "$SCRATCH/missing-modules/extensions"
mkdir "$SCRATCH/missing-modules/extensions"
if bash "$HELPER" activate "$SCRATCH/missing-modules" > "$SCRATCH/output" 2>&1; then
    echo 'Accepted missing extension modules.' >&2
    exit 1
fi
echo 'Five prepared-runtime checks passed: relocation/child PHP, host selection, executable identity, patch identity and missing modules.'
