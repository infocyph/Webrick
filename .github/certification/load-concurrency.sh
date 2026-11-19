#!/usr/bin/env bash

# wrk truncates connections/threads; every supported concurrency must divide exactly.
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
