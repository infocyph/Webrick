#!/usr/bin/env bash
set -euo pipefail

BASELINE_ROOT="${1:?baseline root required}"
CANDIDATE_ROOT="${2:?candidate root required}"
RESULT_DIR="${3:?result directory required}"
DURATION_SECONDS="${4:-5}"
TRIALS="${5:-3}"
PARITY_DURATION_SECONDS="${6:-2}"
MODE="${7:-all}"

if [[ "$MODE" != "all" && "$MODE" != "sapi" && "$MODE" != "runwire" ]]; then
    echo "Certification mode must be all, sapi or runwire." >&2
    exit 1
fi

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
VALIDATION_SCRIPT="$RESULT_DIR/validate.lua"
cat > "$VALIDATION_SCRIPT" <<'LUA'
local workload = os.getenv("CERT_WORKLOAD") or ""
local close_delimited = os.getenv("CERT_CLOSE_DELIMITED") == "1"
local threads = {}
validated = 0
invalid = 0

setup = function(thread)
    table.insert(threads, thread)
end

init = function(args)
    validated = 0
    invalid = 0
end

if workload == "upload" then
    wrk.method = "POST"
    wrk.body = string.rep("x", 16384)
    wrk.headers["Content-Type"] = "application/octet-stream"
elseif workload == "method_not_allowed" then
    wrk.method = "POST"
elseif workload == "range" then
    wrk.headers["Range"] = "bytes=0-15"
end

if close_delimited then
    wrk.headers["Connection"] = "close"
end

local function full_match(body, pattern)
    return string.match(body, pattern) ~= nil
end

response = function(status, headers, body)
    local ok = false

    if workload == "static" then
        ok = status == 200 and body == "webrick-cert-ok"
    elseif workload == "dynamic" then
        ok = status == 200 and body == '{"id":"42"}'
    elseif workload == "json" then
        ok = status == 200
            and full_match(
                body,
                '^{"ok":true,"pid":%d+,"protocol":"1%.1","path":"\\/cert\\/json"}$'
            )
    elseif workload == "stream" then
        ok = status == 200
            and #body == 3072
            and body == string.rep("a", 1024) .. string.rep("b", 1024) .. string.rep("c", 1024)
    elseif workload == "upload" then
        ok = status == 200 and full_match(body, '^{"bytes":16384,"pid":%d+}$')
    elseif workload == "not_found" then
        ok = status == 404
    elseif workload == "method_not_allowed" then
        ok = status == 405
    elseif workload == "file" then
        ok = status == 200 and body == string.rep("0123456789abcdef", 256)
    elseif workload == "range" then
        ok = status == 206 and body == "0123456789abcdef"
    elseif workload == "slow" then
        ok = status == 200 and full_match(body, '^{"slept_ms":5,"pid":%d+}$')
    end

    if ok then
        validated = validated + 1
    else
        invalid = invalid + 1
    end
end

done = function(summary, latency, requests)
    local total_validated = 0
    local total_invalid = 0
    for _, thread in ipairs(threads) do
        total_validated = total_validated + (thread:get("validated") or 0)
        total_invalid = total_invalid + (thread:get("invalid") or 0)
    end
    io.write(string.format("CERT Validated responses: %d\n", total_validated))
    io.write(string.format("CERT Invalid responses: %d\n", total_invalid))
end
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

