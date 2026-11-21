#!/usr/bin/env bash

init_rss_sampling() {
    local page_bytes
    page_bytes="$(getconf PAGESIZE)" || return 1
    [[ "$page_bytes" =~ ^[0-9]+$ ]] && (( page_bytes >= 1024 && page_bytes % 1024 == 0 )) || return 1
    RSS_PAGE_KB=$((page_bytes / 1024))
}

rss_kb() {
    local root="$1" pid resident task children child total=0
    local -a pending=("$root")
    local -A seen=()
    [[ "$root" =~ ^[1-9][0-9]*$ && "${RSS_PAGE_KB:-}" =~ ^[1-9][0-9]*$ ]] || return 1

    while ((${#pending[@]})); do
        pid="${pending[-1]}"
        unset 'pending[-1]'
        [[ -z "${seen[$pid]:-}" ]] || continue
        seen[$pid]=1
        if ! read -r _ resident _ 2>/dev/null < "/proc/$pid/statm"; then
            [[ "$pid" != "$root" && ! -d "/proc/$pid" ]] && continue
            echo "Cannot sample RSS for process $pid." >&2
            return 1
        fi
        [[ "$resident" =~ ^[0-9]+$ ]] || return 1
        total=$((total + resident * RSS_PAGE_KB))
        # Children can be attached to any thread in a persistent worker.
        for task in /proc/"$pid"/task/*/children; do
            [[ -r "$task" ]] || continue
            children=''
            read -r children 2>/dev/null < "$task" || true
            for child in $children; do pending+=("$child"); done
        done
    done
    printf '%s\n' "$total"
}

run_load_with_rss() {
    local server_pid="$1"
    local samples="$2"
    local output="$3"
    shift 3

    rss_kb "$server_pid" > "$samples" || return $?
    "$@" > "$output" 2>&1 &
    local load_pid=$!
    local status=0
    while kill -0 "$load_pid" 2>/dev/null; do
        if rss_kb "$server_pid" >> "$samples"; then
            :
        else
            status=$?
            kill -KILL "$load_pid" 2>/dev/null || true
            wait "$load_pid" 2>/dev/null || true
            return "$status"
        fi
        sleep 0.25
    done

    wait "$load_pid" || status=$?
    local sample_status=0
    rss_kb "$server_pid" >> "$samples" || sample_status=$?
    (( status != 0 )) || status=$sample_status
    return "$status"
}
