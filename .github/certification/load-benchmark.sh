#!/usr/bin/env bash
set -euo pipefail

BASELINE_ROOT="${1:?baseline root required}"
CANDIDATE_ROOT="${2:?candidate root required}"
RESULT_DIR="${3:?result directory required}"
DURATION_SECONDS="${4:-5}"
TRIALS="${5:-3}"

HARNESS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/cert-server.php"
mkdir -p "$RESULT_DIR"
RESULTS="$RESULT_DIR/load-results.jsonl"
: > "$RESULTS"
CURRENT_PID=""

cleanup_current() {
    if [[ -n "$CURRENT_PID" ]] && kill -0 "$CURRENT_PID" 2>/dev/null; then
        stop_server "$CURRENT_PID" || true
    fi
}
trap cleanup_current EXIT

PAYLOAD="$RESULT_DIR/upload.bin"
head -c 16384 /dev/zero > "$PAYLOAD"
POST_SCRIPT="$RESULT_DIR/post.lua"
cat > "$POST_SCRIPT" <<'LUA'
wrk.method = "POST"
wrk.body = string.rep("x", 16384)
wrk.headers["Content-Type"] = "application/octet-stream"
LUA

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
    local url="$1"
    for _ in $(seq 1 80); do
        if curl -fsS --max-time 1 "$url/cert/static" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.25
    done

    return 1
}

start_server() {
    local root="$1"
    local mode="$2"
    local port="$3"
    local label="$4"
    local log="$RESULT_DIR/${label}-${mode}.log"

    if [[ "$mode" == "sapi" ]]; then
        WEBRICK_CERT_ROOT="$root" php -S "127.0.0.1:${port}" "$HARNESS" >"$log" 2>&1 &
    else
        php "$HARNESS" runwire             "--root=${root}"             "--address=127.0.0.1:${port}"             "--recycle=0" >"$log" 2>&1 &
    fi
    local pid=$!

    if ! wait_ready "http://127.0.0.1:${port}"; then
        cat "$log" >&2 || true
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
        return 1
    fi

    echo "$pid"
}

stop_server() {
    local pid="$1"
    kill -TERM "$pid" 2>/dev/null || true
    for _ in $(seq 1 120); do
        if ! kill -0 "$pid" 2>/dev/null; then
            wait "$pid" 2>/dev/null || true
            return 0
        fi
        sleep 0.25
    done

    kill -KILL "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    return 1
}

field() {
    local pattern="$1"
    local file="$2"
    awk -v pattern="$pattern" '
        index($0, pattern) {
            sub("^[^:]+:[[:space:]]*", "", $0);
            print $1;
            exit;
        }
    ' "$file"
}

latency_ms() {
    local raw="$1"
    if [[ "$raw" =~ ^([0-9.]+)(us|ms|s)$ ]]; then
        local value="${BASH_REMATCH[1]}"
        local unit="${BASH_REMATCH[2]}"
        awk -v value="$value" -v unit="$unit" 'BEGIN {
            if (unit == "us") printf "%.6f", value / 1000;
            else if (unit == "s") printf "%.6f", value * 1000;
            else printf "%.6f", value;
        }'
        return 0
    fi

    echo "0"
}

wrk_socket_errors() {
    local file="$1"
    local line
    line="$(grep -m1 'Socket errors:' "$file" || true)"
    if [[ -z "$line" ]]; then
        echo "0"
        return 0
    fi

    grep -oE '[0-9]+' <<<"$line" | awk '{sum += $1} END {print sum + 0}'
}

wrk_requests() {
    local file="$1"
    awk '$2 == "requests" && $3 == "in" { print $1; exit }' "$file"
}

wrk_p99_ms() {
    local file="$1"
    local raw
    raw="$(awk '$1 == "99%" { print $2; exit }' "$file")"
    latency_ms "${raw:-0ms}"
}