wrk_socket_error() {
    local file="$1"
    local category="$2"
    local match
    match="$(grep -m1 'Socket errors:' "$file" | grep -oE "${category} [0-9]+" || true)"
    if [[ -z "$match" ]]; then
        echo "0"
        return 0
    fi

    awk '{print $2}' <<<"$match"
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

validate_metrics_json() {
    jq -e '
        type == "object"
        and (.pid | type == "number")
        and (.memory_current_bytes | type == "number")
        and (.memory_peak_bytes | type == "number")
        and (.requests_active | type == "number")
        and (
            ((.queued_bytes_current? // null) | type == "number")
            or ((.deferred_backlog? // null) | type == "number")
        )
        and (.rejected_requests_total | type == "number")
    ' >/dev/null
}

fetch_metrics() {
    local port="$1"
    local metrics
    if ! metrics="$(curl -fsS --max-time 2 "http://127.0.0.1:${port}/__cert/metrics")"; then
        echo "Unable to fetch required certification telemetry from port ${port}." >&2
        return 1
    fi
    if ! validate_metrics_json <<<"$metrics"; then
        echo "Certification telemetry from port ${port} is missing required fields." >&2
        echo "$metrics" >&2
        return 1
    fi

    printf '%s' "$metrics"
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
    fetch_metrics "$port" >/dev/null
}

assert_invalid_fixture() {
    local workload="$1"
    local port="$2"
    local path="$3"
    local label="$4"
    local close_delimited="${5:-0}"
    local output="$RESULT_DIR/reject-${label}.txt"

    CERT_WORKLOAD="$workload" CERT_CLOSE_DELIMITED="$close_delimited" \
        wrk -t1 -c1 -d1s --latency -s "$VALIDATION_SCRIPT" \
        "http://127.0.0.1:${port}${path}" >"$output" 2>&1
    if (( $(field "CERT Invalid responses" "$output") == 0 )); then
        echo "Response validator accepted invalid fixture ${label}." >&2
        return 1
    fi
}

assert_rejection_probes() {
    local mode="$1"
    local port="$2"
    local close_delimited=0

    if validate_metrics_json <<<'{}'; then
        echo "Telemetry validator accepted an empty metrics object." >&2
        return 1
    fi

    assert_invalid_fixture static "$port" /cert/corrupt "${mode}-corrupt-static"
    assert_invalid_fixture dynamic "$port" /cert/malformed-dynamic "${mode}-malformed-dynamic"
    assert_invalid_fixture dynamic "$port" /cert/wrong-dynamic "${mode}-wrong-dynamic"
    assert_invalid_fixture json "$port" /cert/malformed-json "${mode}-malformed-json"
    assert_invalid_fixture json "$port" /cert/wrong-json "${mode}-wrong-json"
    assert_invalid_fixture upload "$port" /cert/malformed-upload "${mode}-malformed-upload"
    assert_invalid_fixture upload "$port" /cert/wrong-upload "${mode}-wrong-upload"

    if [[ "$mode" == "sapi" ]]; then
        close_delimited=1
    fi
    assert_invalid_fixture stream "$port" /cert/truncated "${mode}-truncated-stream" "$close_delimited"
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
    local duration="${9:-$DURATION_SECONDS}"

    local out="$RESULT_DIR/${version}-${mode}-${workload}-c${concurrency}-t${trial}.txt"
    local threads="$concurrency"
    local close_delimited=0
    if (( threads > 4 )); then
        threads=4
    fi
    if [[ "$mode" == "sapi" && "$workload" == "stream" ]]; then
        close_delimited=1
    fi

    CERT_WORKLOAD="$workload" CERT_CLOSE_DELIMITED="$close_delimited"         wrk -t"$threads" -c"$concurrency" -d"${duration}s" --latency         -s "$VALIDATION_SCRIPT" "http://127.0.0.1:${port}${path}" >"$out" 2>&1

    local rps complete non2xx p99 rss metrics validated invalid
    local connect_errors read_errors write_errors timeout_errors failed
    rps="$(field "Requests/sec" "$out")"
    complete="$(wrk_requests "$out")"
    non2xx="$(field "Non-2xx or 3xx responses" "$out")"
    p99="$(wrk_p99_ms "$out")"
    validated="$(field "CERT Validated responses" "$out")"
    invalid="$(field "CERT Invalid responses" "$out")"
    connect_errors="$(wrk_socket_error "$out" connect)"
    read_errors="$(wrk_socket_error "$out" read)"
    write_errors="$(wrk_socket_error "$out" write)"
    timeout_errors="$(wrk_socket_error "$out" timeout)"
    failed=$((connect_errors + read_errors + write_errors + timeout_errors))
    rss="$(rss_kb "$pid")"
    metrics="$(fetch_metrics "$port")"

    jq -cn         --arg version "$version"         --arg mode "$mode"         --arg workload "$workload"         --argjson concurrency "$concurrency"         --argjson trial "$trial"         --argjson rps "${rps:-0}"         --argjson complete "${complete:-0}"         --argjson validated "${validated:-0}"         --argjson invalid "${invalid:-0}"         --argjson failed "$failed"         --argjson non2xx "${non2xx:-0}"         --argjson connect_errors "$connect_errors"         --argjson read_errors "$read_errors"         --argjson write_errors "$write_errors"         --argjson timeout_errors "$timeout_errors"         --argjson p99 "${p99:-0}"         --argjson rss_kb "$rss"         --argjson metrics "$metrics"         '{
            version:$version,
            mode:$mode,
            workload:$workload,
            concurrency:$concurrency,
            trial:$trial,
            rps:$rps,
            complete:$complete,
            validated:$validated,
            invalid:$invalid,
            failed:$failed,
            non2xx:$non2xx,
            socket_errors:{
                connect:$connect_errors,
                read:$read_errors,
                write:$write_errors,
                timeout:$timeout_errors
            },
            p99:$p99,
            rss_kb:$rss_kb,
            metrics:$metrics
        }' >> "$RESULTS"
}

run_trial() {
    local version="$1"
    local root="$2"
    local mode="$3"
    local port="$4"
    local trial="$5"

    local pid
    pid="$(start_server "$root" "$mode" "$port" "${version}-t${trial}")"
    CURRENT_PID="$pid"

    assert_correctness "$port"
    assert_rejection_probes "$mode" "$port"
    wrk -t2 -c5 -d2s "http://127.0.0.1:${port}/cert/static" >/dev/null 2>&1

    for concurrency in 1 5 20 50; do
        record_wrk "$version" "$mode" static "$concurrency" "$trial" "$port" "$pid" "/cert/static"
        record_wrk "$version" "$mode" dynamic "$concurrency" "$trial" "$port" "$pid" "/cert/dynamic/42"
        record_wrk "$version" "$mode" json "$concurrency" "$trial" "$port" "$pid" "/cert/json"
        record_wrk "$version" "$mode" stream "$concurrency" "$trial" "$port" "$pid" "/cert/stream"
        record_wrk "$version" "$mode" upload "$concurrency" "$trial" "$port" "$pid" "/cert/upload"

        record_wrk "$version" "$mode" not_found "$concurrency" "$trial" "$port" "$pid" "/cert/missing" "$PARITY_DURATION_SECONDS"
        record_wrk "$version" "$mode" method_not_allowed "$concurrency" "$trial" "$port" "$pid" "/cert/static" "$PARITY_DURATION_SECONDS"
        record_wrk "$version" "$mode" file "$concurrency" "$trial" "$port" "$pid" "/cert/file" "$PARITY_DURATION_SECONDS"
        record_wrk "$version" "$mode" range "$concurrency" "$trial" "$port" "$pid" "/cert/file" "$PARITY_DURATION_SECONDS"
        record_wrk "$version" "$mode" slow "$concurrency" "$trial" "$port" "$pid" "/cert/slow/5" "$PARITY_DURATION_SECONDS"
    done

    stop_server "$pid"
    CURRENT_PID=""
}

run_mode_pair() {
    local mode="$1"
    local baseline_port="$2"
    local candidate_port="$3"

    for trial in $(seq 1 "$TRIALS"); do
        if (( trial % 2 == 1 )); then
            run_trial baseline "$BASELINE_ROOT" "$mode" "$baseline_port" "$trial"
            run_trial candidate "$CANDIDATE_ROOT" "$mode" "$candidate_port" "$trial"
        else
            run_trial candidate "$CANDIDATE_ROOT" "$mode" "$candidate_port" "$trial"
            run_trial baseline "$BASELINE_ROOT" "$mode" "$baseline_port" "$trial"
        fi
    done
}

php "$HARNESS" prepare "--root=${BASELINE_ROOT}"
php "$HARNESS" prepare "--root=${CANDIDATE_ROOT}"

if [[ "$MODE" == "all" || "$MODE" == "sapi" ]]; then
    run_mode_pair sapi 18080 18081
fi
if [[ "$MODE" == "all" || "$MODE" == "runwire" ]]; then
    run_mode_pair runwire 18082 18083
fi

echo "$RESULTS"
