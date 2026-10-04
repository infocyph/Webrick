#!/usr/bin/env bash
set -euo pipefail

HARNESS_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BATCH_HELPER="${SOAK_BATCH_HELPER:-$HARNESS_DIRECTORY/soak-http-batch.sh}"

if [[ "${1:-}" == "--probe" ]]; then
    source "$BATCH_HELPER"
    SOAK_BATCH_LOG="$(mktemp)"
    SOAK_BATCH_FAILURES="$(mktemp)"
    PAYLOAD="$(mktemp)"
    sleep 30 &
    owner_pid=$!
    trap 'kill "$owner_pid" 2>/dev/null || true; wait "$owner_pid" 2>/dev/null || true; rm -f "$SOAK_BATCH_LOG" "$SOAK_BATCH_FAILURES" "$PAYLOAD"' EXIT

    curl() {
        printf '%s\n' "$*" >> "$SOAK_BATCH_LOG"
        return "$SOAK_BATCH_CURL_EXIT"
    }

    # Model requests completing while the parent is descheduled before waiting.
    pause_before_wait=1
    set -T
    trap 'if [[ "$pause_before_wait" == 1 && "$BASH_COMMAND" == wait* ]]; then pause_before_wait=0; sleep 0.1; jobs > /dev/null; fi' DEBUG

    SOAK_BATCH_CURL_EXIT=0
    run_http_batch 25 10 GET http://fixture/success
    kill -0 "$owner_pid"
    [[ "$(wc -l < "$SOAK_BATCH_LOG")" == 25 ]]

    : > "$SOAK_BATCH_LOG"
    SOAK_BATCH_CURL_EXIT=22
    pause_before_wait=1
    if run_http_batch 25 10 POST http://fixture/failure > "$SOAK_BATCH_FAILURES" 2>&1; then
        echo 'Failed HTTP requests were accepted by the soak batch.' >&2
        exit 1
    fi
    kill -0 "$owner_pid"
    [[ "$(wc -l < "$SOAK_BATCH_LOG")" == 25 ]]
    [[ "$(awk '/--data-binary/ { count++ } END { print count }' "$SOAK_BATCH_LOG")" == 25 ]]
    [[ "$(cat "$SOAK_BATCH_FAILURES")" == 'Bounded HTTP batch recorded 25 failed request(s).' ]]
    exit 0
fi

timeout 10 "$BASH" "$0" --probe
printf 'Soak batches wait only for their own requests and preserve every failure.\n'
