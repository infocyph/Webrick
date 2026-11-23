#!/usr/bin/env bash

# Share the workload deadline between requests and their queued control probe.
load_request_timeout() {
    case "$1" in
        risk_login*|risk_provider_slow) echo 10 ;;
        *) echo 2 ;;
    esac
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
    local timeout="${2:-2}"
    local metrics
    if ! metrics="$(curl -fsS --max-time "$timeout" "http://127.0.0.1:${port}/__cert/metrics")"; then
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

fetch_drained_metrics() {
    local port="$1"
    local evidence="$2"
    local timeout="${3:-2}"
    if [[ ! "$timeout" =~ ^[1-9][0-9]*$ ]]; then
        echo "Telemetry deadline must be a positive number of seconds." >&2
        return 1
    fi
    local deadline=$((SECONDS + timeout))
    local remaining metrics
    : > "$evidence"
    while true; do
        remaining=$((deadline - SECONDS))
        if (( remaining <= 0 )); then
            echo "Certification requests or queues did not drain within $timeout seconds on port $port." >&2
            return 1
        fi
        metrics="$(fetch_metrics "$port" "$remaining")" || return 1
        printf '%s\n' "$metrics" >> "$evidence"
        if jq -e '
            (.queued_bytes_current // 0) == 0
            and (.deferred_backlog // 0) == 0
            and .requests_active <= 1
            and .rejected_requests_total == 0
        ' <<<"$metrics" >/dev/null; then
            printf '%s' "$metrics"
            return 0
        fi
        sleep 0.05
    done
}

