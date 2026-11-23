#!/usr/bin/env bash
set -euo pipefail

HARNESS_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HARNESS_DIRECTORY/load-rss-sampler.sh"
FIXTURE="$(mktemp -d)"
fixture_pid=''
cleanup() {
    touch "$FIXTURE/stop"
    if [[ -n "$fixture_pid" ]]; then wait "$fixture_pid" || true; fi
    rm -rf "$FIXTURE"
}
trap cleanup EXIT

init_rss_sampling
cat > "$FIXTURE/tree.php" <<'PHP'
<?php
[$script, $directory, $level] = $argv;
$memory = str_repeat('x', 16 * 1024 * 1024);
$child = $level < 2 ? proc_open([PHP_BINARY, $script, $directory, (string) ($level + 1)], [], $pipes) : null;
file_put_contents("$directory/pid-$level", (string) getmypid());
while (!file_exists("$directory/stop")) { usleep(10000); clearstatcache(); }
if (is_resource($child)) { proc_close($child); }
PHP
php "$FIXTURE/tree.php" "$FIXTURE" 0 &
fixture_pid=$!
for _ in {1..100}; do
    [[ -s "$FIXTURE/pid-0" && -s "$FIXTURE/pid-1" && -s "$FIXTURE/pid-2" ]] && break
    sleep 0.02
done
[[ -s "$FIXTURE/pid-0" && -s "$FIXTURE/pid-1" && -s "$FIXTURE/pid-2" ]]
child_pid="$(cat "$FIXTURE/pid-1")"
grandchild_pid="$(cat "$FIXTURE/pid-2")"
expected="$(ps -o rss= -p "$fixture_pid,$child_pid,$grandchild_pid" | awk '{sum += $1} END {print sum}')"
actual="$(rss_kb "$fixture_pid")"
(( actual > 48000 && actual >= expected - 256 && actual <= expected + 256 ))
touch "$FIXTURE/stop"
wait "$fixture_pid"
if rss_kb "$fixture_pid" > /dev/null 2>&1; then
    echo 'A missing server process was accepted.' >&2
    exit 1
fi
fixture_pid=''
if rss_kb invalid > /dev/null 2>&1; then exit 1; fi
printf 'Real parent, child and grandchild RSS matches ps; missing servers fail.\n'

rss_kb() {
    if [[ -f "$FIXTURE/running" ]]; then
        echo 100000
    else
        echo 60000
    fi
}

load_fixture() {
    touch "$FIXTURE/running"
    sleep 0.75
    rm "$FIXTURE/running"
    return "$1"
}

# A worker replacement after client close must not erase its in-load RSS peak.
run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" load_fixture 0
[[ "$(tail -n 1 "$FIXTURE/rss.txt")" == 60000 ]]
[[ "$(awk 'max < $1 { max = $1 } END { print max }' "$FIXTURE/rss.txt")" == 100000 ]]

if run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" load_fixture 22; then
    echo 'A failed load command was accepted.' >&2
    exit 1
else
    [[ "$?" == 22 ]]
fi

printf 'Load sampling retains in-load RSS peaks and the load command exit status.\n'

rss_kb() {
    [[ ! -f "$FIXTURE/load-pid" ]] || return 23
    echo 60000
}
if run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" \
    bash -c 'echo $$ > "$1/load-pid"; exec sleep 30' bash "$FIXTURE"; then
    echo 'A failed RSS sample was accepted.' >&2
    exit 1
else
    [[ "$?" == 23 ]]
fi
load_pid="$(cat "$FIXTURE/load-pid")"
if kill -0 "$load_pid" 2>/dev/null; then
    kill -KILL "$load_pid"
    echo 'The sampler left its load process running.' >&2
    exit 1
fi
printf 'Sampling failures terminate and reap the owned load process.\n'

if run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" touch "$FIXTURE/unexpected-load"; then
    exit 1
else
    [[ "$?" == 23 && ! -f "$FIXTURE/unexpected-load" ]]
fi

rss_kb() {
    [[ ! -f "$FIXTURE/finished" ]] || return 24
    echo 60000
}
if run_load_with_rss 1 "$FIXTURE/rss.txt" "$FIXTURE/load.txt" touch "$FIXTURE/finished"; then
    exit 1
else
    [[ "$?" == 24 ]]
fi
printf 'Sampling failures before and after load remain failures.\n'
