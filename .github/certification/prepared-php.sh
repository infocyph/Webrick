#!/usr/bin/env bash
set -euo pipefail

php_information() {
    "$1" -r '
    declare(strict_types=1);
    $modules = get_loaded_extensions();
    sort($modules);
    echo json_encode([
        "version" => PHP_VERSION, "binary" => PHP_BINARY,
        "extensions" => ini_get("extension_dir"), "ini" => php_ini_loaded_file(),
        "scanned" => php_ini_scanned_files(), "modules" => $modules,
    ], JSON_THROW_ON_ERROR);'
}

relocate_ini() {
    local source="$1" destination="$2" previous="$3" current="$4" content
    content="$(cat "$source")"
    printf '%s\n' "${content//"$previous"/"$current"}" > "$destination"
}

activate_runtime() {
    local root="$1" manifest actual checksum previous quoted source
    manifest="$(cat "$root/manifest.json")"
    checksum="$(sha256sum "$root/bin/php" | cut -d ' ' -f 1)"
    if [[ "$checksum" != "$(jq -er '.binary_sha256' <<<"$manifest")" ]]; then
        echo 'Prepared PHP executable does not match its captured identity.' >&2
        return 1
    fi
    previous="$(jq -er '.extensions' <<<"$manifest")"
    relocate_ini "$root/original-php.ini" "$root/php.ini" "$previous" "$root/extensions"
    quoted="${root//\\/\\\\}/extensions"
    quoted="${quoted//\"/\\\"}"
    printf '\nextension_dir="%s"\n' "$quoted" >> "$root/php.ini"
    mkdir -p "$root/ini"
    for source in "$root/original-ini/"*.ini; do
        [[ -f "$source" ]] || continue
        relocate_ini "$source" "$root/ini/${source##*/}" "$previous" "$root/extensions"
    done
    actual="$(PHPRC="$root/php.ini" PHP_INI_SCAN_DIR="$root/ini" php_information "$root/bin/php")"
    if ! jq -e --argjson expected "$manifest" '
        .version == $expected.version and .modules == $expected.modules
    ' <<<"$actual" >/dev/null; then
        echo 'Prepared PHP version or loaded modules differ from dependency preparation.' >&2
        return 1
    fi
    local fpm_checksum
    fpm_checksum="$(jq -r '.fpm_sha256 // empty' <<<"$manifest")"
    if [[ -n "$fpm_checksum" ]]; then
        if [[ "$(sha256sum "$root/bin/php-fpm" | cut -d ' ' -f 1)" != "$fpm_checksum" ]]; then
            echo 'Prepared PHP-FPM executable does not match its captured identity.' >&2
            return 1
        fi
        local fpm_details fpm_modules
        fpm_details="$(PHPRC="$root/php.ini" PHP_INI_SCAN_DIR="$root/ini" "$root/bin/php-fpm" -v)"
        fpm_modules="$(PHPRC="$root/php.ini" PHP_INI_SCAN_DIR="$root/ini" "$root/bin/php-fpm" -m)"
        if [[ "$fpm_details" != "PHP $(jq -er '.version' <<<"$manifest") (fpm-fcgi)"* ]] \
            || ! grep -Fxq redis <<<"$fpm_modules" || ! grep -Fxq 'Zend OPcache' <<<"$fpm_modules"; then
            echo 'Prepared PHP-FPM version, Redis or OPcache did not match preparation.' >&2
            return 1
        fi
    fi
    jq -r '"Verified prepared PHP \(.version) with \(.modules | length) loaded modules"' <<<"$actual"
}

capture_runtime() {
    local root="$1" information binary extensions ini filename target checksum index=0
    local fpm="${2:-}" fpm_checksum=''
    information="$(php_information "$(command -v php)")"
    binary="$(jq -er '.binary' <<<"$information")"
    extensions="$(jq -er '.extensions' <<<"$information")"
    mkdir -p "${root%/*}"
    mkdir "$root" "$root/bin" "$root/original-ini" "$root/extensions"
    cp --preserve=mode -- "$binary" "$root/bin/php"
    cp -RL -- "$extensions/." "$root/extensions/"
    ini="$(jq -r '.ini // empty' <<<"$information")"
    if [[ -n "$ini" ]]; then
        cp -- "$ini" "$root/original-php.ini"
    else
        : > "$root/original-php.ini"
    fi
    while IFS= read -r filename; do
        printf -v target '%s/original-ini/%04d.ini' "$root" "$index"
        cp -- "$filename" "$target"
        index=$((index + 1))
    done < <(jq -r '(.scanned // "") | split(",")[] | gsub("^[[:space:]]+|[[:space:]]+$"; "") | select(length > 0)' <<<"$information")
    checksum="$(sha256sum "$root/bin/php" | cut -d ' ' -f 1)"
    if [[ -n "$fpm" ]]; then
        cp --preserve=mode -- "$fpm" "$root/bin/php-fpm"
        fpm_checksum="$(sha256sum "$root/bin/php-fpm" | cut -d ' ' -f 1)"
    fi
    jq --arg checksum "$checksum" --arg fpm "$fpm_checksum" '
        . + {binary_sha256:$checksum} + (if $fpm == "" then {} else {fpm_sha256:$fpm} end)
    ' <<<"$information" > "$root/manifest.json"
    activate_runtime "$root"
}

if (( $# < 2 || $# > 3 )) || [[ "$1" == activate && $# != 2 ]]; then
    echo 'Usage: prepared-php.sh capture directory [php-fpm] | activate directory' >&2
    exit 64
fi
PREPARED_PHP_ROOT="$(realpath -m -- "$2")"
case "$1" in
    capture) capture_runtime "$PREPARED_PHP_ROOT" "${3:-}" ;;
    activate) activate_runtime "$PREPARED_PHP_ROOT" ;;
    *) echo 'Expected capture or activate.' >&2; exit 64 ;;
esac
