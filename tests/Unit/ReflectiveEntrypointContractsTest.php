<?php

declare(strict_types=1);

use Infocyph\Webrick\Benchmarks;
use Infocyph\Webrick\Benchmarks\Fixture\ParameterizedRuntimeMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use PhpBench\Attributes\Groups;

/**
 * The benchmark runner discovers these subjects through reflection, not direct calls.
 * Keep this inventory tied to real on-disk subjects and executable benchmark methods.
 */
test('all benchmark subjects are Composer-loadable and contain executable benchmark entry points', function (): void {
    $subjects = [
        Benchmarks\CacheLifecycleBench::class,
        Benchmarks\EtagBench::class,
        Benchmarks\KernelDispatchBench::class,
        Benchmarks\MatcherAdaptiveDecisionBench::class,
        Benchmarks\MatcherBench::class,
        Benchmarks\MatcherMemoryBench::class,
        Benchmarks\MatcherMethodTerminalBench::class,
        Benchmarks\MatcherOutcomeBench::class,
        Benchmarks\MatcherPathInspectionBench::class,
        Benchmarks\MatcherPcrePolicyBench::class,
        Benchmarks\MatcherScaleBench::class,
        Benchmarks\MatcherTypedConstraintBench::class,
        Benchmarks\PreRoutingGateBench::class,
        Benchmarks\ResponseCacheBench::class,
        Benchmarks\RuntimeMiddlewareDescriptorBench::class,
        Benchmarks\RunwireRuntimeBench::class,
        Benchmarks\SecurityMiddlewareKernelBench::class,
        Benchmarks\SignedUrlBench::class,
    ];

    $files = glob(dirname(__DIR__, 2) . '/benchmarks/*Bench.php');
    if (!is_array($files)) {
        throw new RuntimeException('Unable to enumerate PHPBench subjects.');
    }

    $discovered = array_map(
        static fn(string $path): string => Benchmarks::class . '\\' . basename($path, '.php'),
        $files,
    );
    sort($subjects);
    sort($discovered);
    expect($subjects)->toBe($discovered);

    foreach ($subjects as $subject) {
        expect(class_exists($subject))->toBeTrue();
        $reflection = new ReflectionClass($subject);
        expect($reflection->isInstantiable())->toBeTrue()
            ->and($reflection->getAttributes(Groups::class))->not->toBeEmpty();

        $benchMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool => str_starts_with($method->getName(), 'bench'),
        );
        expect($benchMethods)->not->toBeEmpty();
    }
});

test('PHPBench descriptor fixture remains invokable through its runtime callback contract', function (): void {
    $middleware = new ParameterizedRuntimeMiddleware('30', '60');
    $request = Request::fake(uri: '/benchmark');
    $response = $middleware($request, static fn(Request $current): Response => Response::plaintext($current->getUri()->getPath()));

    expect($middleware->limit)->toBe('30')
        ->and($middleware->window)->toBe('60')
        ->and((string) $response->getBody())->toBe('/benchmark');
});

test('public extension-point classes are Composer-loadable without booting a request kernel', function (): void {
    $subjects = [
        \Infocyph\Webrick\Http\HttpFactory::class,
        \Infocyph\Webrick\Middleware\InputSanitizerMiddleware::class,
        \Infocyph\Webrick\Request\Http\SessionCsrfTokenStore::class,
        \Infocyph\Webrick\Response\Emitter\CliEmitter::class,
        \Infocyph\Webrick\Response\Headers\Language::class,
        \Infocyph\Webrick\Response\Headers\RateLimit::class,
        \Infocyph\Webrick\Response\Headers\Vary::class,
        \Infocyph\Webrick\Router\Kernel\PhpErrorBridge::class,
        \Infocyph\Webrick\Tests\Fixture\TestController::class,
        \Infocyph\Webrick\Tests\Fixture\TestMiddleware::class,
    ];

    foreach ($subjects as $subject) {
        expect(class_exists($subject))->toBeTrue();
        $source = new ReflectionClass($subject)->getFileName();
        expect(is_string($source) && is_file($source))->toBeTrue();
    }
});
