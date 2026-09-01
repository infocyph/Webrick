<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Baseline for the string/array work currently performed around compact
 * dynamic dispatch. Phase 2 compares these C-backed string primitives with a
 * one-pass path scanner; this benchmark intentionally contains no alternative
 * implementation yet.
 */
#[Bench\Groups(['matcher', 'matcher-path-inspection'])]
#[Bench\Iterations(5)]
#[Bench\Revs(5000)]
#[Bench\Warmup(1)]
final class MatcherPathInspectionBench
{
    #[Bench\ParamProviders('providePaths')]
    public function benchCurrentPathShape(array $params): void
    {
        $path = (string) $params['path'];
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            $count = 0;
            $prefix = '';
        } else {
            $slash = strpos($trimmed, '/');
            $prefix = $slash === false ? $trimmed : substr($trimmed, 0, $slash);
            $count = substr_count($trimmed, '/') + 1;
        }

        if ($count < 0 || $prefix === "\0") {
            throw new \LogicException('Unreachable benchmark guard.');
        }
    }

    public function benchCurrentPositionalParameterCapture(): void
    {
        $entry = ['id' => 42, 'params' => ['group', 'id', 'slug']];
        $matches = [
            0 => '/api/groups/main/items/42/benchmark',
            1 => 'main',
            2 => '42',
            3 => 'benchmark',
            'MARK' => 'r0',
        ];

        $captured = [];
        foreach ($entry['params'] as $offset => $name) {
            $piece = $matches[$offset + 1] ?? null;
            if (!is_string($piece)) {
                throw new \UnexpectedValueException('Benchmark capture is unavailable.');
            }
            $captured[$name] = $piece;
        }

        $result = [$entry['id'], $captured];
        if ($result[0] !== 42) {
            throw new \LogicException('Unreachable benchmark guard.');
        }
    }

    #[Bench\ParamProviders('providePaths')]
    public function benchCurrentSegmentMaterialization(array $params): void
    {
        $path = (string) $params['path'];
        if ($path === '/' || $path === '') {
            $segments = [];
        } else {
            $trimmed = trim($path, '/');
            $segments = $trimmed === '' ? [] : explode('/', $trimmed);
        }

        if (count($segments) < 0) {
            throw new \LogicException('Unreachable benchmark guard.');
        }
    }

    /** @return iterable<string, array{path:string}> */
    public function providePaths(): iterable
    {
        yield 'root' => ['path' => '/'];
        yield 'single' => ['path' => '/users'];
        yield 'shallow-dynamic' => ['path' => '/users/42'];
        yield 'deep-dynamic' => ['path' => '/api/v1/accounts/42/orders/900/items/benchmark'];
        yield 'normalized-extra-slashes' => ['path' => '//api/v1/users/42//'];
    }
}
