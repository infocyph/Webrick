#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:?candidate root required}"
RESULT_DIR="${2:?result directory required}"
SOAK_MINUTES="${3:-30}"
MEMORY_GROWTH_MB="${4:-32}"

HARNESS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/cert-server.php"
mkdir -p "$RESULT_DIR"
METRICS="$RESULT_DIR/soak-metrics.jsonl"
PIDS="$RESULT_DIR/worker-pids.txt"
LOG="$RESULT_DIR/soak-server.log"
: > "$METRICS"
: > "$PIDS"

PAYLOAD="$RESULT_DIR/upload.bin"
head -c 32768 /dev/zero > "$PAYLOAD"

descendants() {
    local parent="$1"
    local child
    echo "$parent"
    while read -r child; do
        [[ -n "$child" ]] || continue
        descendants "$child"
    done < <(pgrep -P "$parent" 2>/dev/null || true)
}

rss_kb() {
    local pid="$1"
    local total=0
    local child
    while read -r child; do
        [[ -n "$child" ]] || continue
        local rss
        rss="$(ps -o rss= -p "$child" 2>/dev/null | tr -d ' ' || true)"
        if [[ "$rss" =~ ^[0-9]+$ ]]; then
            total=$((total + rss))
        fi
    done < <(descendants "$pid" | sort -u)
    echo "$total"
}

wait_ready() {
    for _ in $(seq 1 100); do
        if curl -fsS --max-time 1 "http://127.0.0.1:18100/cert/static" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.25
    done

    return 1
}

fetch_retry() {
    local url="$1"
    local output
    for _ in $(seq 1 40); do
        if output="$(curl -fsS --max-time 2 "$url" 2>/dev/null)"; then
            printf '%s' "$output"
            return 0
        fi
        sleep 0.1
    done

    return 1
}

sample() {
    local cycle="$1"
    local runtime metrics pid rss
    runtime="$(fetch_retry "http://127.0.0.1:18100/__cert/metrics")"
    metrics="$(fetch_retry "http://127.0.0.1:18100/cert/json")"
    pid="$(jq -r '.pid' <<<"$metrics")"
    rss="$(rss_kb "$SERVER_PID")"
    echo "$pid" >> "$PIDS"

    jq -cn         --argjson cycle "$cycle"         --argjson rss_kb "$rss"         --argjson runtime "$runtime"         --argjson worker "$metrics"         '{cycle:$cycle,rss_kb:$rss_kb,runtime:$runtime,worker:$worker}' >> "$METRICS"
}

php "$HARNESS" prepare "--root=${ROOT}"
php "$HARNESS" runwire     "--root=${ROOT}"     "--address=127.0.0.1:18100"     "--recycle=1000" >"$LOG" 2>&1 &
SERVER_PID=$!

cleanup() {
    kill -TERM "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT

if ! wait_ready; then
    cat "$LOG" >&2 || true
    exit 1
fi

sample 0
DEADLINE=$(( $(date +%s) + SOAK_MINUTES * 60 ))
CYCLE=0

while (( $(date +%s) < DEADLINE )); do
    CYCLE=$((CYCLE + 1))

    ab -k -n 1000 -c 20 "http://127.0.0.1:18100/cert/static" >/dev/null
    ab -k -n 200 -c 10 -p "$PAYLOAD" -T application/octet-stream         "http://127.0.0.1:18100/cert/upload" >/dev/null

    for _ in $(seq 1 10); do
        curl -fsS --max-time 0.03 "http://127.0.0.1:18100/cert/slow/250" >/dev/null 2>&1 || true
    done

    for _ in $(seq 1 10); do
        [[ "$(fetch_retry "http://127.0.0.1:18100/cert/stream" | wc -c | tr -d ' ')" == "3072" ]]
    done

    [[ "$(fetch_retry "http://127.0.0.1:18100/cert/static")" == "webrick-cert-ok" ]]
    sample "$CYCLE"
done

sleep 1
sample $((CYCLE + 1))

FIRST_MEMORY="$(jq -s '.[0].runtime.memory_current_bytes // 0' "$METRICS")"
LAST_MEMORY="$(jq -s '.[-1].runtime.memory_current_bytes // 0' "$METRICS")"
FIRST_RSS="$(jq -s '.[0].rss_kb // 0' "$METRICS")"
LAST_RSS="$(jq -s '.[-1].rss_kb // 0' "$METRICS")"
FINAL_ACTIVE="$(jq -s '.[-1].runtime.requests_active // 0' "$METRICS")"
FINAL_QUEUE="$(jq -s '.[-1].runtime.queued_bytes_current // 0' "$METRICS")"
REJECTED="$(jq -s '[.[].runtime.rejected_requests_total // 0] | max // 0' "$METRICS")"
DISTINCT_PIDS="$(sort -u "$PIDS" | sed '/^$/d' | wc -l | tr -d ' ')"

MAX_GROWTH_BYTES=$((MEMORY_GROWTH_MB * 1024 * 1024))
if (( LAST_MEMORY > FIRST_MEMORY + MAX_GROWTH_BYTES )); then
    echo "Runtime memory growth exceeded ${MEMORY_GROWTH_MB} MiB." >&2
    exit 1
fi
if (( LAST_RSS > FIRST_RSS + MEMORY_GROWTH_MB * 1024 )); then
    echo "Process-tree RSS growth exceeded ${MEMORY_GROWTH_MB} MiB." >&2
    exit 1
fi
if (( FINAL_ACTIVE > 1 )); then
    echo "Requests remained active after soak cooldown: $FINAL_ACTIVE" >&2
    exit 1
fi
if (( FINAL_QUEUE != 0 )); then
    echo "Queued bytes remained after soak cooldown: $FINAL_QUEUE" >&2
    exit 1
fi
if (( REJECTED != 0 )); then
    echo "Runwire rejected requests during soak: $REJECTED" >&2
    exit 1
fi
if (( DISTINCT_PIDS < 2 )); then
    echo "Worker replacement was not observed; distinct worker PIDs: $DISTINCT_PIDS" >&2
    exit 1
fi

START_STOP="$(date +%s)"
kill -TERM "$SERVER_PID"
for _ in $(seq 1 120); do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        wait "$SERVER_PID" 2>/dev/null || true
        break
    fi
    sleep 0.25
done
if kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "Runwire did not drain within 30 seconds." >&2
    exit 1
fi
STOP_SECONDS=$(( $(date +%s) - START_STOP ))
trap - EXIT

cat > "$RESULT_DIR/soak-summary.md" <<EOF
# Persistent worker soak

- Duration: ${SOAK_MINUTES} minutes
- Cycles: ${CYCLE}
- Distinct worker PIDs: ${DISTINCT_PIDS}
- Runtime memory: ${FIRST_MEMORY} → ${LAST_MEMORY} bytes
- Process-tree RSS: ${FIRST_RSS} → ${LAST_RSS} KiB
- Final active requests: ${FINAL_ACTIVE}
- Final queued bytes: ${FINAL_QUEUE}
- Rejected requests: ${REJECTED}
- Graceful drain: ${STOP_SECONDS} seconds
EOF
