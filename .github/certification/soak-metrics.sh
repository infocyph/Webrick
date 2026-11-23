#!/usr/bin/env bash

source "$(dirname "${BASH_SOURCE[0]}")/load-metrics.sh"

# Runwire refreshes queued-byte diagnostics after request completion, at most
# once per 250 ms. Prime both workers, allow a refresh, then verify both again.
# All observations remain evidence; a persistent queue still fails in two seconds.
fetch_drained_soak_workers() {
    local port="$1" events="$2" evidence="$3"
    local expected metrics samples round remaining
    local deadline=$((SECONDS + 2))
    if ! expected="$(flock --shared "$events" jq -ce -s '
        reduce .[] as $event ({};
            if $event.type == "worker_ready" then .[$event.pid | tostring] = true
            elif $event.type == "worker_exited" then del(.[$event.pid | tostring])
            else . end
        ) | keys | map(tonumber) | sort
        | select(length == 2 and all(.[]; . > 0 and . == floor))
    ' "$events")"; then
        echo "Cannot establish the two active soak workers from lifecycle evidence." >&2
        return 1
    fi
    : > "$evidence"
    for round in 1 2 3; do
        samples='{}'
        while true; do
            remaining=$((deadline - SECONDS))
            if (( remaining <= 0 )); then
                echo "Both soak workers did not report drained queues within two seconds." >&2
                return 1
            fi
            metrics="$(fetch_metrics "$port" "$remaining")" || return 1
            printf '%s\n' "$metrics" >> "$evidence"
            if ! jq -e --argjson expected "$expected" '
                .pid as $pid | ($expected | index($pid)) != null
                and (.queued_bytes_current | type == "number" and . >= 0)
                and (.deferred_backlog | type == "number" and . >= 0)
                and (.requests_active | type == "number" and . >= 0)
                and .rejected_requests_total == 0
            ' <<<"$metrics" >/dev/null; then
                echo "Invalid soak worker telemetry or rejected requests." >&2
                return 1
            fi
            samples="$(jq -c --argjson metrics "$metrics" '.[$metrics.pid | tostring] = $metrics' <<<"$samples")"
            if jq -e --argjson round "$round" '
                length == 2 and ($round < 3 or all(.[];
                    .queued_bytes_current == 0 and .deferred_backlog == 0 and .requests_active <= 1
                ))
            ' <<<"$samples" >/dev/null; then
                break
            fi
            sleep 0.01
        done
        if (( round < 3 )); then
            sleep 0.3
        fi
    done
    jq -c '[.[]]' <<<"$samples"
}
