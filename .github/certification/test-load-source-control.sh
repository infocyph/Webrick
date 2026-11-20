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
