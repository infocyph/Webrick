<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @param list<string> $arguments
 * @throws \Symfony\Component\Process\Exception\ExceptionInterface
 */
function certificationCliRun(string $script, array $arguments): Process
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2) . '/.github/certification/' . $script,
        ...$arguments,
    ], timeout: 15);
    $process->run();

    return $process;
}

/** @throws \RuntimeException */
function certificationTestDirectory(): string
{
    $directory = sys_get_temp_dir() . '/webrick-cert-boundary-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create certification test directory.');
    }

    return $directory;
}

dataset('nonlocal certification paths', [
    'https://example.invalid/evidence',
    'php://filter/resource=/etc/passwd',
    'file:///tmp/evidence',
    '//example.invalid/share/evidence',
    '\\\\example.invalid\\share\\evidence',
]);

test('certification rejects nonlocal checkout roots before loading code', function (string $path): void {
    $process = certificationCliRun('cert-server.php', ['prepare', '--root=' . $path]);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('non-empty local checkout path');
})->with('nonlocal certification paths');

test('certification rejects an empty checkout root', function (): void {
    $process = certificationCliRun('cert-server.php', ['prepare', '--root=']);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('non-empty local checkout path');
});

test('certification prepares artifacts in an operator-selected local checkout', function (): void {
    $directory = certificationTestDirectory();

    try {
        symlink(dirname(__DIR__, 2) . '/vendor', $directory . '/vendor');
        $process = certificationCliRun('cert-server.php', ['prepare', '--root=' . $directory]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('prepared')
            ->and(is_file($directory . '/.runtime-certification/router.php'))->toBeTrue()
            ->and(is_file($directory . '/.runtime-certification/intermix.php'))->toBeTrue();
    } finally {
        cleanTestCache($directory);
    }
});

test('load analysis rejects nonlocal evidence and report paths', function (string $field, string $path): void {
    $directory = certificationTestDirectory();

    try {
        $paths = [
            'input' => $directory . '/evidence.jsonl',
            'summary' => $directory . '/summary.md',
            'json' => $directory . '/report.json',
        ];
        file_put_contents($paths['input'], "{}\n");
        $paths[$field] = $path;
        $process = certificationCliRun('analyze-load.php', [
            '--input=' . $paths['input'],
            '--summary=' . $paths['summary'],
            '--json=' . $paths['json'],
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('must be a local file')
            ->and(is_file($directory . '/summary.md'))->toBeFalse()
            ->and(is_file($directory . '/report.json'))->toBeFalse();
    } finally {
        cleanTestCache($directory);
    }
})->with(['input', 'summary', 'json'])->with('nonlocal certification paths');

test('load analysis writes local diagnostics while rejecting incomplete evidence', function (): void {
    $directory = certificationTestDirectory();

    try {
        file_put_contents($directory . '/evidence.jsonl', "{}\n");
        $process = certificationCliRun('analyze-load.php', [
            '--input=' . $directory . '/evidence.jsonl',
            '--summary=' . $directory . '/summary.md',
            '--json=' . $directory . '/report.json',
            '--profile=sapi',
            '--trials=1',
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and(file_get_contents($directory . '/report.json'))->toContain('missing certification row')
            ->and(file_get_contents($directory . '/summary.md'))->toContain('## Failures');
    } finally {
        cleanTestCache($directory);
    }
});

/**
 * Independent complete evidence fixture: ordinary routes alone must never certify admission.
 * @return list<array{workload:string,version:string,concurrency:int,trial:int,...}>
 */
function certificationCompleteLoadRows(): array
{
    $rows = [];
    $workloads = [
        'static', 'dynamic', 'json', 'stream', 'upload', 'not_found',
        'method_not_allowed', 'file', 'range', 'slow', 'risk_allow',
        'risk_counter', 'risk_clearance', 'risk_login', 'risk_login_invalid', 'risk_provider_slow',
    ];
    foreach ($workloads as $workload) {
        foreach ([1, 5, 20, 50] as $concurrency) {
            foreach (['baseline', 'candidate'] as $version) {
                $rows[] = [
                    'version' => $version, 'mode' => 'sapi', 'workload' => $workload,
                    'concurrency' => $concurrency, 'trial' => 1,
                    'rps' => 100, 'complete' => 10, 'validated' => 10, 'invalid' => 0,
                    'failed' => 0,
                    'non2xx' => in_array($workload, ['not_found', 'method_not_allowed', 'risk_login_invalid'], true) ? 10 : 0,
                    'socket_errors' => ['connect' => 0, 'read' => 0, 'write' => 0, 'timeout' => 0],
                    'p99' => 1, 'rss_kb' => 1024,
                    'metrics' => [
                        'pid' => 1, 'memory_current_bytes' => 1024, 'memory_peak_bytes' => 1024,
                        'requests_active' => 0, 'queued_bytes_current' => 0, 'rejected_requests_total' => 0,
                    ],
                ];
            }
        }
    }

    return $rows;
}

/**
 * @param array<array-key,array<string,mixed>> $evidence
 * @throws JsonException
 */
function certificationWriteLoadRows(string $directory, array $evidence): void
{
    file_put_contents($directory . '/evidence.jsonl', implode("\n", array_map(
        static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
        $evidence,
    )) . "\n");
}

test('load analysis requires protected workloads and distinguishes wrong-password traffic', function (): void {
    $directory = certificationTestDirectory();
    $rows = certificationCompleteLoadRows();

    try {
        $arguments = [
            '--input=' . $directory . '/evidence.jsonl', '--summary=' . $directory . '/summary.md',
            '--json=' . $directory . '/report.json', '--profile=sapi', '--trials=1',
        ];
        certificationWriteLoadRows($directory, $rows);
        $complete = certificationCliRun('analyze-load.php', $arguments);
        expect($complete->isSuccessful())->toBeTrue($complete->getErrorOutput());

        certificationWriteLoadRows($directory, array_filter($rows, static fn(array $row): bool => !str_starts_with($row['workload'], 'risk_')));
        expect(certificationCliRun('analyze-load.php', $arguments)->isSuccessful())->toBeFalse();

        $invalid = $rows;
        foreach ($invalid as &$row) {
            if ($row['workload'] === 'risk_login_invalid') {
                $row['non2xx'] = 0;
            }
        }
        unset($row);
        certificationWriteLoadRows($directory, $invalid);
        expect(certificationCliRun('analyze-load.php', $arguments)->isSuccessful())->toBeFalse()
            ->and(file_get_contents($directory . '/report.json'))->toContain('expected every validated response to be non-2xx/3xx');

        $leaked = $rows;
        $leaked[0]['metrics'] = [
            'pid' => 1, 'memory_current_bytes' => 1024, 'memory_peak_bytes' => 1024,
            'requests_active' => 2, 'queued_bytes_current' => 0, 'rejected_requests_total' => 0,
        ];
        certificationWriteLoadRows($directory, $leaked);
        expect(certificationCliRun('analyze-load.php', $arguments)->isSuccessful())->toBeFalse()
            ->and(file_get_contents($directory . '/report.json'))->toContain('request telemetry did not drain');
    } finally {
        cleanTestCache($directory);
    }
});

test('load analyzer preserves paired trial evidence without weakening budget rejection', function (): void {
    $directory = certificationTestDirectory();

    try {
        $rows = [];
        foreach ([1, 2, 3] as $trial) {
            foreach (certificationCompleteLoadRows() as $row) {
                $row['trial'] = $trial;
                if ($row['workload'] === 'dynamic' && $row['concurrency'] === 20) {
                    $row['rps'] = ($row['version'] === 'baseline' ? 100 : 90) + $trial;
                    $row['p99'] = ($row['version'] === 'baseline' ? 4 : 9) + $trial;
                }
                $rows[] = $row;
            }
        }
        certificationWriteLoadRows($directory, $rows);
        $process = certificationCliRun('analyze-load.php', [
            '--input=' . $directory . '/evidence.jsonl',
            '--summary=' . $directory . '/summary.md',
            '--json=' . $directory . '/report.json',
            '--profile=sapi',
            '--trials=3',
        ]);

        $report = json_decode((string) file_get_contents($directory . '/report.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($report) || !is_array($report['comparisons'] ?? null)) {
            throw new UnexpectedValueException('Load analysis did not produce a comparison report.');
        }

        $comparison = null;
        foreach ($report['comparisons'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['mode'] ?? null) === 'sapi'
                && ($entry['workload'] ?? null) === 'dynamic'
                && ($entry['concurrency'] ?? null) === 20) {
                $comparison = $entry;

                break;
            }
        }
        if (!is_array($comparison)) {
            throw new UnexpectedValueException('Expected the dynamic c20 performance comparison.');
        }
        $baselineSamples = $comparison['baseline_samples'] ?? null;
        $candidateSamples = $comparison['candidate_samples'] ?? null;
        if (!is_array($baselineSamples) || !is_array($candidateSamples)
            || !is_array($baselineSamples[0] ?? null)
            || !is_array($baselineSamples[1] ?? null)
            || !is_array($candidateSamples[1] ?? null)) {
            throw new UnexpectedValueException('Paired trial diagnostics are absent.');
        }

        expect($process->isSuccessful())->toBeFalse()
            ->and($report['errors'] ?? [])->not->toBeEmpty()
            ->and($baselineSamples)->toHaveCount(3)
            ->and($candidateSamples)->toHaveCount(3)
            ->and($baselineSamples[0]['trial'])->toBe(1)
            ->and($baselineSamples[1]['rps'])->toBe(102)
            ->and($candidateSamples[1]['rps'])->toBe(92)
            ->and($comparison['baseline_rps_cv_percent'])->toBeGreaterThan(0)
            ->and((string) file_get_contents($directory . '/summary.md'))
            ->toContain('## Trial-level performance diagnostics')
            ->toContain('| sapi | dynamic | 20 | 2 | 102.00 | 92.00 | 6.00 | 11.00 |');
    } finally {
        cleanTestCache($directory);
    }
});
