#!/usr/bin/env bash

# wrk truncates connections/threads; every supported concurrency must divide exactly.
load_concurrencies() {
    case "${1:-all}" in
        all) printf '%s\n' 1 5 20 50 ;;
        1|5|20|50) printf '%s\n' "$1" ;;
        *) echo "Unsupported certification concurrency: $1" >&2; return 1 ;;
    esac
}

load_threads() {
    local threads
    case "$1" in
        1|5) threads=1 ;;
        20) threads=4 ;;
        50) threads=2 ;;
        *) echo "Unsupported certification concurrency: $1" >&2; return 1 ;;
    esac
    # One loop avoids low-rate client cohort jitter while retaining every socket.
    case "${2:-}" in
        risk_login*|risk_provider_slow) threads=1 ;;
    esac
    printf '%s\n' "$threads"
}

# Cross first/second load position with both fixed loopback port roles. Each
# group of four trials contains the complete 2x2 design, limiting role and time
# bias without changing workload semantics or performance acceptance budgets.
load_validate_trials() {
    case "${1:-}" in
        4|8|12) return 0 ;;
        *) echo 'Certification requires 4, 8, or 12 crossed paired trials.' >&2; return 1 ;;
    esac
}

load_pair_order() {
    local trial="${1:-}"
    if [[ ! "$trial" =~ ^[1-9][0-9]*$ ]]; then
        echo 'Trial must be a positive integer.' >&2
        return 1
    fi
    case $(((trial - 1) % 4)) in
        0|3) printf '%s\n' baseline candidate ;;
        *) printf '%s\n' candidate baseline ;;
    esac
}

# Output baseline/candidate ports. The caller starts each process on the
# selected port and records the mapping in the artifact for auditability.
load_pair_ports() {
    local trial="${1:-}" low="${2:-}" high="${3:-}"
    if [[ ! "$trial" =~ ^[1-9][0-9]*$ ]] ||
       [[ ! "$low" =~ ^[1-9][0-9]*$ ]] ||
       [[ ! "$high" =~ ^[1-9][0-9]*$ ]] ||
       [[ "$low" == "$high" ]]; then
        echo 'Trial and two distinct numeric ports are required.' >&2
        return 1
    fi
    case $(((trial - 1) % 4)) in
        0|1) printf '%s %s\n' "$low" "$high" ;;
        *) printf '%s %s\n' "$high" "$low" ;;
    esac
}
