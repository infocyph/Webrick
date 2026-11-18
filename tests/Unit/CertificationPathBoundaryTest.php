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
