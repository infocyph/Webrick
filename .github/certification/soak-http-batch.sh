#!/usr/bin/env bash

run_http_batch() {
    local count="$1"
    local concurrency="$2"
    local method="$3"
    local url="$4"
    local failures=0
    local request_pid
    local -a request_pids=()

    for _ in $(seq 1 "$count"); do
        if [[ "$method" == "POST" ]]; then
            curl --http1.1 --fail --silent --show-error --max-time 5 \
                --output /dev/null \
                --header 'Content-Type: application/octet-stream' \
                --data-binary "@$PAYLOAD" \
                "$url" &
        else
            curl --http1.1 --fail --silent --show-error --max-time 5 \
                --output /dev/null \
                "$url" &
        fi
        request_pids+=("$!")

        if (( ${#request_pids[@]} >= concurrency )); then
            for request_pid in "${request_pids[@]}"; do
                if ! wait "$request_pid"; then
                    failures=$((failures + 1))
                fi
            done
            request_pids=()
        fi
    done

    for request_pid in "${request_pids[@]}"; do
        if ! wait "$request_pid"; then
            failures=$((failures + 1))
        fi
    done

    if (( failures != 0 )); then
        echo "Bounded HTTP batch recorded ${failures} failed request(s)." >&2
        return 1
    fi
}
