#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/soak-metrics.sh"
SOAK_METRICS_TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$SOAK_METRICS_TEST_ROOT"' EXIT
EVENTS="$SOAK_METRICS_TEST_ROOT/events.jsonl"
EVIDENCE="$SOAK_METRICS_TEST_ROOT/samples.jsonl"
COUNT="$SOAK_METRICS_TEST_ROOT/count"
printf '%s\n' \
    '{"type":"worker_ready","pid":11}' \
    '{"type":"worker_ready","pid":22}' > "$EVENTS"

fetch_metrics() {
    local count pid queue=0 active=1 rejected=0 backlog=0
    count="$(cat "$COUNT" 2>/dev/null || echo 0)"
    count=$((count + 1))
    echo "$count" > "$COUNT"
    pid=$((count % 2 == 1 ? 11 : 22))
    case "$MODE" in
        stale) (( count <= 4 )) && queue=32768 ;;
        delayed_leak) (( count > 4 && pid == 22 )) && queue=32768 ;;
        queue) (( pid == 22 )) && queue=32768 ;;
        active) (( pid == 22 )) && active=2 ;;
        backlog) (( pid == 22 )) && backlog=1 ;;
        missing) pid=11 ;;
        unknown) pid=33 ;;
        rejected) rejected=1 ;;
        malformed) echo '{}'; return ;;
        unavailable) return 1 ;;
    esac
    jq -cn --argjson pid "$pid" --argjson queue "$queue" \
        --argjson active "$active" --argjson rejected "$rejected" --argjson backlog "$backlog" \
        '{pid:$pid,queued_bytes_current:$queue,deferred_backlog:$backlog,
          requests_active:$active,rejected_requests_total:$rejected}'
}

MODE=stale
fetch_drained_soak_workers 0 "$EVENTS" "$EVIDENCE" > "$SOAK_METRICS_TEST_ROOT/result.json"
jq -e 'length == 2 and all(.[]; .queued_bytes_current == 0)' "$SOAK_METRICS_TEST_ROOT/result.json" >/dev/null
jq -e -s 'length == 6 and .[0].queued_bytes_current == 32768 and .[-1].queued_bytes_current == 0' "$EVIDENCE" >/dev/null

for MODE in queue delayed_leak active backlog missing unknown rejected malformed unavailable; do
    rm -f "$COUNT"
    if fetch_drained_soak_workers 0 "$EVENTS" "$EVIDENCE" > "$SOAK_METRICS_TEST_ROOT/result.json" 2> "$SOAK_METRICS_TEST_ROOT/error"; then
        echo "Soak drain check accepted $MODE worker evidence." >&2
        exit 1
    fi
done

for invalid in missing empty malformed one_worker; do
    case "$invalid" in
        missing) rm -f "$EVENTS" ;;
        empty) : > "$EVENTS" ;;
        malformed) echo '{' > "$EVENTS" ;;
        one_worker) echo '{"type":"worker_ready","pid":11}' > "$EVENTS" ;;
    esac
    if fetch_drained_soak_workers 0 "$EVENTS" "$EVIDENCE" >/dev/null 2>&1; then
        echo "Soak drain check accepted $invalid lifecycle evidence." >&2
        exit 1
    fi
done

# Recycled workers no longer qualify the final two-worker topology.
printf '%s\n' \
    '{"type":"worker_ready","pid":1}' \
    '{"type":"worker_ready","pid":11}' \
    '{"type":"worker_exited","pid":1}' \
    '{"type":"worker_ready","pid":22}' > "$EVENTS"
MODE=stale
rm -f "$COUNT"
fetch_drained_soak_workers 0 "$EVENTS" "$EVIDENCE" >/dev/null
echo 'Soak telemetry accepts refreshed samples from both workers and rejects persistent leaks, missing workers and invalid evidence.'
