#!/usr/bin/env bash
set -euo pipefail

BASELINE_ROOT="${1:?baseline root required}"
CANDIDATE_ROOT="${2:?candidate root required}"
RESULT_DIR="${3:?result directory required}"
DURATION_SECONDS="${4:-5}"
TRIALS="${5:-3}"
PARITY_DURATION_SECONDS="${6:-$DURATION_SECONDS}"
MODE="${7:-all}"
CONCURRENCY="${8:-all}"
RUNWIRE_TAIL_SECONDS="${9:-$DURATION_SECONDS}"

source "$(dirname "${BASH_SOURCE[0]}")/load-concurrency.sh"
SELECTED_CONCURRENCIES="$(load_concurrencies "$CONCURRENCY")"

if [[ "$MODE" != "all" && "$MODE" != "sapi" && "$MODE" != "runwire" ]]; then
    echo "Certification mode must be all, sapi or runwire." >&2
    exit 1
fi

SERVER_COMMAND=()
LOAD_CLIENT=(wrk)
if [[ -n "${CERT_SERVER_CPUS:-}" && -n "${CERT_LOAD_CPUS:-}" ]]; then
    SERVER_COMMAND=(taskset -c "$CERT_SERVER_CPUS")
    LOAD_CLIENT=(taskset -c "$CERT_LOAD_CPUS" wrk)
fi

HARNESS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/cert-server.php"
mkdir -p "$RESULT_DIR"
RESULTS="$RESULT_DIR/load-results.jsonl"
: > "$RESULTS"
declare -A SERVER_PIDS=() SERVER_PORTS=() SERVER_EVENTS=() EVENT_EVIDENCE=()

cleanup_servers() {
    local version
    for version in "${!SERVER_PIDS[@]}"; do
        stop_server "${SERVER_PIDS[$version]}" || true
        if [[ -f "${SERVER_EVENTS[$version]:-}" ]]; then
            cp "${SERVER_EVENTS[$version]}" "${EVENT_EVIDENCE[$version]}" || true
        fi
    done
}
trap cleanup_servers EXIT

PAYLOAD="$RESULT_DIR/upload.bin"
head -c 16384 /dev/zero > "$PAYLOAD"
VALIDATION_SCRIPT="$RESULT_DIR/validate.lua"
cat > "$VALIDATION_SCRIPT" <<'LUA'
local workload = os.getenv("CERT_WORKLOAD") or ""
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
elseif string.sub(workload, 1, 5) == "risk_" then
    wrk.method = "POST"
    local password = workload == "risk_login_invalid" and "wrong-password" or "cert-password"
    wrk.body = '{"account":"cert-account","password":"' .. password .. '"}'
    wrk.headers["Content-Type"] = "application/json"
elseif workload == "method_not_allowed" then
    wrk.method = "POST"
elseif workload == "range" then
    wrk.headers["Range"] = "bytes=0-15"
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
                '^{"ok":true,"pid":[1-9]%d*,"protocol":"1%.1","path":"\\/cert\\/json"}$'
            )
    elseif workload == "stream" then
        ok = status == 200
            and #body == 3072
            and body == string.rep("a", 1024) .. string.rep("b", 1024) .. string.rep("c", 1024)
    elseif workload == "upload" then
        ok = status == 200 and full_match(body, '^{"bytes":16384,"pid":[1-9]%d*}$')
    elseif workload == "not_found" then
        ok = status == 404
    elseif workload == "method_not_allowed" then
        ok = status == 405
    elseif workload == "file" then
        ok = status == 200 and body == string.rep("0123456789abcdef", 256)
    elseif workload == "range" then
        ok = status == 206 and body == "0123456789abcdef"
    elseif workload == "slow" then
        ok = status == 200 and full_match(body, '^{"slept_ms":5,"pid":[1-9]%d*}$')
    elseif workload == "risk_allow" or workload == "risk_counter" or workload == "risk_clearance" then
        ok = status == 200 and body == "risk-admitted"
    elseif workload == "risk_login" or workload == "risk_provider_slow" then
        ok = status == 200 and body == '{"authenticated":true}'
    elseif workload == "risk_login_invalid" then
        ok = status == 401 and body == '{"error":"invalid_credentials"}'
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

