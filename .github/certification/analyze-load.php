<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Load analysis requires the CLI.');
}

/**
 * Resolve the evidence file explicitly selected by the command-line operator.
 * The file contents retain all their data taints; this grants only local path access.
 * URL taints describe network authority here; this is not a URL encoder.
 *
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 * @psalm-taint-escape url_path
 * @throws \RuntimeException
 */
function load_input_path(string $path): string
{
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '//') || str_starts_with($path, '\\\\') || preg_match('~^[a-z][a-z0-9.+-]*://~i', $path) === 1) {
        throw new RuntimeException('Load results must be a local file.');
    }
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved)) {
        throw new RuntimeException('Load results must be a local file.');
    }

    return $resolved;
}

/**
 * Grant writes to an operator-selected report filename in an existing local directory.
 * File data and non-filesystem taints are not declassified.
 * URL taints describe network authority here; this is not a URL encoder.
 *
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 * @psalm-taint-escape url_path
 * @throws \RuntimeException
 */
function report_output_path(string $path): string
{
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '//') || str_starts_with($path, '\\\\') || preg_match('~^[a-z][a-z0-9.+-]*://~i', $path) === 1) {
        throw new RuntimeException('Report output must be a local file.');
    }
    $directory = realpath(dirname($path));
    if ($directory === false || !is_dir($directory) || in_array(basename($path), ['.', '..'], true)) {
        throw new RuntimeException('Report output directory must exist.');
    }

    return $directory . DIRECTORY_SEPARATOR . basename($path);
}

/**
 * @return list<array<string,mixed>>
 * @throws \RuntimeException
 */
function load_rows(string $path): array
{
    $localPath = load_input_path($path);

    $rows = [];
    $lines = file($localPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read load result rows.');
    }
    foreach ($lines as $line) {
        if (!str_starts_with(ltrim($line), '{')) {
            throw new RuntimeException('Load result row must be a JSON object.');
        }
        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($row)) {
            throw new RuntimeException('Load result row must be a JSON object.');
        }
        foreach (array_keys($row) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('Load result row keys must be strings.');
            }
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
        ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0
        : (float) $values[$middle];
}

/** @param list<float|int> $values */
function coefficient_of_variation(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }
    $mean = array_sum($values) / (float) $count;
    if ($mean <= 0) {
        return 0.0;
    }
    $sum = 0.0;
    foreach ($values as $value) {
        $sum += ((float) $value - $mean) ** 2;
    }

    return sqrt($sum / (float) ($count - 1)) / $mean * 100.0;
}


/**
 * Exploratory paired-trial log-ratio CI, not a replacement for hard budgets.
 *
 * p99 samples represent per-trial tails, never pooled request histograms.
 *
 * @param list<array{trial:int,rps:float,p99_ms:float}> $baseline
 * @param list<array{trial:int,rps:float,p99_ms:float}> $candidate
 * @return array{status:string,trial_pairs:int,estimate_ratio?:float,lower_ratio?:float,upper_ratio?:float}
 */
function paired_trial_interval(array $baseline, array $candidate, string $metric, float $objective): array
{
    $count = count($baseline);
    $critical = match ($count) {
        4 => 3.182446,
        8 => 2.364624,
        12 => 2.200985,
        default => null,
    };
    if ($critical === null || count($candidate) !== $count) {
        return ['status' => 'insufficient_or_mismatched_trials', 'trial_pairs' => $count];
    }

    $logs = [];
    foreach ($baseline as $index => $sample) {
        $comparison = $candidate[$index];
        if ($sample['trial'] !== $comparison['trial']) {
            return ['status' => 'unpaired_trials', 'trial_pairs' => $count];
        }

        $original = $sample[$metric];
        $updated = $comparison[$metric];
        if ($original <= 0.0 || $updated <= 0.0) {
            return ['status' => 'unavailable_nonpositive_metric', 'trial_pairs' => $count];
        }
        $logs[] = log((float) $updated / (float) $original);
    }

    $average = array_sum($logs) / (float) $count;
    $squared = 0.0;
    foreach ($logs as $value) {
        $squared += ($value - $average) ** 2;
    }
    $halfWidth = $critical * sqrt($squared / ((float) ($count - 1) * (float) $count));
    $estimate = exp($average);
    $lower = exp($average - $halfWidth);
    $upper = exp($average + $halfWidth);
    if (!is_finite($lower) || !is_finite($upper) || !is_finite($estimate)) {
        return ['status' => 'nonfinite_interval', 'trial_pairs' => $count];
    }

    $status = match ($metric) {
        'rps' => $lower >= $objective ? 'non_inferior'
            : ($upper < $objective ? 'regression_supported' : 'inconclusive'),
        'p99_ms' => $upper <= $objective ? 'non_inferior'
            : ($lower > $objective ? 'regression_supported' : 'inconclusive'),
        default => 'unknown_metric',
    };

    return [
        'status' => $status,
        'trial_pairs' => $count,
        'estimate_ratio' => $estimate,
        'lower_ratio' => $lower,
        'upper_ratio' => $upper,
    ];
}

