#!/usr/bin/env bash
set -euo pipefail
ROOT="$(realpath "$1")"
OUT="$2"
VARIANT="$3"
case "$VARIANT" in released|notice_fix) ;; *) exit 2 ;; esac
mkdir -p "$OUT"
OUT="$(realpath "$OUT")"
test "$(git -C "$ROOT" rev-parse HEAD)" = 981d095251dc3bf9c65c434f0d84aecb38025f88
export RUNWIRE_VENDOR="$ROOT/vendor/infocyph/runwire" DIAGNOSTIC_VARIANT="$VARIANT"

# Temporary vendor patch only: exception types and locations, no secrets.
php <<'PHP'
<?php
declare(strict_types=1);
$vendor = getenv('RUNWIRE_VENDOR');
$variant = getenv('DIAGNOSTIC_VARIANT');
$worker = $vendor . '/src/Supervisor/Internal/WorkerChildRuntime.php';
$source = file_get_contents($worker);
$needle = "        } catch (Throwable \$error) {\n            \$warmupFailure =";
$replacement = <<<'REPLACEMENT'
        } catch (Throwable $error) {
            for ($failure = $error, $depth = 0; $failure !== null && $depth < 3; $failure = $failure->getPrevious(), ++$depth) {
                error_log(sprintf(
                    'RUNWIRE_WORKER_FATAL depth=%d type=%s file=%s line=%d',
                    $depth,
                    $failure::class,
                    basename($failure->getFile()),
                    $failure->getLine(),
                ));
            }
            $warmupFailure =
REPLACEMENT;
if (!is_string($source) || substr_count($source, $needle) !== 1) {
    throw new RuntimeException('Unexpected Runwire 3.0.1 worker source.');
}
file_put_contents($worker, str_replace($needle, $replacement, $source));
if ($variant === 'notice_fix') {
    $path = $vendor . '/src/Network/Internal/StreamWriter.php';
    $writer = file_get_contents($path);
    $from = "return \$severity === E_WARNING && str_starts_with(\$message, 'fwrite():');";
    $to = "return (\$severity === E_WARNING || \$severity === E_NOTICE) && str_starts_with(\$message, 'fwrite():');";
    if (!is_string($writer) || substr_count($writer, $from) !== 1) {
        throw new RuntimeException('Unexpected Runwire 3.0.1 StreamWriter source.');
    }
    file_put_contents($path, str_replace($from, $to, $writer));
}
PHP
php -l "$RUNWIRE_VENDOR/src/Supervisor/Internal/WorkerChildRuntime.php"
php -l "$RUNWIRE_VENDOR/src/Network/Internal/StreamWriter.php"
printf 'source=981d095\nrunwire=3.0.1\nvariant=%s\n' "$VARIANT" > "$OUT/provenance.txt"
php "$ROOT/.github/certification/cert-server.php" prepare "--root=$ROOT"

cat > "$OUT/upload.lua" <<'LUA'
wrk.method = "POST"
wrk.body = string.rep("x", 16384)
wrk.headers["Content-Type"] = "application/octet-stream"
LUA
cat > "$OUT/method.lua" <<'LUA'
wrk.method = "POST"
LUA
port=18082
WEBRICK_CERT_ROOT="$ROOT" php "$ROOT/.github/certification/cert-server.php" runwire \
    "--root=$ROOT" "--address=127.0.0.1:$port" "--recycle=0" > "$OUT/server.log" 2>&1 &
pid=$!
trap 'kill -TERM "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true' EXIT
for i in $(seq 1 100); do
    curl -fsS --max-time 1 "http://127.0.0.1:$port/cert/static" >/dev/null 2>&1 && break
    sleep 0.1
done
curl -fsS --max-time 2 "http://127.0.0.1:$port/cert/static" >/dev/null
events="$ROOT/.runtime-certification/router.php.supervisor-events.jsonl"
test -f "$events"
printf 'workload\tconcurrency\tworker_fatal\n' > "$OUT/stress.tsv"
for c in 1 5 20 50; do
    for workload in static dynamic json stream upload missing method file range slow; do
        case "$workload" in
            static|method) path=/cert/static ;;
            dynamic) path=/cert/dynamic/42 ;;
            json) path=/cert/json ;;
            stream) path=/cert/stream ;;
            upload) path=/cert/upload ;;
            missing) path=/cert/missing ;;
            file|range) path=/cert/file ;;
            slow) path=/cert/slow/5 ;;
        esac
        echo "Runwire stress $workload c$c"
        if [ "$workload" = upload ]; then
            wrk -t2 -c"$c" -d10s -s "$OUT/upload.lua" "http://127.0.0.1:$port$path" > "$OUT/wrk-$workload-c$c.txt" 2>&1 || true
        elif [ "$workload" = method ]; then
            wrk -t2 -c"$c" -d10s -s "$OUT/method.lua" "http://127.0.0.1:$port$path" > "$OUT/wrk-$workload-c$c.txt" 2>&1 || true
        elif [ "$workload" = range ]; then
            wrk -t2 -c"$c" -d10s -H 'Range: bytes=0-15' "http://127.0.0.1:$port$path" > "$OUT/wrk-$workload-c$c.txt" 2>&1 || true
        else
            wrk -t2 -c"$c" -d10s "http://127.0.0.1:$port$path" > "$OUT/wrk-$workload-c$c.txt" 2>&1 || true
        fi
        if jq -s -e 'any(.[]; .type == "worker_exited" and .expected == false)' "$events" >/dev/null; then
            printf '%s\t%s\t1\n' "$workload" "$c" >> "$OUT/stress.tsv"
            echo "UNEXPECTED_WORKER_EXIT at $workload c$c" >&2
            break 2
        fi
        printf '%s\t%s\t0\n' "$workload" "$c" >> "$OUT/stress.tsv"
    done
done
cp "$events" "$OUT/supervisor-events.jsonl"
if jq -s -e 'any(.[]; .type == "worker_exited" and .expected == false)' "$events" >/dev/null; then
    grep RUNWIRE_WORKER_FATAL "$OUT/server.log" | tail -n 12 || true
    exit 1
fi
curl -fsS --max-time 2 "http://127.0.0.1:$port/cert/static" >/dev/null
echo "No unexpected worker exits in selected stress."
