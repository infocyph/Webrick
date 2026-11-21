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

# Alternate source order with an even number of trials. A three-trial AB/BA/AB
# comparison gives the baseline the first measurement twice, causing bias when
# the first wrk invocation benefits from CPU/cache scheduling.
load_validate_trials() {
    local trials="${1:-}"
    if [[ ! "$trials" =~ ^[1-9][0-9]*$ ]] ||
       (( trials < 4 || trials % 2 != 0 )); then
        echo 'Certification requires an even number of paired trials (at least four).' >&2
        return 1
    fi
}

load_pair_order() {
    local trial="${1:-}"
    if [[ ! "$trial" =~ ^[1-9][0-9]*$ ]]; then
        echo 'Trial must be a positive integer.' >&2
        return 1
    fi
    if (( trial % 2 == 0 )); then
        printf '%s\n' candidate baseline
    else
        printf '%s\n' baseline candidate
    fi
}
