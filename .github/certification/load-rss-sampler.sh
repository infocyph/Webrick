#!/usr/bin/env bash

# The caller provides rss_kb for the server's process tree.
run_load_with_rss() {
    local server_pid="$1"
    local samples="$2"
    local output="$3"
    shift 3

    rss_kb "$server_pid" > "$samples"
    "$@" > "$output" 2>&1 &
    local load_pid=$!
    while kill -0 "$load_pid" 2>/dev/null; do
        rss_kb "$server_pid" >> "$samples"
        sleep 0.25
    done

    local status=0
    wait "$load_pid" || status=$?
    rss_kb "$server_pid" >> "$samples"
    return "$status"
}
