#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/load-concurrency.sh"

for trials in 4 6 8 12; do
    load_validate_trials "$trials"
done
for trials in 0 1 2 3 5 7 9 invalid 04 99999999999999999999999999; do
    if (load_validate_trials "$trials" >/dev/null 2>&1); then
        echo "Accepted unbalanced or invalid trial count: $trials" >&2
        exit 1
    fi
done
for trial in 1 2 3 4 5 6; do
    case $((trial % 2)) in
        0) expected=$'candidate\nbaseline' ;;
        *) expected=$'baseline\ncandidate' ;;
    esac
    actual="$(load_pair_order "$trial")"
    if [[ "$actual" != "$expected" ]]; then
        echo "Incorrect alternating pairing at trial $trial." >&2
        exit 1
    fi
done
for trial in 0 -1 abc 01; do
    if (load_pair_order "$trial" >/dev/null 2>&1); then
        echo "Accepted invalid trial index: $trial" >&2
        exit 1
    fi
done
# The first position must be distributed identically between revisions.
for trials in 4 6 8 12; do
    first_baseline=0
    first_candidate=0
    for ((trial=1;trial<=trials;trial++)); do
        first="$(load_pair_order "$trial" | head -n 1)"
        if [[ "$first" == baseline ]]; then
            ((first_baseline+=1))
        else
            ((first_candidate+=1))
        fi
    done
    [[ "$first_baseline" -eq "$first_candidate" ]]
done
echo 'Even paired-trial configuration and start/measurement order verified.'
