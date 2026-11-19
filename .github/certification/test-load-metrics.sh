#!/usr/bin/env bash
set -euo pipefail

METRICS_HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$METRICS_HARNESS_DIR/load-metrics.sh"
METRICS_TEST_DIR="$(mktemp -d)"
METRICS_SERVER_PID=""
METRICS_WORK_PID=""

stop_metrics_server() {
    if [[ -n "$METRICS_WORK_PID" ]]; then
        kill "$METRICS_WORK_PID" 2>/dev/null || true
        wait "$METRICS_WORK_PID" 2>/dev/null || true
        METRICS_WORK_PID=""
    fi
    if [[ -n "$METRICS_SERVER_PID" ]]; then
        kill "$METRICS_SERVER_PID" 2>/dev/null || true
        wait "$METRICS_SERVER_PID" 2>/dev/null || true
        METRICS_SERVER_PID=""
    fi
}
cleanup_metrics_test() {
    stop_metrics_server
    rm -rf "$METRICS_TEST_DIR"
}
trap cleanup_metrics_test EXIT

start_metrics_server() {
    stop_metrics_server
    METRICS_PORT="$(python3 - <<'PY'
import socket
with socket.socket() as server:
    server.bind(('127.0.0.1', 0))
    print(server.getsockname()[1])
PY
    )"
    METRICS_LOG="$METRICS_TEST_DIR/server-$1.log"
    WEBRICK_METRICS_FIXTURE_MODE="$1" php -S "127.0.0.1:$METRICS_PORT" \
        "$METRICS_HARNESS_DIR/metrics-fixture.php" >>"$METRICS_LOG" 2>&1 &
    METRICS_SERVER_PID=$!
    for _ in $(seq 1 100); do
        if curl -fsS --max-time 0.2 "http://127.0.0.1:$METRICS_PORT/__cert/metrics" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.02
    done
    cat "$METRICS_LOG" >&2
    return 1
}

start_queued_work() {
    local before current
    before="$(grep -c METRICS_FIXTURE_WORK_STARTED "$METRICS_LOG" || true)"
    curl -fsS --max-time 5 -X POST "http://127.0.0.1:$METRICS_PORT/work" \
        >"$METRICS_TEST_DIR/work.json" 2>"$METRICS_TEST_DIR/work.stderr" &
    METRICS_WORK_PID=$!
    for _ in $(seq 1 100); do
        current="$(grep -c METRICS_FIXTURE_WORK_STARTED "$METRICS_LOG" || true)"
        if (( current > before )); then
            return 0
        fi
        sleep 0.01
    done
    echo "Queued request did not start." >&2
    return 1
}

[[ "$(load_request_timeout static)" == 2 ]]
for workload in risk_login risk_login_invalid risk_provider_slow; do
    [[ "$(load_request_timeout "$workload")" == 10 ]]
done
for invalid_timeout in 0 -1 invalid '2+2'; do
    if fetch_drained_metrics 1 "$METRICS_TEST_DIR/invalid.jsonl" "$invalid_timeout" >/dev/null 2>&1; then
        echo "Probe accepted invalid deadline $invalid_timeout." >&2
        exit 1
    fi
done

start_metrics_server healthy
start_queued_work
if fetch_drained_metrics "$METRICS_PORT" "$METRICS_TEST_DIR/ordinary.jsonl" 2 \
    >"$METRICS_TEST_DIR/ordinary.out" 2>"$METRICS_TEST_DIR/ordinary.stderr"; then
    echo "Ordinary probe incorrectly exceeded its two-second deadline." >&2
    exit 1
fi
wait "$METRICS_WORK_PID"
METRICS_WORK_PID=""
[[ "$(cat "$METRICS_TEST_DIR/work.json")" == '{"ok":true}' ]]
kill -0 "$METRICS_SERVER_PID"

start_queued_work
fetch_drained_metrics "$METRICS_PORT" "$METRICS_TEST_DIR/protected.jsonl" \
    "$(load_request_timeout risk_login)" > "$METRICS_TEST_DIR/protected.out"
wait "$METRICS_WORK_PID"
METRICS_WORK_PID=""
jq -e '.requests_active == 0 and .queued_bytes_current == 0' "$METRICS_TEST_DIR/protected.out" >/dev/null

for mode in leaked malformed; do
    start_metrics_server "$mode"
    if fetch_drained_metrics "$METRICS_PORT" "$METRICS_TEST_DIR/$mode.jsonl" 2 \
        >"$METRICS_TEST_DIR/$mode.out" 2>"$METRICS_TEST_DIR/$mode.stderr"; then
        echo "Probe accepted $mode telemetry." >&2
        exit 1
    fi
done
echo "Metrics probes preserve ordinary deadlines, wait for bounded protected work, and reject invalid or leaked state."
