<?php

declare(strict_types=1);

use PhpBench\Attributes\Groups;

/**
 * PHPBench discovers subjects via reflection. Verify the actual benchmark surface
 * rather than manufacturing entry-point annotations for Psalm's unused-class rule.
 */
test('every benchmark subject is discoverable by PHPBench', function (): void {
    $subjects = [
        \Infocyph\Webrick\Benchmarks\CacheLifecycleBench::class,
        \Infocyph\Webrick\Benchmarks\EtagBench::class,
        \Infocyph\Webrick\Benchmarks\KernelDispatchBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherAdaptiveDecisionBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherMemoryBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherMethodTerminalBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherOutcomeBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherPathInspectionBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherPcrePolicyBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherScaleBench::class,
        \Infocyph\Webrick\Benchmarks\MatcherTypedConstraintBench::class,
        \Infocyph\Webrick\Benchmarks\PreRoutingGateBench::class,
        \Infocyph\Webrick\Benchmarks\ResponseCacheBench::class,
        \Infocyph\Webrick\Benchmarks\RuntimeMiddlewareDescriptorBench::class,
        \Infocyph\Webrick\Benchmarks\RunwireRuntimeBench::class,
        \Infocyph\Webrick\Benchmarks\SecurityMiddlewareKernelBench::class,
        \Infocyph\Webrick\Benchmarks\SignedUrlBench::class,
    ];

    $files = glob(__DIR__ . '/../../benchmarks/*Bench.php');
    if ($files === false) {
        throw new RuntimeException('Cannot list benchmark source files.');
    }

    $discovered = array_map(basename(...), $files);
    $registered = [];

    foreach ($subjects as $subject) {
        $reflection = new ReflectionClass($subject);
        $registered[] = $reflection->getShortName() . '.php';

        expect($reflection->getAttributes(Groups::class))->toHaveCount(1);

        $benchMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool => str_starts_with($method->getName(), 'bench'),
        );
        expect($benchMethods)->not->toBeEmpty();
    }

    sort($discovered);
    sort($registered);

    expect($registered)->toBe($discovered);
});
