#!/usr/bin/env bash
set -euo pipefail

HARNESS_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HARNESS_DIRECTORY/load-rss-sampler.sh"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT

rss_kb() {
    if [[ -f "$FIXTURE/running" ]]; then
        echo 100000
    else
        echo 60000
    fi
}

load_fixture() {
    touch "$FIXTURE/running"
    sleep 0.75
    rm "$FIXTURE/running"
    return "$1"
}

# A worker replacement after client close must not erase its in-load RSS peak.
run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" load_fixture 0
[[ "$(tail -n 1 "$FIXTURE/rss.txt")" == 60000 ]]
[[ "$(awk 'max < $1 { max = $1 } END { print max }' "$FIXTURE/rss.txt")" == 100000 ]]

if run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" load_fixture 22; then
    echo 'A failed load command was accepted.' >&2
    exit 1
else
    [[ "$?" == 22 ]]
fi

printf 'Load sampling retains in-load RSS peaks and the load command exit status.\n'
