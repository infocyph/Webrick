<?php

declare(strict_types=1);

/** @return list<array<string,mixed>> */
function load_rows(string $path): array
{
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($row)) {
            throw new RuntimeException('Load result row must be a JSON object.');
        }
        $rows[] = $row;
    }

    return $rows;
}

/** @param list<float|int> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }
    $middle = intdiv($count, 2);

    return $count % 2 === 0
        ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
        : (float) $values[$middle];
}

/** @param list<float|int> $values */
function coefficient_of_variation(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }
    $mean = array_sum($values) / $count;
    if ($mean <= 0) {
        return 0.0;
    }
    $sum = 0.0;
    foreach ($values as $value) {
        $sum += ((float) $value - $mean) ** 2;
    }

    return sqrt($sum / ($count - 1)) / $mean * 100;
}

/** @return array<string,string> */
function cli_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $entry) {
        if (!str_starts_with($entry, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($entry, 2), 2), 2, '');
        $options[$key] = $value;
    }

    return $options;
}

$options = cli_options($argv);
$input = $options['input'] ?? '';
$summaryPath = $options['summary'] ?? '';
$jsonPath = $options['json'] ?? '';
$throughputBudget = (float) ($options['throughput-regression-percent'] ?? 2);
$p99Budget = (float) ($options['p99-regression-percent'] ?? 15);
$memoryBudgetMb = (float) ($options['memory-regression-mb'] ?? 32);

if ($input === '' || $summaryPath === '' || $jsonPath === '') {
    throw new RuntimeException('Expected --input, --summary and --json paths.');
}

$groups = [];
$errors = [];
foreach (load_rows($input) as $row) {
    $version = (string) ($row['version'] ?? '');
    $mode = (string) ($row['mode'] ?? '');
    $workload = (string) ($row['workload'] ?? '');
    $concurrency = (int) ($row['concurrency'] ?? 0);
    $key = implode('|', [$mode, $workload, (string) $concurrency, $version]);
    $groups[$key][] = $row;

    $complete = (int) ($row['complete'] ?? 0);
    $validated = (int) ($row['validated'] ?? -1);
    $invalid = (int) ($row['invalid'] ?? -1);
    $non2xx = (int) ($row['non2xx'] ?? 0);
    $socketErrors = is_array($row['socket_errors'] ?? null) ? $row['socket_errors'] : [];
    $connectErrors = (int) ($socketErrors['connect'] ?? -1);
    $readErrors = (int) ($socketErrors['read'] ?? -1);
    $writeErrors = (int) ($socketErrors['write'] ?? -1);
    $timeoutErrors = (int) ($socketErrors['timeout'] ?? -1);
    $failed = (int) ($row['failed'] ?? -1);
    $socketErrorTotal = $connectErrors + $readErrors + $writeErrors + $timeoutErrors;
    $validatedCloseDelimitedStream = $mode === 'sapi'
        && $workload === 'stream'
        && $complete > 0
        && $validated === $complete
        && $invalid === 0
        && $readErrors === $complete
        && $connectErrors === 0
        && $writeErrors === 0
        && $timeoutErrors === 0
        && $non2xx === 0;

    if ($complete <= 0) {
        $errors[] = "{$key}: no complete HTTP responses were measured.";
    }
    if ($validated !== $complete || $invalid !== 0) {
        $errors[] = "{$key}: response validation did not prove every complete response.";
    }
    if ($non2xx !== 0) {
        $errors[] = "{$key}: unexpected non-2xx/3xx responses were observed.";
    }
    if ($failed !== $socketErrorTotal) {
        $errors[] = "{$key}: socket-error totals are incomplete or inconsistent.";
    }
    if (!$validatedCloseDelimitedStream && $socketErrorTotal !== 0) {
        $errors[] = "{$key}: transport socket errors were observed.";
    }

    $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
    foreach ([
        'pid',
        'memory_current_bytes',
        'memory_peak_bytes',
        'requests_active',
        'queued_bytes_current',
        'rejected_requests_total',
    ] as $metric) {
        if (!isset($metrics[$metric]) || !is_int($metrics[$metric])) {
            $errors[] = "{$key}: required telemetry '{$metric}' is missing or invalid.";
        }
    }
    if (($metrics['queued_bytes_current'] ?? null) !== 0) {
        $errors[] = "{$key}: queued bytes did not drain to zero.";
    }
    if (($metrics['rejected_requests_total'] ?? null) !== 0) {
        $errors[] = "{$key}: rejected requests were observed.";
    }
}

$aggregates = [];
foreach ($groups as $key => $rows) {
    [$mode, $workload, $concurrency, $version] = explode('|', $key, 4);
    $rps = array_map(static fn(array $row): float => (float) ($row['rps'] ?? 0), $rows);
    $p99 = array_map(static fn(array $row): float => (float) ($row['p99'] ?? 0), $rows);
    $rss = array_map(static fn(array $row): float => (float) ($row['rss_kb'] ?? 0), $rows);

    $aggregates[$mode][$workload][$concurrency][$version] = [
        'median_rps' => median($rps),
        'median_rpm' => median($rps) * 60,
        'rps_cv_percent' => coefficient_of_variation($rps),
        'median_p99_ms' => median($p99),
        'median_rss_kb' => median($rss),
    ];
}

