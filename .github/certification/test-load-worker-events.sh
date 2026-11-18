#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/load-worker-events.sh"
EVENT_TEST_DIR="$(mktemp -d)"
trap 'rm -rf "$EVENT_TEST_DIR"' EXIT

expect_rejected_events() {
    if assert_load_worker_events "$1" 2>/dev/null; then
        echo "Worker lifecycle validator accepted invalid evidence: $1" >&2
        exit 1
    fi
}

printf '%s\n' '{"type":"worker_ready"}' '{"type":"worker_exited","expected":true,"exit_code":0}' > "$EVENT_TEST_DIR/healthy.jsonl"
assert_load_worker_events "$EVENT_TEST_DIR/healthy.jsonl"
expect_rejected_events "$EVENT_TEST_DIR/missing.jsonl"
: > "$EVENT_TEST_DIR/empty.jsonl"
expect_rejected_events "$EVENT_TEST_DIR/empty.jsonl"
printf '%s\n' '{' > "$EVENT_TEST_DIR/malformed.jsonl"
expect_rejected_events "$EVENT_TEST_DIR/malformed.jsonl"
printf '%s\n' '{"type":"worker_exited","expected":true}' > "$EVENT_TEST_DIR/not-ready.jsonl"
expect_rejected_events "$EVENT_TEST_DIR/not-ready.jsonl"

for event in worker_unhealthy worker_restart_scheduled worker_exited; do
    printf '%s\n' '{"type":"worker_ready"}' "{\"type\":\"$event\",\"expected\":false,\"exit_code\":70}" > "$EVENT_TEST_DIR/$event.jsonl"
    expect_rejected_events "$EVENT_TEST_DIR/$event.jsonl"
done
printf '%s\n' '{"type":"worker_ready"}' '{"type":"worker_exited"}' > "$EVENT_TEST_DIR/unknown-exit.jsonl"
expect_rejected_events "$EVENT_TEST_DIR/unknown-exit.jsonl"
echo "Load lifecycle validation rejects worker failures and incomplete evidence."
