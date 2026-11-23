#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/load-source-control.sh"
scratch="$(mktemp -d)"
trap 'rm -rf -- "$scratch"' EXIT
git init -q "$scratch/baseline"
printf '{"lock":"identical"}\n' > "$scratch/baseline/composer.lock"
printf 'a\n' > "$scratch/baseline/source.txt"
git -C "$scratch/baseline" add composer.lock source.txt
git -C "$scratch/baseline" -c user.name=Webrick -c user.email=cert@example.invalid commit -q -m control
git clone -q "$scratch/baseline" "$scratch/candidate"

CERT_AA_CONTROL=0 load_select_admission_modes "$scratch/baseline" "$scratch/candidate"
[[ "$BASELINE_ADMISSION" == native && "$CANDIDATE_ADMISSION" == middleware ]]

CERT_AA_CONTROL=1 load_select_admission_modes "$scratch/baseline" "$scratch/candidate"
[[ "$BASELINE_ADMISSION" == middleware && "$CANDIDATE_ADMISSION" == middleware ]]

if CERT_AA_CONTROL=invalid load_select_admission_modes "$scratch/baseline" "$scratch/candidate"; then
    echo 'Accepted invalid A/A mode.' >&2; exit 1
fi

printf 'modified\n' >> "$scratch/candidate/source.txt"
if CERT_AA_CONTROL=1 load_select_admission_modes "$scratch/baseline" "$scratch/candidate"; then
    echo 'Accepted modified A/A source.' >&2; exit 1
fi
git -C "$scratch/candidate" checkout -q -- source.txt

printf '{"lock":"different"}\n' > "$scratch/candidate/composer.lock"
if CERT_AA_CONTROL=1 load_select_admission_modes "$scratch/baseline" "$scratch/candidate"; then
    echo 'Accepted mismatched Composer lock.' >&2; exit 1
fi
git -C "$scratch/candidate" checkout -q -- composer.lock

if CERT_AA_CONTROL=1 load_select_admission_modes "$scratch/baseline" "$scratch/baseline"; then
    echo 'Accepted identical A/A checkout paths.' >&2; exit 1
fi

printf 'second commit\n' >> "$scratch/candidate/source.txt"
git -C "$scratch/candidate" add source.txt
git -C "$scratch/candidate" -c user.name=Webrick -c user.email=cert@example.invalid commit -q -m changed
if CERT_AA_CONTROL=1 load_select_admission_modes "$scratch/baseline" "$scratch/candidate"; then
    echo 'Accepted mismatched A/A source revisions.' >&2; exit 1
fi
echo 'A/A control identity and fail-closed contract verified.'

# Keep optional source-identical diagnostic A/A separate from feature PR
# release certification. Release A/B must compare against a fixed tag.
workflow_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../workflows" && pwd)"
release="$workflow_root/release-acceptance.yml"
runtime="$workflow_root/runtime-release-certification.yml"
aa="$workflow_root/http-aa-control.yml"
if grep -Fq 'aa_control: true' "$release" || grep -Fq 'needs: measurement-calibration' "$release"; then
    echo 'A/A calibration must not gate PR release A/B certification.' >&2
    exit 1
fi
grep -Fq 'aa_control: false' "$release"
grep -Fq 'uses: ./.github/workflows/runtime-release-certification.yml' "$release"
grep -Fq 'aa_control: true' "$aa"
grep -Eq '^[[:space:]]+workflow_dispatch:' "$aa"
if grep -Eq '^[[:space:]]+push:|^[[:space:]]+pull_request:' "$aa"; then
    echo 'Diagnostic source-identical A/A must remain manual-only.' >&2
    exit 1
fi
if [[ "$(grep -Fc "ref: \${{ inputs.aa_control && github.sha || '6.0.4' }}" "$runtime")" -ne 2 ]]; then
    echo 'Both A/B baseline checkouts must pin the immutable 6.0.4 tag.' >&2
    exit 1
fi
echo 'PR release compares 6.0.4 vs candidate; A/A is manual and non-blocking.'
