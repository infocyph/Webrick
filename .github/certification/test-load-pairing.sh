#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/load-concurrency.sh"

for trials in 4 8 12; do
    load_validate_trials "$trials"
done
for trials in 0 1 2 3 5 6 7 9 10 14 invalid 04 99999999999999999999999999; do
    if load_validate_trials "$trials" >/dev/null 2>&1; then
        echo "Accepted incomplete crossing or invalid trial count: $trials" >&2
        exit 1
    fi
done

# The four pairings cross which side runs first against which side owns the
# lower port, instead of just alternating order with permanently fixed ports.
for ((trial=1;trial<=12;trial++)); do
    slot=$(((trial - 1) % 4))
    case "$slot" in
        0) expected_order=$'baseline\ncandidate'; expected_ports="18082 18083" ;;
        1) expected_order=$'candidate\nbaseline'; expected_ports="18082 18083" ;;
        2) expected_order=$'candidate\nbaseline'; expected_ports="18083 18082" ;;
        3) expected_order=$'baseline\ncandidate'; expected_ports="18083 18082" ;;
    esac
    [[ "$(load_pair_order "$trial")" == "$expected_order" ]]
    [[ "$(load_pair_ports "$trial" 18082 18083)" == "$expected_ports" ]]
done
for invalid in 0 -1 abc 01; do
    if load_pair_order "$invalid" >/dev/null 2>&1; then
        echo "Accepted invalid trial index: $invalid" >&2
        exit 1
    fi
    if load_pair_ports "$invalid" 18082 18083 >/dev/null 2>&1; then
        echo "Accepted invalid port-crossing trial: $invalid" >&2
        exit 1
    fi
done
if load_pair_ports 1 18082 18082 >/dev/null 2>&1; then
    echo 'Accepted identical ports.' >&2
    exit 1
fi
if load_pair_ports 1 bad 18083 >/dev/null 2>&1; then
    echo 'Accepted non-numeric port.' >&2
    exit 1
fi
echo 'Four-way baseline/candidate port and order crossing verified.'