assert_correctness() {
    local port="$1"
    local base="http://127.0.0.1:${port}"
    local status

    [[ "$(curl -fsS "$base/cert/static")" == "webrick-cert-ok" ]]
    [[ "$(curl -fsS "$base/cert/dynamic/42" | jq -r '.id')" == "42" ]]
    [[ "$(curl -fsS "$base/cert/json" | jq -r '.ok')" == "true" ]]
    [[ "$(curl -fsS "$base/cert/stream" | wc -c | tr -d ' ')" == "3072" ]]
    [[ "$(curl -fsS -X POST --data-binary "@$PAYLOAD" "$base/cert/upload" | jq -r '.bytes')" == "16384" ]]

    status="$(curl -sS -o /dev/null -w '%{http_code}' "$base/cert/missing")"
    [[ "$status" == "404" ]]
    status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$base/cert/static")"
    [[ "$status" == "405" ]]
    status="$(curl -sS -o "$RESULT_DIR/range.body" -w '%{http_code}' -H 'Range: bytes=0-15' "$base/cert/file")"
    [[ "$status" == "206" ]]
    [[ "$(wc -c < "$RESULT_DIR/range.body" | tr -d ' ')" == "16" ]]
}

record_wrk() {
    local version="$1"
    local mode="$2"
    local workload="$3"
    local concurrency="$4"
    local trial="$5"
    local port="$6"
    local pid="$7"
    local path="$8"
    local method="$9"

    local out="$RESULT_DIR/${version}-${mode}-${workload}-c${concurrency}-t${trial}.txt"
    local threads="$concurrency"
    if (( threads > 4 )); then
        threads=4
    fi

    local args=(-t"$threads" -c"$concurrency" -d"${DURATION_SECONDS}s" --latency)
    if [[ "$method" == "POST" ]]; then
        args+=(-s "$POST_SCRIPT")
    fi

    wrk "${args[@]}" "http://127.0.0.1:${port}${path}" >"$out" 2>&1

    local rps complete failed non2xx p99 rss metrics
    rps="$(field "Requests/sec" "$out")"
    complete="$(wrk_requests "$out")"
    failed="$(wrk_socket_errors "$out")"
    non2xx="$(field "Non-2xx or 3xx responses" "$out")"
    p99="$(wrk_p99_ms "$out")"
    rss="$(rss_kb "$pid")"
    metrics="$(curl -fsS "http://127.0.0.1:${port}/__cert/metrics" 2>/dev/null || echo '{}')"

    jq -cn         --arg version "$version"         --arg mode "$mode"         --arg workload "$workload"         --argjson concurrency "$concurrency"         --argjson trial "$trial"         --argjson rps "${rps:-0}"         --argjson complete "${complete:-0}"         --argjson failed "${failed:-0}"         --argjson non2xx "${non2xx:-0}"         --argjson p99 "${p99:-0}"         --argjson rss_kb "$rss"         --argjson metrics "$metrics"         '{
            version:$version,
            mode:$mode,
            workload:$workload,
            concurrency:$concurrency,
            trial:$trial,
            rps:$rps,
            complete:$complete,
            failed:$failed,
            non2xx:$non2xx,
            p99:$p99,
            rss_kb:$rss_kb,
            metrics:$metrics
        }' >> "$RESULTS"
}

run_target() {
    local version="$1"
    local root="$2"
    local mode="$3"
    local port="$4"

    for trial in $(seq 1 "$TRIALS"); do
        local pid
        pid="$(start_server "$root" "$mode" "$port" "${version}-t${trial}")"
        CURRENT_PID="$pid"

        assert_correctness "$port"
        wrk -t2 -c5 -d2s "http://127.0.0.1:${port}/cert/static" >/dev/null 2>&1

        for concurrency in 1 5 20 50; do
            record_wrk "$version" "$mode" static "$concurrency" "$trial" "$port" "$pid" "/cert/static" GET
            record_wrk "$version" "$mode" dynamic "$concurrency" "$trial" "$port" "$pid" "/cert/dynamic/42" GET
            record_wrk "$version" "$mode" json "$concurrency" "$trial" "$port" "$pid" "/cert/json" GET
            record_wrk "$version" "$mode" stream "$concurrency" "$trial" "$port" "$pid" "/cert/stream" GET
            record_wrk "$version" "$mode" upload "$concurrency" "$trial" "$port" "$pid" "/cert/upload" POST
        done

        stop_server "$pid"
        CURRENT_PID=""
    done
}

php "$HARNESS" prepare "--root=${BASELINE_ROOT}"
php "$HARNESS" prepare "--root=${CANDIDATE_ROOT}"

run_target baseline "$BASELINE_ROOT" sapi 18080
run_target candidate "$CANDIDATE_ROOT" sapi 18081
run_target baseline "$BASELINE_ROOT" runwire 18082
run_target candidate "$CANDIDATE_ROOT" runwire 18083

echo "$RESULTS"