$comparisons = [];
foreach ($aggregates as $mode => $workloads) {
    foreach ($workloads as $workload => $concurrencies) {
        foreach ($concurrencies as $concurrency => $versions) {
            $baseline = $versions['baseline'] ?? null;
            $candidate = $versions['candidate'] ?? null;
            if (!is_array($baseline) || !is_array($candidate)) {
                $errors[] = "{$mode}/{$workload}/c{$concurrency}: missing baseline or candidate rows.";
                continue;
            }

            $baselineRps = (float) $baseline['median_rps'];
            $candidateRps = (float) $candidate['median_rps'];
            $throughputRegression = $baselineRps > 0
                ? (($baselineRps - $candidateRps) / $baselineRps) * 100
                : 0.0;
            $baselineP99 = (float) $baseline['median_p99_ms'];
            $candidateP99 = (float) $candidate['median_p99_ms'];
            $p99Regression = $baselineP99 > 0
                ? (($candidateP99 - $baselineP99) / $baselineP99) * 100
                : 0.0;
            $memoryDeltaMb = ((float) $candidate['median_rss_kb'] - (float) $baseline['median_rss_kb']) / 1024;

            if ($throughputRegression > $throughputBudget) {
                $errors[] = sprintf(
                    '%s/%s/c%d: throughput regression %.2f%% exceeds %.2f%%.',
                    $mode,
                    $workload,
                    $concurrency,
                    $throughputRegression,
                    $throughputBudget,
                );
            }
            if ($p99Regression > $p99Budget) {
                $errors[] = sprintf(
                    '%s/%s/c%d: p99 regression %.2f%% exceeds %.2f%%.',
                    $mode,
                    $workload,
                    $concurrency,
                    $p99Regression,
                    $p99Budget,
                );
            }
            if ($memoryDeltaMb > $memoryBudgetMb) {
                $errors[] = sprintf(
                    '%s/%s/c%d: RSS regression %.2f MiB exceeds %.2f MiB.',
                    $mode,
                    $workload,
                    $concurrency,
                    $memoryDeltaMb,
                    $memoryBudgetMb,
                );
            }

            $comparisons[] = [
                'mode' => $mode,
                'workload' => $workload,
                'concurrency' => (int) $concurrency,
                'baseline_rpm' => $baseline['median_rpm'],
                'candidate_rpm' => $candidate['median_rpm'],
                'throughput_regression_percent' => $throughputRegression,
                'baseline_p99_ms' => $baselineP99,
                'candidate_p99_ms' => $candidateP99,
                'p99_regression_percent' => $p99Regression,
                'candidate_rps_cv_percent' => $candidate['rps_cv_percent'],
                'rss_delta_mb' => $memoryDeltaMb,
            ];
        }
    }
}

$report = [
    'budgets' => [
        'throughput_regression_percent' => $throughputBudget,
        'p99_regression_percent' => $p99Budget,
        'memory_regression_mb' => $memoryBudgetMb,
    ],
    'comparisons' => $comparisons,
    'errors' => array_values(array_unique($errors)),
];
file_put_contents(
    $jsonPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

$markdown = [
    '# Webrick 5.4 → 6.0 HTTP certification',
    '',
    sprintf(
        'Budgets: throughput regression ≤ %.2f%%, p99 regression ≤ %.2f%%, RSS regression ≤ %.2f MiB.',
        $throughputBudget,
        $p99Budget,
        $memoryBudgetMb,
    ),
    '',
    '| Mode | Workload | C | 5.4 RPM | 6.0 RPM | Δ throughput | 5.4 p99 ms | 6.0 p99 ms | Δ p99 | RSS Δ MiB | 6.0 RPS CV |',
    '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
];
foreach ($comparisons as $row) {
    $markdown[] = sprintf(
        '| %s | %s | %d | %.0f | %.0f | %.2f%% | %.1f | %.1f | %.2f%% | %.2f | %.2f%% |',
        $row['mode'],
        $row['workload'],
        $row['concurrency'],
        $row['baseline_rpm'],
        $row['candidate_rpm'],
        $row['throughput_regression_percent'],
        $row['baseline_p99_ms'],
        $row['candidate_p99_ms'],
        $row['p99_regression_percent'],
        $row['rss_delta_mb'],
        $row['candidate_rps_cv_percent'],
    );
}
if ($errors !== []) {
    $markdown[] = '';
    $markdown[] = '## Failures';
    foreach (array_values(array_unique($errors)) as $error) {
        $markdown[] = '- ' . $error;
    }
}
file_put_contents($summaryPath, implode("\n", $markdown) . "\n");

if ($errors !== []) {
    $message = implode("\n", array_values(array_unique($errors)));
    fwrite(STDERR, $message . "\n");

    throw new RuntimeException('HTTP performance acceptance failed.');
}
