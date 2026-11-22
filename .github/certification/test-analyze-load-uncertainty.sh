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
                    'p99' => 1.0,
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
