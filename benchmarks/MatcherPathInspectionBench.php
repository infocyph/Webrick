<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Measures path inspection around compact dynamic dispatch and preserves the
 * rejected Phase 2 PHP scanner beside the retained C-backed string strategy.
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

    #[Bench\ParamProviders('providePaths')]
    public function benchCurrentShapeAndSegments(array $params): void
    {
        $path = (string) $params['path'];
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            $count = 0;
            $prefix = '';
            $segments = [];
        } else {
            $slash = strpos($trimmed, '/');
            $prefix = $slash === false ? $trimmed : substr($trimmed, 0, $slash);
            $count = substr_count($trimmed, '/') + 1;
            $segments = explode('/', $trimmed);
        }

        self::guardCombined($count, $prefix, $segments);
    }

    #[Bench\ParamProviders('providePaths')]
    public function benchOnePassPathShape(array $params): void
    {
        $path = (string) $params['path'];
        [$start, $end] = self::trimBounds($path);
        if ($start === $end) {
            $count = 0;
            $prefix = '';
        } else {
            $count = 1;
            $prefixEnd = $end;
            for ($index = $start; $index < $end; ++$index) {
                if ($path[$index] !== '/') {
                    continue;
                }
                if ($prefixEnd === $end) {
                    $prefixEnd = $index;
                }
                ++$count;
            }
            $prefix = substr($path, $start, $prefixEnd - $start);
        }

        if ($count < 0 || $prefix === "\0") {
            throw new \LogicException('Unreachable benchmark guard.');
        }
    }

    #[Bench\ParamProviders('providePaths')]
    public function benchOnePassViewAndMaterialize(array $params): void
    {
        $path = (string) $params['path'];
        [$start, $end] = self::trimBounds($path);
        if ($start === $end) {
            $count = 0;
            $prefix = '';
            $segments = [];
        } else {
            $segments = [];
            $segmentStart = $start;
            for ($index = $start; $index < $end; ++$index) {
                if ($path[$index] !== '/') {
                    continue;
                }
                $segments[] = substr($path, $segmentStart, $index - $segmentStart);
                $segmentStart = $index + 1;
            }
            $segments[] = substr($path, $segmentStart, $end - $segmentStart);
            $count = count($segments);
            $prefix = $segments[0];
        }

        self::guardCombined($count, $prefix, $segments);
    }

    /** @return iterable<string, array{path:string}> */
    public function providePaths(): iterable
    {
        yield 'root' => ['path' => '/'];
        yield 'single' => ['path' => '/users'];
        yield 'shallow-dynamic' => ['path' => '/users/42'];
        yield 'medium-dynamic' => ['path' => '/api/v1/users/42'];
        yield 'deep-dynamic' => ['path' => '/api/v1/accounts/42/orders/900/items/benchmark'];
        yield 'normalized-extra-slashes' => ['path' => '//api/v1/users/42//'];
    }

    /** @param list<string> $segments */
    private static function guardCombined(int $count, string $prefix, array $segments): void
    {
        if ($count < 0 || $prefix === "\0" || count($segments) < 0) {
            throw new \LogicException('Unreachable benchmark guard.');
        }
    }

    /** @return array{0:int,1:int} */
    private static function trimBounds(string $path): array
    {
        $start = 0;
        $end = strlen($path);
        while ($start < $end && $path[$start] === '/') {
            ++$start;
        }
        while ($end > $start && $path[$end - 1] === '/') {
            --$end;
        }

        return [$start, $end];
    }
}
