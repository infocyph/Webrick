#!/usr/bin/env bash

assert_load_worker_events() {
    local events="$1"
    if [[ ! -s "$events" ]]; then
        echo "Required load worker lifecycle evidence is missing or empty: $events" >&2
        return 1
    fi
    if ! jq -s -e '
        any(.[]; .type == "worker_ready")
        and all(.[];
            .type != "worker_unhealthy"
            and .type != "worker_restart_scheduled"
            and (.type != "worker_exited" or .expected == true)
        )
    ' "$events" >/dev/null; then
        echo "Load worker lifecycle contains an unexpected failure or invalid evidence: $events" >&2
        return 1
    fi
}
