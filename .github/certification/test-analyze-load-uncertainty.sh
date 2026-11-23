#!/usr/bin/env bash
set -euo pipefail
root="$(cd .github/certification && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
INPUT="$tmp/results.jsonl" php <<'PHP'
<?php
declare(strict_types=1);

$workloads = [
    'static', 'dynamic', 'json', 'stream', 'upload', 'not_found',
    'method_not_allowed', 'file', 'range', 'slow', 'risk_allow',
    'risk_counter', 'risk_clearance', 'risk_login', 'risk_login_invalid',
    'risk_provider_slow',
];
$output = fopen((string) getenv('INPUT'), 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to open synthetic fixture.');
}
foreach ($workloads as $workload) {
    foreach ([1, 5, 20, 50] as $concurrency) {
        foreach (['baseline', 'candidate'] as $version) {
            for ($trial = 1; $trial <= 4; ++$trial) {
                $rps = 100.0;
                if ($version === 'candidate' && $workload === 'json' && $concurrency === 5) {
                    $rps = 90.0;
                }
                if ($version === 'candidate' && $workload === 'file' && $concurrency === 20) {
                    $rps = ($trial % 2 === 0) ? 109.0 : 91.0;
                }
                $row = [
                    'version' => $version,
                    'mode' => 'runwire',
                    'workload' => $workload,
                    'concurrency' => $concurrency,
                    'trial' => $trial,
                    'rps' => $rps,
                    'p99' => $workload === 'risk_provider_slow' && $concurrency === 50
                        ? ($version === 'candidate' && $trial % 2 === 1 ? 5300.0 : 2730.0)
                        : 1.0,
                    'rss_kb' => 1024,
                    'complete' => 100,
                    'validated' => 100,
                    'invalid' => 0,
                    'failed' => 0,
                    'non2xx' => in_array(
                        $workload,
                        ['not_found', 'method_not_allowed', 'risk_login_invalid'],
                        true,
                    ) ? 100 : 0,
                    'socket_errors' => ['connect' => 0, 'read' => 0, 'write' => 0, 'timeout' => 0],
                    'metrics' => [
                        'pid' => 1,
                        'memory_current_bytes' => 100,
                        'memory_peak_bytes' => 100,
                        'requests_active' => 0,
                        'rejected_requests_total' => 0,
                        'queued_bytes_current' => 0,
                        'deferred_backlog' => 0,
                    ],
                ];
                fwrite($output, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            }
        }
    }
}
fclose($output);
PHP
if php "$root/analyze-load.php" \
    --input="$tmp/results.jsonl" --summary="$tmp/report.md" \
    --json="$tmp/report.json" --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32 > "$tmp/analyzer.log" 2>&1; then
    echo 'Synthetic 10% regression was incorrectly accepted.' >&2
    exit 1
fi
RESULT="$tmp/report.json" php <<'PHP'
<?php
declare(strict_types=1);

$report = json_decode(
    (string) file_get_contents((string) getenv('RESULT')),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
if (($report['expected_rows'] ?? null) !== 512 || ($report['observed_rows'] ?? null) !== 512) {
    throw new RuntimeException('Synthetic matrix completeness failed.');
}
if (!in_array(
    'runwire/json/c5: throughput regression 10.00% exceeds 2.00%.',
    $report['errors'] ?? [],
    true,
)) {
    throw new RuntimeException('Original hard acceptance was weakened.');
}
$checks = [
    'static/1' => ['non_inferior', 'steady_state'],
    'json/5' => ['regression_supported', 'steady_state'],
    'file/20' => ['inconclusive', 'file_or_stream_io'],
    'risk_login/50' => ['non_inferior', 'security_or_provider_contention'],
    'stream/50' => ['non_inferior', 'high_concurrency_stress'],
];
foreach ($report['comparisons'] as $row) {
    $key = $row['workload'] . '/' . $row['concurrency'];
    if (!isset($checks[$key])) {
        continue;
    }
    [$expectedStatus, $expectedScenario] = $checks[$key];
    if ($row['paired_throughput_ci95_diagnostic']['status'] !== $expectedStatus
        || $row['scenario'] !== $expectedScenario
        || $row['paired_p99_ci95_diagnostic']['status'] !== 'non_inferior'
        || $row['paired_throughput_ci95_diagnostic']['trial_pairs'] !== 4
    ) {
        throw new RuntimeException('Paired diagnostic classification mismatch: ' . $key);
    }
    unset($checks[$key]);
}
if ($checks !== []) {
    throw new RuntimeException('Missing expected synthetic comparisons.');
}
echo "Offline paired interval, scenario, and hard-rejection contracts verified.\n";
PHP


# Mutation generator preserves all 512 rows; no test is allowed to omit load.
make_case() {
    CASE="$1" SOURCE="$2" TARGET="$3" php <<'PHP'
<?php
declare(strict_types=1);
$lines = file((string) getenv('SOURCE'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$out = fopen((string) getenv('TARGET'), 'wb');
if ($lines === false || $out === false) {
    throw new RuntimeException('Unable to build certification case.');
}
foreach ($lines as $line) {
    $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    $case = (string) getenv('CASE');
    if ($case === 'remove_core_regression'
        && $row['workload'] === 'json' && $row['concurrency'] === 5
        && $row['version'] === 'candidate') {
        $row['rps'] = 100.0;
    }
    if ($case === 'stress_ceiling'
        && $row['workload'] === 'risk_provider_slow' && $row['concurrency'] === 50
        && $row['version'] === 'candidate' && $row['trial'] === 1) {
        $row['p99'] = 9000.0;
    }
    if ($case === 'core_p99'
        && $row['workload'] === 'json' && $row['concurrency'] === 5
        && $row['version'] === 'candidate') {
        $row['p99'] = 1.30;
    }
    if ($case === 'optional_p99'
        && $row['workload'] === 'risk_allow' && $row['concurrency'] === 5
        && $row['version'] === 'candidate') {
        $row['p99'] = 1.30;
    }
    if ($case === 'optional_ceiling'
        && $row['workload'] === 'risk_allow' && $row['concurrency'] === 5
        && $row['version'] === 'candidate' && $row['trial'] === 1) {
        $row['p99'] = 2000.0;
    }
    if ($case === 'stress_corrupt_response'
        && $row['workload'] === 'risk_provider_slow' && $row['concurrency'] === 50
        && $row['version'] === 'candidate' && $row['trial'] === 1) {
        $row['validated'] = 99;
        $row['invalid'] = 1;
    }
    fwrite($out, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
}
fclose($out);
PHP
}

# The historical-style bimodal provider p99 must stay VISIBLE in the report
# without vetoing a source-identical core A/A that is otherwise clean.
make_case remove_core_regression "$tmp/results.jsonl" "$tmp/stress-only.jsonl"
php "$root/analyze-load.php" --input="$tmp/stress-only.jsonl" \
    --summary="$tmp/stress.md" --json="$tmp/stress.json" \
    --comparison-kind=aa --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32
RESULT="$tmp/stress.json" php <<'PHP'
<?php
declare(strict_types=1);
$report = json_decode((string) file_get_contents((string) getenv('RESULT')), true, 512, JSON_THROW_ON_ERROR);
if ($report['errors'] !== [] || $report['comparison_kind'] !== 'aa') {
    throw new RuntimeException('Bimodal same-source provider must pass independent core and stress safety.');
}
$seen = [];
foreach ($report['comparisons'] as $row) {
    if ($row['workload'] === 'risk_provider_slow' && $row['concurrency'] === 50) {
        if ($row['acceptance_lane'] !== 'contention_stress'
            || $row['p99_regression_percent'] <= 15.0
            || !is_numeric($row['stress_p99_ceiling_ms'])
            || (float) $row['stress_p99_ceiling_ms'] !== 8000.0) {
            throw new RuntimeException('Raw stress tail comparison is missing or misclassified.');
        }
        $seen['stress'] = true;
    }
    if ($row['workload'] === 'static' && $row['acceptance_lane'] === 'webrick_core') {
        $seen['core'] = true;
    }
    if ($row['workload'] === 'risk_allow' && $row['acceptance_lane'] === 'opt_in_admission') {
        $seen['optional'] = true;
    }
}
if (count($seen) !== 3) {
    throw new RuntimeException('Default, optional admission and stress lanes were not represented.');
}
PHP

make_case stress_ceiling "$tmp/stress-only.jsonl" "$tmp/stress-breach.jsonl"
if php "$root/analyze-load.php" --input="$tmp/stress-breach.jsonl" \
    --summary="$tmp/stress-breach.md" --json="$tmp/stress-breach.json" \
    --comparison-kind=aa --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32 >"$tmp/stress-breach.log" 2>&1; then
    echo 'Over-limit provider p99 trial passed the independent stress safety gate.' >&2
    exit 1
fi
grep -q 'candidate trial 1 p99 9000.00 ms exceeds safety ceiling 8000.00 ms' "$tmp/stress-breach.log"

make_case core_p99 "$tmp/stress-only.jsonl" "$tmp/core-breach.jsonl"
if php "$root/analyze-load.php" --input="$tmp/core-breach.jsonl" \
    --summary="$tmp/core-breach.md" --json="$tmp/core-breach.json" \
    --comparison-kind=ab --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32 >"$tmp/core-breach.log" 2>&1; then
    echo 'Default-route core p99 regression was incorrectly accepted.' >&2
    exit 1
fi
grep -q 'runwire/json/c5: p99 regression 30.00% exceeds 15.00%' "$tmp/core-breach.log"

# Admission-only overhead is visible but cannot fail the default-router budget.
make_case optional_p99 "$tmp/stress-only.jsonl" "$tmp/optional-p99.jsonl"
php "$root/analyze-load.php" --input="$tmp/optional-p99.jsonl" \
    --summary="$tmp/optional-p99.md" --json="$tmp/optional-p99.json" \
    --comparison-kind=ab --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32
RESULT="$tmp/optional-p99.json" php <<'PHP'
<?php
declare(strict_types=1);
$report = json_decode((string) file_get_contents((string) getenv('RESULT')), true, 512, JSON_THROW_ON_ERROR);
if ($report['errors'] !== []) {
    throw new RuntimeException('Optional admission overhead blocked default-routing certification.');
}
$found = false;
foreach ($report['comparisons'] as $row) {
    if ($row['workload'] === 'risk_allow' && $row['concurrency'] === 5) {
        $found = $row['acceptance_lane'] === 'opt_in_admission'
            && $row['p99_regression_percent'] > 15.0
            && (float) $row['safety_p99_ceiling_ms'] === 1600.0;
    }
}
if (!$found) {
    throw new RuntimeException('Optional admission overhead was not reported separately.');
}
PHP

# Optional routes must still fail their independent hard latency safety gate.
make_case optional_ceiling "$tmp/stress-only.jsonl" "$tmp/optional-ceiling.jsonl"
if php "$root/analyze-load.php" --input="$tmp/optional-ceiling.jsonl" \
    --summary="$tmp/optional-ceiling.md" --json="$tmp/optional-ceiling.json" \
    --comparison-kind=ab --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32 >"$tmp/optional-ceiling.log" 2>&1; then
    echo 'Over-limit optional admission p99 passed the hard safety gate.' >&2
    exit 1
fi
grep -q 'runwire/risk_allow/c5: candidate trial 1 p99 2000.00 ms exceeds safety ceiling 1600.00 ms' "$tmp/optional-ceiling.log"

make_case stress_corrupt_response "$tmp/stress-only.jsonl" "$tmp/stress-corrupt.jsonl"
if php "$root/analyze-load.php" --input="$tmp/stress-corrupt.jsonl" \
    --summary="$tmp/stress-corrupt.md" --json="$tmp/stress-corrupt.json" \
    --comparison-kind=aa --profile=runwire --trials=4 \
    --throughput-regression-percent=2 --p99-regression-percent=15 \
    --memory-regression-mb=32 >"$tmp/stress-corrupt.log" 2>&1; then
    echo 'Invalid risk-provider stress response was incorrectly accepted.' >&2
    exit 1
fi
grep -q 'response validation did not prove every complete response' "$tmp/stress-corrupt.log"

echo 'Default-routing budgets, optional admission overhead and protected-route safety passed.'