source "$(dirname "${BASH_SOURCE[0]}")/load-rss-sampler.sh"
init_rss_sampling
source "$(dirname "${BASH_SOURCE[0]}")/load-worker-events.sh"
source "$(dirname "${BASH_SOURCE[0]}")/load-metrics.sh"

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
    local version="$4"
    local trial="$5"
    local log="$RESULT_DIR/${version}-t${trial}-${mode}.log"
    local admission=native
    if [[ "$version" == candidate ]]; then
        admission=middleware
    fi

    if [[ "$mode" == "sapi" ]]; then
        WEBRICK_CERT_ADMISSION="$admission" WEBRICK_CERT_ROOT="$root" \
            "${SERVER_COMMAND[@]}" bash "$(dirname "$HARNESS")/run-fpm.sh" "$port" "$HARNESS" >"$log" 2>&1 &
    else
        WEBRICK_CERT_ADMISSION="$admission" "${SERVER_COMMAND[@]}" php "$HARNESS" runwire \
            "--root=${root}" "--address=127.0.0.1:${port}" "--recycle=0" >"$log" 2>&1 &
    fi
    SERVER_PIDS[$version]=$!
    SERVER_PORTS[$version]=$port

    if ! wait_ready "http://127.0.0.1:${port}"; then
        cat "$log" >&2 || true
        return 1
    fi
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
    for workload in risk_allow risk_counter risk_clearance; do
        [[ "$(curl -fsS -X POST "$base/cert/$workload")" == "risk-admitted" ]]
    done
    [[ "$(curl -fsS -H 'Content-Type: application/json' \
        --data '{"account":"cert-account","password":"cert-password"}' "$base/cert/risk_login")" == '{"authenticated":true}' ]]
    status="$(curl -sS -o "$RESULT_DIR/login-invalid.body" -w '%{http_code}' -H 'Content-Type: application/json' \
        --data '{"account":"cert-account","password":"wrong-password"}' "$base/cert/risk_login_invalid")"
    [[ "$status" == 401 ]]
    [[ "$(cat "$RESULT_DIR/login-invalid.body")" == '{"error":"invalid_credentials"}' ]]
    for action in challenge deny unavailable; do
        local expected_status
        case "$action" in
            challenge) expected_status=429 ;;
            deny) expected_status=403 ;;
            unavailable) expected_status=503 ;;
        esac
        status="$(curl -sS -D "$RESULT_DIR/risk.headers" -o "$RESULT_DIR/risk.body" -w '%{http_code}' \
            -X POST -H "X-Cert-Risk-Action: $action" "$base/cert/risk_allow")"
        [[ "$status" == "$expected_status" ]]
        grep -qi '^Cache-Control: no-store' "$RESULT_DIR/risk.headers"
        jq -e 'keys == ["error"]' "$RESULT_DIR/risk.body" >/dev/null
    done
    fetch_metrics "$port" >/dev/null
}

assert_invalid_fixture() {
    local workload="$1"
    local port="$2"
    local path="$3"
    local label="$4"
    local output="$RESULT_DIR/reject-${label}.txt"

    CERT_WORKLOAD="$workload" \
        "${LOAD_CLIENT[@]}" -t1 -c1 -d1s --latency -s "$VALIDATION_SCRIPT" \
        "http://127.0.0.1:${port}${path}" >"$output" 2>&1
    if (( $(field "CERT Invalid responses" "$output") == 0 )); then
        echo "Response validator accepted invalid fixture ${label}." >&2
        return 1
    fi
}

assert_rejection_probes() {
    local mode="$1"
    local port="$2"

    if validate_metrics_json <<<'{}'; then
        echo "Telemetry validator accepted an empty metrics object." >&2
        return 1
    fi

    assert_invalid_fixture static "$port" /cert/corrupt "${mode}-corrupt-static"
    assert_invalid_fixture dynamic "$port" /cert/malformed-dynamic "${mode}-malformed-dynamic"
    assert_invalid_fixture dynamic "$port" /cert/wrong-dynamic "${mode}-wrong-dynamic"
    assert_invalid_fixture json "$port" /cert/malformed-json "${mode}-malformed-json"
    assert_invalid_fixture json "$port" /cert/wrong-json "${mode}-wrong-json"
    assert_invalid_fixture json "$port" /cert/leading-zero-json "${mode}-leading-zero-json"
    assert_invalid_fixture upload "$port" /cert/malformed-upload "${mode}-malformed-upload"
    assert_invalid_fixture upload "$port" /cert/wrong-upload "${mode}-wrong-upload"
    assert_invalid_fixture upload "$port" /cert/leading-zero-upload "${mode}-leading-zero-upload"
    assert_invalid_fixture slow "$port" /cert/leading-zero-slow "${mode}-leading-zero-slow"
    assert_invalid_fixture risk_allow "$port" /cert/corrupt "${mode}-wrong-risk-allow"
    assert_invalid_fixture risk_login "$port" /cert/corrupt "${mode}-wrong-risk-login"
    assert_invalid_fixture risk_login_invalid "$port" /cert/corrupt "${mode}-wrong-risk-login-invalid"

    assert_invalid_fixture stream "$port" /cert/truncated "${mode}-truncated-stream"
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

    if [[ "$mode" == runwire ]] && (( concurrency >= 20 && RUNWIRE_TAIL_SECONDS > duration )); then
        case "$workload" in
            risk_login*|risk_provider_slow) duration="$RUNWIRE_TAIL_SECONDS" ;;
        esac
    fi

    local out="$RESULT_DIR/${version}-${mode}-${workload}-c${concurrency}-t${trial}.txt"
    local threads
    threads="$(load_threads "$concurrency" "$workload")"
    local request_timeout
    request_timeout="$(load_request_timeout "$workload")"

    local rss_samples="$RESULT_DIR/${version}-${mode}-${workload}-c${concurrency}-t${trial}-rss.txt"
    CERT_WORKLOAD="$workload" \
        run_load_with_rss "$pid" "$rss_samples" "$out" \
        "${LOAD_CLIENT[@]}" -t"$threads" -c"$concurrency" -d"${duration}s" --latency --timeout "${request_timeout}s" \
        -s "$VALIDATION_SCRIPT" "http://127.0.0.1:${port}${path}"

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
    rss="$(awk 'max < $1 { max = $1 } END { print max + 0 }' "$rss_samples")"
    if [[ "$mode" == runwire ]]; then
        assert_load_worker_events "${SERVER_EVENTS[$version]}"
    fi
    metrics="$(fetch_drained_metrics "$port" "$RESULT_DIR/${version}-${mode}-${workload}-c${concurrency}-t${trial}-settling.jsonl" "$request_timeout")"

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

