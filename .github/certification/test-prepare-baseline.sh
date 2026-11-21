#!/usr/bin/env bash
set -euo pipefail
scripts="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source_root="$(git -C "$scripts" rev-parse --show-toplevel)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
module=src/Runtime/Http/RunwireRuntimeApplication.php
git -C "$source_root" show "981d095251dc3bf9c65c434f0d84aecb38025f88:$module" > "$scratch/original.php"
mkdir -p "$scratch/control/$(dirname "$module")"
git -C "$scratch/control" init -q
cp "$scratch/original.php" "$scratch/control/$module"
echo fixture > "$scratch/control/unrelated.txt"
commit_fixture() {
    git -C "$scratch/control" add .
    local tree commit
    tree="$(git -C "$scratch/control" write-tree)"
    commit="$(GIT_AUTHOR_NAME=Fixture GIT_AUTHOR_EMAIL=fixture@example.test \
        GIT_COMMITTER_NAME=Fixture GIT_COMMITTER_EMAIL=fixture@example.test \
        git -C "$scratch/control" commit-tree "$tree" -m fixture)"
    git -C "$scratch/control" update-ref HEAD "$commit"
}
prepare_fixture() {
    GITHUB_STEP_SUMMARY="$scratch/summary.md" \
        bash "$scripts/prepare-baseline.sh" "$scratch/control" "$scratch/evidence" > "$scratch/output.log" 2>&1
}
expect_rejected() {
    local before after
    before="$(sha256sum "$scratch/control/$module")"
    if prepare_fixture; then
        echo "Accepted invalid baseline: $1" >&2; exit 1
    fi
    after="$(sha256sum "$scratch/control/$module")"
    [[ "$before" == "$after" ]]
}
commit_fixture
head="$(git -C "$scratch/control" rev-parse HEAD)"
prepare_fixture
jq -e '.state == "applied" and .committed_sha256 != .measured_sha256' "$scratch/evidence/baseline-normalization.json" > /dev/null
[[ "$(git -C "$scratch/control" rev-parse HEAD)" == "$head" ]]
[[ "$(git -C "$scratch/control" diff --name-only)" == "$module" ]]
cmp "$scratch/original.php" "$scratch/evidence/baseline-original-source.php.txt"
cmp "$scratch/control/$module" "$scratch/evidence/baseline-measured-source.php.txt"
[[ "$(jq -r '.measured_sha256' "$scratch/evidence/baseline-normalization.json")" == "$(sha256sum "$scratch/control/$module" | cut -d' ' -f1)" ]]
prepare_fixture
jq -e '.state == "already-normalized"' "$scratch/evidence/baseline-normalization.json" > /dev/null
commit_fixture
prepare_fixture
jq -e '.state == "not-needed"' "$scratch/evidence/baseline-normalization.json" > /dev/null
cp "$source_root/$module" "$scratch/control/$module"
commit_fixture
prepare_fixture
jq -e '.state == "not-needed"' "$scratch/evidence/baseline-normalization.json" > /dev/null
echo dirty >> "$scratch/control/unrelated.txt"
expect_rejected 'unrelated tracked edits'
git -C "$scratch/control" restore unrelated.txt
echo staged >> "$scratch/control/unrelated.txt"
git -C "$scratch/control" add unrelated.txt
expect_rejected 'staged edits'
git -C "$scratch/control" restore --staged unrelated.txt
git -C "$scratch/control" restore unrelated.txt
echo dirty >> "$scratch/control/$module"
expect_rejected 'unrecognized working source'
commit_fixture
expect_rejected 'unrecognized committed source'
cp "$scratch/original.php" "$scratch/control/$module"
commit_fixture
mkdir "$scratch/tampered"
cp "$scripts/prepare-baseline.sh" "$scripts/baseline-cancellation.diff" "$scratch/tampered/"
echo tampered >> "$scratch/tampered/baseline-cancellation.diff"
if bash "$scratch/tampered/prepare-baseline.sh" "$scratch/control" "$scratch/evidence" > "$scratch/output.log" 2>&1; then
    echo 'Accepted tampered patch.' >&2; exit 1
fi
cmp "$scratch/original.php" "$scratch/control/$module"
echo 'Baseline normalization preserves HEAD, pins source and rejects unreviewed changes.'
