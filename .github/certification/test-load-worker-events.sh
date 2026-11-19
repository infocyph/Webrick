#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/load-worker-events.sh"
EVENT_TEST_DIR="$(mktemp -d)"
EVENT_TEST_WRITER_PID=""
cleanup_event_test() {
    if [[ -n "$EVENT_TEST_WRITER_PID" ]]; then
        wait "$EVENT_TEST_WRITER_PID" 2>/dev/null || true
    fi
    rm -rf "$EVENT_TEST_DIR"
}
trap cleanup_event_test EXIT

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

# A healthy shutdown record is briefly incomplete while its writer owns the
# same exclusive lock used by the real PHP supervisor-event callback.
printf '%s\n' '{"type":"worker_ready"}' > "$EVENT_TEST_DIR/concurrent.jsonl"
(
    flock --exclusive 9
    printf '%s' '{"type":"worker_exited",'
    : > "$EVENT_TEST_DIR/writing"
    sleep 0.2
    printf '%s\n' '"expected":true,"exit_code":0}'
) 9<>"$EVENT_TEST_DIR/concurrent.jsonl" >> "$EVENT_TEST_DIR/concurrent.jsonl" &
EVENT_TEST_WRITER_PID=$!
for _ in $(seq 1 100); do
    if [[ -f "$EVENT_TEST_DIR/writing" ]]; then
        break
    fi
    sleep 0.01
done
test -f "$EVENT_TEST_DIR/writing"
assert_load_worker_events "$EVENT_TEST_DIR/concurrent.jsonl"
wait "$EVENT_TEST_WRITER_PID"
EVENT_TEST_WRITER_PID=""
echo "Load lifecycle validation rejects worker failures and incomplete evidence."