warm_server() {
    local version="$1" root="$2" mode="$3" port="$4" trial="$5"
    if [[ "$mode" == runwire ]]; then
        SERVER_EVENTS[$version]="$root/.runtime-certification/router.php.supervisor-events.jsonl"
        EVENT_EVIDENCE[$version]="$RESULT_DIR/${version}-t${trial}-supervisor-events.jsonl"
    fi
    start_server "$root" "$mode" "$port" "$version" "$trial"
    assert_correctness "$port"
    assert_rejection_probes "$mode" "$port"
    "${LOAD_CLIENT[@]}" -t1 -c5 -d2s "http://127.0.0.1:${port}/cert/static" >/dev/null 2>&1
}

record_pair() {
    local mode="$1" concurrency="$2" trial="$3" workload="$4" path="$5"
    local duration="${6:-$DURATION_SECONDS}"
    local -a versions=(baseline candidate)
    if (( trial % 2 == 0 )); then
        versions=(candidate baseline)
    fi
    local version
    for version in "${versions[@]}"; do
        record_wrk "$version" "$mode" "$workload" "$concurrency" "$trial" \
            "${SERVER_PORTS[$version]}" "${SERVER_PIDS[$version]}" "$path" "$duration"
    done
}

run_mode_pair() {
    local mode="$1"
    local -A ports=([baseline]="$2" [candidate]="$3")
    local trial concurrency workload version
    for trial in $(seq 1 "$TRIALS"); do
        warm_server baseline "$BASELINE_ROOT" "$mode" "${ports[baseline]}" "$trial"
        warm_server candidate "$CANDIDATE_ROOT" "$mode" "${ports[candidate]}" "$trial"
        # Keep matching samples close while running only one load client at a time.
        for concurrency in $SELECTED_CONCURRENCIES; do
            record_pair "$mode" "$concurrency" "$trial" static /cert/static
            record_pair "$mode" "$concurrency" "$trial" dynamic /cert/dynamic/42
            record_pair "$mode" "$concurrency" "$trial" json /cert/json
            record_pair "$mode" "$concurrency" "$trial" stream /cert/stream
            record_pair "$mode" "$concurrency" "$trial" upload /cert/upload

            record_pair "$mode" "$concurrency" "$trial" not_found /cert/missing "$PARITY_DURATION_SECONDS"
            record_pair "$mode" "$concurrency" "$trial" method_not_allowed /cert/static "$PARITY_DURATION_SECONDS"
            record_pair "$mode" "$concurrency" "$trial" file /cert/file "$PARITY_DURATION_SECONDS"
            record_pair "$mode" "$concurrency" "$trial" range /cert/file "$PARITY_DURATION_SECONDS"
            record_pair "$mode" "$concurrency" "$trial" slow /cert/slow/5 "$PARITY_DURATION_SECONDS"
            for workload in risk_allow risk_counter risk_clearance risk_login risk_login_invalid risk_provider_slow; do
                record_pair "$mode" "$concurrency" "$trial" "$workload" "/cert/$workload"
            done
        done
        for version in baseline candidate; do
            stop_server "${SERVER_PIDS[$version]}"
            unset 'SERVER_PIDS[$version]' 'SERVER_PORTS[$version]'
            if [[ "$mode" == runwire ]]; then
                cp "${SERVER_EVENTS[$version]}" "${EVENT_EVIDENCE[$version]}"
                assert_load_worker_events "${EVENT_EVIDENCE[$version]}"
            fi
        done
        SERVER_EVENTS=()
        EVENT_EVIDENCE=()
    done
}

WEBRICK_CERT_ADMISSION=native php "$HARNESS" prepare "--root=${BASELINE_ROOT}"
WEBRICK_CERT_ADMISSION=middleware php "$HARNESS" prepare "--root=${CANDIDATE_ROOT}"

if [[ "$MODE" == "all" || "$MODE" == "sapi" ]]; then
    run_mode_pair sapi 18080 18081
fi
if [[ "$MODE" == "all" || "$MODE" == "runwire" ]]; then
    run_mode_pair runwire 18082 18083
fi

echo "$RESULTS"