/** Diagnostic scenarios are distinct from the hard acceptance lanes. */
function load_scenario(string $workload, int $concurrency): string
{
    if (in_array($workload, ['risk_login', 'risk_login_invalid', 'risk_provider_slow', 'slow'], true)) {
        return 'security_or_provider_contention';
    }

    if ($concurrency === 50) {
        return 'high_concurrency_stress';
    }

    return in_array($workload, ['file', 'range', 'stream', 'upload'], true)
        ? 'file_or_stream_io'
        : 'steady_state';
}

/**
 * Default routes alone define the framework's release-performance contract.
 * Explicitly protected routes measure optional admission or host-owned stress,
 * never the cost of serving ordinary application routes.
 */
function acceptance_lane(string $workload): string
{
    if ($workload === 'risk_allow') {
        return 'opt_in_admission';
    }

    return in_array($workload, [
        'slow', 'risk_counter', 'risk_clearance',
        'risk_login', 'risk_login_invalid', 'risk_provider_slow',
    ], true) ? 'contention_stress' : 'webrick_core';
}

/**
 * Per-trial stress safety limits: 80% of the existing client timeouts
 * (10 s for bcrypt/provider; 2 s for other stress). Not router regressions.
 */
function protected_p99_ceiling_ms(string $workload): float
{
    return in_array($workload, ['risk_login', 'risk_login_invalid', 'risk_provider_slow'], true)
        ? 8_000.0
        : 1_600.0;
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

/** @return array<string,true> */
function expected_rows(string $profile, int $trials): array
{
    $modes = $profile === 'all' ? ['sapi', 'runwire'] : [$profile];
    $versions = ['baseline', 'candidate'];
    $workloads = [
        'static',
        'dynamic',
        'json',
        'stream',
        'upload',
        'not_found',
        'method_not_allowed',
        'file',
        'range',
        'slow',
        'risk_allow',
        'risk_counter',
        'risk_clearance',
        'risk_login',
        'risk_login_invalid',
        'risk_provider_slow',
    ];
    $concurrencies = [1, 5, 20, 50];
    $expected = [];

    foreach ($modes as $mode) {
        foreach ($workloads as $workload) {
            foreach ($concurrencies as $concurrency) {
                foreach ($versions as $version) {
                    for ($trial = 1; $trial <= $trials; ++$trial) {
                        $expected[implode('|', [
                            $mode,
                            $workload,
                            (string) $concurrency,
                            $version,
                            (string) $trial,
                        ])] = true;
                    }
                }
            }
        }
    }

    return $expected;
}

function valid_measurement(mixed $value, bool $allowZero = true): bool
{
    if (!is_int($value) && !is_float($value)) {
        return false;
    }

    $number = (float) $value;
    if (!is_finite($number)) {
        return false;
    }

    return $allowZero ? $number >= 0.0 : $number > 0.0;
}

$options = cli_options($argv);
$input = $options['input'] ?? '';
$summaryPath = $options['summary'] ?? '';
$jsonPath = $options['json'] ?? '';
$throughputBudget = (float) ($options['throughput-regression-percent'] ?? 2);
$p99Budget = (float) ($options['p99-regression-percent'] ?? 15);
$memoryBudgetMb = (float) ($options['memory-regression-mb'] ?? 32);
$profile = $options['profile'] ?? 'all';
$trials = (int) ($options['trials'] ?? 3);
$comparisonKind = $options['comparison-kind'] ?? 'ab';

if ($input === '' || $summaryPath === '' || $jsonPath === '') {
    throw new RuntimeException('Expected --input, --summary and --json paths.');
}
$summaryPath = report_output_path($summaryPath);
$jsonPath = report_output_path($jsonPath);
if (!in_array($profile, ['all', 'sapi', 'runwire'], true)) {
    throw new RuntimeException('Certification profile must be all, sapi or runwire.');
}
if (!in_array($comparisonKind, ['aa', 'ab'], true)) {
    throw new RuntimeException('Comparison kind must be aa or ab.');
}
if ($trials < 1) {
    throw new RuntimeException('Certification trial count must be at least one.');
}

$expected = expected_rows($profile, $trials);
$seen = [];
$groups = [];
$errors = [];
$rows = load_rows($input);
if ($rows === []) {
    $errors[] = 'Certification evidence is empty.';
}

foreach ($rows as $row) {
    $version = is_string($row['version'] ?? null) ? $row['version'] : '';
    $mode = is_string($row['mode'] ?? null) ? $row['mode'] : '';
    $workload = is_string($row['workload'] ?? null) ? $row['workload'] : '';
    $concurrency = is_int($row['concurrency'] ?? null) ? $row['concurrency'] : 0;
    $trial = is_int($row['trial'] ?? null) ? $row['trial'] : 0;
    $rowKey = implode('|', [$mode, $workload, (string) $concurrency, $version, (string) $trial]);

    if (!isset($expected[$rowKey])) {
        $errors[] = "{$rowKey}: unexpected certification row.";

        continue;
    }
    if (isset($seen[$rowKey])) {
        $errors[] = "{$rowKey}: duplicate certification row.";

        continue;
    }
    $seen[$rowKey] = true;

    $key = implode('|', [$mode, $workload, (string) $concurrency, $version]);
    $groups[$key][] = $row;

    foreach ([
        'rps' => false,
        'p99' => false,
        'rss_kb' => false,
    ] as $measurement => $allowZero) {
        if (!valid_measurement($row[$measurement] ?? null, $allowZero)) {
            $errors[] = "{$rowKey}: measurement '{$measurement}' is missing or invalid.";
        }
    }

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
    if ($complete <= 0) {
        $errors[] = "{$key}: no complete HTTP responses were measured.";
    }
    if ($validated !== $complete || $invalid !== 0) {
        $errors[] = "{$key}: response validation did not prove every complete response.";
    }
    $expectsNon2xx = in_array($workload, ['not_found', 'method_not_allowed', 'risk_login_invalid'], true);
    if ($expectsNon2xx) {
        if ($complete <= 0 || $non2xx !== $complete) {
            $errors[] = "{$key}: expected every validated response to be non-2xx/3xx.";
        }
    } elseif ($non2xx !== 0) {
        $errors[] = "{$key}: unexpected non-2xx/3xx responses were observed.";
    }
    if ($failed !== $socketErrorTotal) {
        $errors[] = "{$key}: socket-error totals are incomplete or inconsistent.";
    }
    if ($socketErrorTotal !== 0) {
        $errors[] = "{$key}: transport socket errors were observed.";
    }

    $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
    foreach ([
        'pid',
        'memory_current_bytes',
        'memory_peak_bytes',
        'requests_active',
        'rejected_requests_total',
    ] as $metric) {
        if (!isset($metrics[$metric]) || !is_int($metrics[$metric])) {
            $errors[] = "{$key}: required telemetry '{$metric}' is missing or invalid.";
        }
    }
    $active = $metrics['requests_active'] ?? null;
    $maximumActive = $mode === 'runwire' ? 1 : 0;
    if (is_int($active) && ($active < 0 || $active > $maximumActive)) {
        $errors[] = "{$key}: request telemetry did not drain to the metrics request only.";
    }

    $queueMetrics = [];
    foreach (['queued_bytes_current', 'deferred_backlog'] as $metric) {
        if (array_key_exists($metric, $metrics)) {
            if (!is_int($metrics[$metric])) {
                $errors[] = "{$key}: queue telemetry '{$metric}' is invalid.";

                continue;
            }

            $queueMetrics[$metric] = $metrics[$metric];
        }
    }
    if ($queueMetrics === []) {
        $errors[] = "{$key}: required queue telemetry is missing.";
    }
    foreach ($queueMetrics as $metric => $value) {
        if ($value !== 0) {
            $errors[] = "{$key}: queue telemetry '{$metric}' did not drain to zero.";
        }
    }
    if (($metrics['rejected_requests_total'] ?? null) !== 0) {
        $errors[] = "{$key}: rejected requests were observed.";
    }
}

foreach (array_keys($expected) as $rowKey) {
    if (!isset($seen[$rowKey])) {
        $errors[] = "{$rowKey}: missing certification row.";
    }
}

$aggregates = [];
foreach ($groups as $key => $rows) {
    [$mode, $workload, $concurrency, $version] = explode('|', $key, 4);
    usort($rows, static fn(array $left, array $right): int => (int) ($left['trial'] ?? 0) <=> (int) ($right['trial'] ?? 0));
    $samples = array_map(static fn(array $row): array => [
        'trial' => (int) ($row['trial'] ?? 0),
        'rps' => (float) ($row['rps'] ?? 0),
        'p99_ms' => (float) ($row['p99'] ?? 0),
    ], $rows);
    $rps = array_map(static fn(array $row): float => (float) ($row['rps'] ?? 0), $rows);
    $p99 = array_map(static fn(array $row): float => (float) ($row['p99'] ?? 0), $rows);
    $rss = array_map(static fn(array $row): float => (float) ($row['rss_kb'] ?? 0), $rows);

    $aggregates[$mode][$workload][$concurrency][$version] = [
        'samples' => $samples,
        'median_rps' => median($rps),
        'median_rpm' => median($rps) * 60.0,
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

            $baselineRps = $baseline['median_rps'];
            $candidateRps = $candidate['median_rps'];
            $throughputRegression = $baselineRps > 0
                ? (($baselineRps - $candidateRps) / $baselineRps) * 100.0
                : 0.0;
            $baselineP99 = $baseline['median_p99_ms'];
            $candidateP99 = $candidate['median_p99_ms'];
            $p99Regression = $baselineP99 > 0
                ? (($candidateP99 - $baselineP99) / $baselineP99) * 100.0
                : 0.0;
            $memoryDeltaMb = ($candidate['median_rss_kb'] - $baseline['median_rss_kb']) / 1024.0;

            $lane = acceptance_lane($workload);
            if ($lane === 'webrick_core') {
                $label = $comparisonKind === 'aa' ? 'A/A variation' : 'regression';
                if ($throughputRegression > $throughputBudget) {
                    $errors[] = sprintf(
                        '%s/%s/c%d: throughput %s %.2f%% exceeds %.2f%%.',
                        $mode, $workload, $concurrency, $label,
                        $throughputRegression, $throughputBudget,
                    );
                }
                if ($p99Regression > $p99Budget) {
                    $errors[] = sprintf(
                        '%s/%s/c%d: p99 %s %.2f%% exceeds %.2f%%.',
                        $mode, $workload, $concurrency, $label,
                        $p99Regression, $p99Budget,
                    );
                }
            } else {
                $ceiling = protected_p99_ceiling_ms($workload);
                foreach (['baseline' => $baseline, 'candidate' => $candidate] as $role => $arm) {
                    foreach ($arm['samples'] as $sample) {
                        if ($sample['p99_ms'] > $ceiling) {
                            $errors[] = sprintf(
                                '%s/%s/c%d: %s trial %d p99 %.2f ms exceeds safety ceiling %.2f ms.',
                                $mode, $workload, $concurrency, $role,
                                $sample['trial'], $sample['p99_ms'], $ceiling,
                            );
                        }
                    }
                }
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

            $pairedThroughput = paired_trial_interval(
                $baseline['samples'],
                $candidate['samples'],
                'rps',
                1.0 - $throughputBudget / 100.0,
            );
            $pairedP99 = paired_trial_interval(
                $baseline['samples'],
                $candidate['samples'],
                'p99_ms',
                1.0 + $p99Budget / 100.0,
            );

            $comparisons[] = [
                'mode' => $mode,
                'workload' => $workload,
                'concurrency' => (int) $concurrency,
                'scenario' => load_scenario($workload, (int) $concurrency),
                'acceptance_lane' => $lane,
                'stress_p99_ceiling_ms' => $lane === 'contention_stress'
                    ? protected_p99_ceiling_ms($workload) : null,
                'safety_p99_ceiling_ms' => $lane !== 'webrick_core'
                    ? protected_p99_ceiling_ms($workload) : null,
                'paired_throughput_ci95_diagnostic' => $pairedThroughput,
                'paired_p99_ci95_diagnostic' => $pairedP99,
                'baseline_rpm' => $baseline['median_rpm'],
                'candidate_rpm' => $candidate['median_rpm'],
                'baseline_rps_cv_percent' => $baseline['rps_cv_percent'],
                'baseline_samples' => $baseline['samples'],
                'candidate_samples' => $candidate['samples'],
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
    'profile' => $profile,
    'comparison_kind' => $comparisonKind,
    'measurement_model' => 'default-routing-opt-in-admission-stress-v2',
    'trials' => $trials,
    'expected_rows' => count($expected),
    'observed_rows' => count($seen),
    'budgets' => [
        'throughput_regression_percent' => $throughputBudget,
        'p99_regression_percent' => $p99Budget,
        'memory_regression_mb' => $memoryBudgetMb,
        'stress_p99_max_ms' => ['password_or_provider' => 8_000, 'other_stress' => 1_600],
        'opt_in_admission_p99_max_ms' => 1_600,
    ],
    'comparisons' => $comparisons,
    'errors' => array_values(array_unique($errors)),
];
file_put_contents(
    $jsonPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

$markdown = [
    '# Webrick HTTP certification — default routing, optional admission, and contention stress',
    '',
    $comparisonKind === 'aa'
        ? 'Identical-source A/A: core deviations measure repeatability, never code regression.'
        : 'A/B: core deviations compare release baseline and candidate.',
    '',
    sprintf(
        'Default routes (risk admission disabled): throughput regression ≤ %.2f%%, p99 regression ≤ %.2f%%. All lanes: RSS growth ≤ %.2f MiB.',
        $throughputBudget, $p99Budget, $memoryBudgetMb,
    ),
    'Opt-in admission and contention stress: complete validated responses, zero transport errors, drained queues, zero rejected requests, and per-trial p99 ≤ 8000 ms (hash/provider) or ≤ 1600 ms (other protected/stress workloads).',
    'Relative opt-in admission and contention-stress throughput/p99 comparisons are diagnostic, not default-routing regression gates.',
    '',
    '| Lane | Mode | Workload | C | Baseline RPM | Candidate RPM | Δ throughput | Baseline p99 ms | Candidate p99 ms | Δ p99 | RSS Δ MiB | Candidate RPS CV |',
    '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
];
foreach ($comparisons as $row) {
    $markdown[] = sprintf(
        '| %s | %s | %s | %d | %.0f | %.0f | %.2f%% | %.1f | %.1f | %.2f%% | %.2f | %.2f%% |',
        $row['acceptance_lane'],
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
// Keep all raw relative deviations, including non-gating protected-route overhead.
$regressions = array_filter($comparisons, static fn(array $row): bool =>
    $row['throughput_regression_percent'] > $throughputBudget
    || $row['p99_regression_percent'] > $p99Budget
    || $row['rss_delta_mb'] > $memoryBudgetMb,
);
if ($regressions !== []) {
    $markdown[] = '';
    $markdown[] = '## Trial-level performance diagnostics';
    $markdown[] = '';
    $markdown[] = 'Relative-threshold deviations are shown. Optional admission and stress ratios are diagnostic; absolute safety ceilings and correctness checks are hard gates. Trials are paired by number; isolated differences do not establish a code-level cause.';
    $markdown[] = '';
    $markdown[] = '| Mode | Workload | C | Trial | Baseline RPS | Candidate RPS | Baseline p99 ms | Candidate p99 ms |';
    $markdown[] = '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |';
    foreach ($regressions as $row) {
        foreach ($row['baseline_samples'] as $index => $baselineSample) {
            $candidateSample = $row['candidate_samples'][$index] ?? null;
            if (!is_array($candidateSample)) {
                continue;
            }
            $markdown[] = sprintf(
                '| %s | %s | %d | %d | %.2f | %.2f | %.2f | %.2f |',
                $row['mode'],
                $row['workload'],
                $row['concurrency'],
                $baselineSample['trial'],
                $baselineSample['rps'],
                $candidateSample['rps'],
                $baselineSample['p99_ms'],
                $candidateSample['p99_ms'],
            );
        }
    }
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
