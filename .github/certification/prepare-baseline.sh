#!/usr/bin/env bash
set -euo pipefail

# A broken transport cannot be a performance control. Apply only the reviewed
# cancellation correction, preserve HEAD, and publish the exact working source.
baseline="$(realpath "${1:?Baseline checkout required}")"
mkdir -p "${2:?External evidence directory required}"
evidence="$(realpath "$2")"
case "$evidence/" in
    "$baseline/"*) echo 'Evidence must be outside the baseline checkout.' >&2; exit 1 ;;
esac
module=src/Runtime/Http/RunwireRuntimeApplication.php
patch="$(realpath "$(dirname "${BASH_SOURCE[0]}")/baseline-cancellation.diff")"
original=3af39736811aa6e785f246583fb0b7695bf8e140e819ec1c99c8460f072655d3
corrected=576baed44af1a0e8e21486f599164d88cf1ef3d62b1add6413337fcf6caafd4f
candidate=e05581462b9809d4636014239eb71aa94b933042312ff792784c34ac0597b3c0
patch_hash=cf9a1cd884425bef0d03eab4a3705f44463cde12bec8f9ae97dcd5679fd5d403
[[ "$(sha256sum "$patch" | cut -d' ' -f1)" == "$patch_hash" ]]
[[ -f "$baseline/$module" && ! -L "$baseline/$module" ]]
[[ -z "$(git -C "$baseline" diff --cached --name-only)" ]]
changed="$(git -C "$baseline" diff --name-only)"
[[ -z "$changed" || "$changed" == "$module" ]]
[[ -z "$(git -C "$baseline" diff --summary)" ]]
head="$(git -C "$baseline" rev-parse HEAD)"
committed="$(git -C "$baseline" show "HEAD:$module" | sha256sum | cut -d' ' -f1)"
before="$(sha256sum "$baseline/$module" | cut -d' ' -f1)"
state=not-needed
reference='unmodified main with verified cancellation correction'
if [[ "$committed" == "$original" ]]; then
    reference='main + reviewed cancellation-only backport'
    if [[ "$before" == "$original" && -z "$changed" ]]; then
        git -C "$baseline" apply --check "$patch"
        git -C "$baseline" apply "$patch"
        state=applied
    elif [[ "$before" == "$corrected" && "$changed" == "$module" ]]; then
        state=already-normalized
    else
        echo 'Unrecognized baseline working source; refusing normalization.' >&2
        exit 1
    fi
    [[ "$(sha256sum "$baseline/$module" | cut -d' ' -f1)" == "$corrected" ]]
elif [[ ( "$committed" == "$corrected" || "$committed" == "$candidate" ) && "$before" == "$committed" && -z "$changed" ]]; then
    :
else
    echo 'Unreviewed baseline source; review and repin before benchmarking.' >&2
    exit 1
fi
[[ "$(git -C "$baseline" rev-parse HEAD)" == "$head" ]]
git -C "$baseline" show "HEAD:$module" > "$evidence/baseline-original-source.php.txt"
cp "$baseline/$module" "$evidence/baseline-measured-source.php.txt"
after="$(sha256sum "$baseline/$module" | cut -d' ' -f1)"
jq -n --arg reference "$reference" --arg state "$state" \
    --arg commit "$head" --arg module "$module" --arg committed "$committed" \
    --arg before "$before" --arg after "$after" --arg patch "$patch_hash" \
    '{reference:$reference,state:$state,commit:$commit,module:$module,
      committed_sha256:$committed,before_sha256:$before,measured_sha256:$after,patch_sha256:$patch}' \
    > "$evidence/baseline-normalization.json"
printf 'reference=%s\nreference_state=%s\nreference_source_sha256=%s\nreference_patch_sha256=%s\n' \
    "$reference" "$state" "$after" "$patch_hash" | tee -a "$evidence/environment.txt"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    printf 'Performance reference: **%s** (commit `%s`, state `%s`). Source and patch hashes are in `baseline-normalization.json`. This comparison does not certify unpatched main.\n\n' \
        "$reference" "$head" "$state" >> "$GITHUB_STEP_SUMMARY"
fi
