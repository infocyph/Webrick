#!/usr/bin/env bash
# Explicitly isolate source-identical measurement controls from release comparisons.
# The release path is unchanged: trusted baseline uses native admission.
load_select_admission_modes() {
    local baseline_root="${1:?baseline checkout required}"
    local candidate_root="${2:?candidate checkout required}"
    BASELINE_ADMISSION=native
    CANDIDATE_ADMISSION=middleware

    case "${CERT_AA_CONTROL:-0}" in
        0) return 0 ;;
        1) ;;
        *) echo 'CERT_AA_CONTROL must be 0 or 1.' >&2; return 1 ;;
    esac

    local baseline_sha candidate_sha
    baseline_sha="$(git -C "$baseline_root" rev-parse --verify HEAD)" || return 1
    candidate_sha="$(git -C "$candidate_root" rev-parse --verify HEAD)" || return 1
    if [[ "$baseline_sha" != "$candidate_sha" ]] ||
       [[ "$(realpath "$baseline_root")" == "$(realpath "$candidate_root")" ]] ||
       ! cmp -s "$baseline_root/composer.lock" "$candidate_root/composer.lock"; then
        echo 'A/A requires distinct checkouts of the same commit and identical Composer locks.' >&2
        return 1
    fi
    if ! git -C "$baseline_root" diff --quiet HEAD -- ||
       ! git -C "$candidate_root" diff --quiet HEAD --; then
        echo 'A/A refuses tracked source changes after checkout.' >&2
        return 1
    fi

    BASELINE_ADMISSION=middleware
    CANDIDATE_ADMISSION=middleware
}
