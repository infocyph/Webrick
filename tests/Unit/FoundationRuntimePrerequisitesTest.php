<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\HandlerCompiler;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\CompiledMiddlewarePipeline;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Psr\Log\NullLogger;
use Throwable;

if (!class_exists('FoundationRuntimeParameterizedMiddlewareFactory', false)) {
    final class FoundationRuntimeParameterizedMiddlewareFactory
    {
        public static int $calls = 0;

        public static function make(string $limit, string $bucket): Closure
        {
            ++self::$calls;

            return static function (Request $request, Closure $next) use ($limit, $bucket): Response {
                return $next($request)->withHeader('X-Parameterized-Middleware', "{$limit}:{$bucket}");
            };
        }
    }
}

if (!class_exists('FoundationRuntimeMiddlewareDependency', false)) {
    final readonly class FoundationRuntimeMiddlewareDependency
    {
        public function __construct(public string $marker) {}
    }
}

if (!class_exists('FoundationRuntimeParameterizedClassMiddleware', false)) {
    final readonly class FoundationRuntimeParameterizedClassMiddleware
    {
        public function __construct(
            private string $limit,
            private string $bucket,
            private FoundationRuntimeMiddlewareDependency $dependency,
        ) {}

        public function __invoke(Request $request, Closure $next): Response
        {
            return $next($request)->withHeader(
                'X-Parameterized-Class-Middleware',
                "{$this->limit}:{$this->bucket}:{$this->dependency->marker}",
            );
        }
    }
}

if (!class_exists('FoundationRuntimeScopedMarker', false)) {
    final readonly class FoundationRuntimeScopedMarker
    {
        public function __construct(public string $id) {}
    }
}

if (!class_exists('FoundationRuntimeController', false)) {
    final class FoundationRuntimeController
    {
        public static function known(): Response
        {
            return Response::plaintext('known');
        }
    }
}

/** @return array{0:CompiledRouterKernel,1:string} */
function foundationRuntimeCompiledKernel(bool $routeErrorsThroughErrorHandler): array
{
    $directory = sys_get_temp_dir() . '/webrick-foundation-runtime-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create Webrick test directory '{$directory}'.");
    }

    $routerPath = $directory . '/router.php';
    $containerPath = $directory . '/container.php';
    $build = new RouteCompiler()->compile(
        register: static function (Registrar $registrar): void {
            $registrar->get('/known', [FoundationRuntimeController::class, 'known']);
        },
        environment: 'test',
        configFingerprint: 'foundation-runtime',
        preGlobalTags: [],
        postGlobalTags: [],
    );
    new RouterArtifactCompiler()->compile($build, $routerPath);

    $builder = ContainerBuilder::create('webrick.foundation.runtime');
    $builder->compile($containerPath);
    $container = $builder->production($containerPath);
    $errorHandler = new ErrorHandler(
        logger: new NullLogger(),
        responseRenderer: static function (
            Request $request,
            Throwable $exception,
            int $status,
            array $headers,
        ): Response {
            unset($request, $exception);

            return new Response($status, 'custom-routing-error', $headers + ['X-Routing-Policy' => 'custom']);
        },
    );

    return [
        CompiledRouterKernel::fromCompiledArtifact(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            container: $container,
            artifactPath: $routerPath,
            environment: 'test',
            configFingerprint: 'foundation-runtime',
            errorHandler: $errorHandler,
            routeErrorsThroughErrorHandler: $routeErrorsThroughErrorHandler,
        ),
        $directory,
    ];
}

function foundationRuntimeCleanupDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Unable to remove Webrick test artifact '{$path}'.");
        }
    }
    if (is_dir($directory) && !rmdir($directory)) {
        throw new RuntimeException("Unable to remove Webrick test directory '{$directory}'.");
    }
}

it('defers parameterized middleware alias factories through artifact hydration until request runtime', function (): void {
    MiddlewareAliases::reset();
    FoundationRuntimeParameterizedMiddlewareFactory::$calls = 0;
    MiddlewareAliases::register(
        'runtime_limit',
        [FoundationRuntimeParameterizedMiddlewareFactory::class, 'make'],
    );

    try {
        $compiled = new HandlerCompiler()->compileMiddlewareList(['runtime_limit:60,api']);
        expect(FoundationRuntimeParameterizedMiddlewareFactory::$calls)->toBe(0)
            ->and($compiled)->toHaveCount(1);

        $descriptor = $compiled[0];
        expect($descriptor)->toBeInstanceOf(RuntimeMiddlewareDescriptor::class);
        if (!$descriptor instanceof RuntimeMiddlewareDescriptor) {
            throw new RuntimeException('Expected a runtime middleware descriptor.');
        }
        expect($descriptor->parameters)->toBe(['60', 'api']);

        $hydrated = ArtifactValueCodec::decode(ArtifactValueCodec::encode($descriptor));
        expect(FoundationRuntimeParameterizedMiddlewareFactory::$calls)->toBe(0)
            ->and($hydrated)->toBeInstanceOf(RuntimeMiddlewareDescriptor::class);
        if (!$hydrated instanceof RuntimeMiddlewareDescriptor) {
            throw new RuntimeException('Expected hydrated runtime middleware descriptor.');
        }

        $container = new Container('webrick.foundation.middleware');
        $pipeline = new CompiledMiddlewarePipeline(
            [$hydrated],
            static fn(Request $request): Response => Response::plaintext('ok'),
            new InterMixRuntime($container),
        );
        $response = $container->withinScope(
            RuntimeRequestContext::REQUEST_SCOPE,
            static fn(): Response => $pipeline->handle(Request::fake(uri: '/runtime-middleware')),
        );

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->getHeaderLine('X-Parameterized-Middleware'))->toBe('60:api')
            ->and(FoundationRuntimeParameterizedMiddlewareFactory::$calls)->toBe(1);
    } finally {
        MiddlewareAliases::reset();
    }
});

it('resolves parameterized class middleware with scoped DI in development request scope', function (): void {
    MiddlewareAliases::reset();
    MiddlewareAliases::register('runtime_class', FoundationRuntimeParameterizedClassMiddleware::class);

    $container = new Container('webrick.foundation.parameterized-class');
    $container->scoped(
        FoundationRuntimeMiddlewareDependency::class,
        static fn(): FoundationRuntimeMiddlewareDependency => new FoundationRuntimeMiddlewareDependency(
            bin2hex(random_bytes(6)),
        ),
    );

    try {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $registrar): void {
                $registrar->get(
                    '/parameterized-class',
                    static fn(): Response => Response::plaintext('ok'),
                    ['middleware' => ['runtime_class:25,api']],
                );
            },
            invoker: Invoker::with($container),
            preGlobalTags: [],
            postGlobalTags: [],
        );

        $first = $kernel->handle(Request::fake(uri: '/parameterized-class'));
        $second = $kernel->handle(Request::fake(uri: '/parameterized-class'));
        $firstParts = explode(':', $first->getHeaderLine('X-Parameterized-Class-Middleware'));
        $secondParts = explode(':', $second->getHeaderLine('X-Parameterized-Class-Middleware'));

        expect(array_slice($firstParts, 0, 2))->toBe(['25', 'api'])
            ->and(array_slice($secondParts, 0, 2))->toBe(['25', 'api'])
            ->and($firstParts[2] ?? null)->toBeString()
            ->and($secondParts[2] ?? null)->toBeString()
            ->and($firstParts[2])->not->toBe($secondParts[2]);
    } finally {
        MiddlewareAliases::reset();
    }
});

it('exposes the semantic request scope label from runtime request contexts', function (): void {
    $context = new RuntimeRequestContext(
        routing: new RoutingInput('GET', '/scope'),
        requestFactory: static fn(): Request => Request::fake(uri: '/scope'),
        capabilities: new RuntimeCapabilities('test', persistent: true, concurrent: true),
    );

    expect($context->scopeId())->toBe(RuntimeRequestContext::REQUEST_SCOPE)
        ->and($context->scopeId())->toBe('webrick.request');
});

it('uses the stable development request scope while retaining fresh scoped state', function (): void {
    $container = new Container('webrick.foundation.scope');
    $scopeLeaves = 0;
    $container->scoped(
        FoundationRuntimeScopedMarker::class,
        static fn(): FoundationRuntimeScopedMarker => new FoundationRuntimeScopedMarker(bin2hex(random_bytes(6))),
    );
    $container->onScopeLeave(RuntimeRequestContext::REQUEST_SCOPE, static function () use (&$scopeLeaves): void {
        ++$scopeLeaves;
    });

    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger(),
        matcher: FusedMatcher::make(),
        register: static function (Registrar $registrar): void {
            $registrar->get('/scope', static function (FoundationRuntimeScopedMarker $marker): Response {
                return Response::json(['id' => $marker->id]);
            });
        },
        invoker: Invoker::with($container),
        preGlobalTags: [],
        postGlobalTags: [],
    );

    $first = json_decode((string) $kernel->handle(Request::fake(uri: '/scope'))->getBody(), true);
    $second = json_decode((string) $kernel->handle(Request::fake(uri: '/scope'))->getBody(), true);

    expect($scopeLeaves)->toBe(2)
        ->and($first['id'] ?? null)->toBeString()
        ->and($second['id'] ?? null)->toBeString()
        ->and($first['id'])->not->toBe($second['id']);
});

it('keeps default compiled 404 handling direct even with a custom application error handler', function (): void {
    [$kernel, $directory] = foundationRuntimeCompiledKernel(false);

    try {
        $response = $kernel->handle(Request::fake(uri: '/missing'));

        expect($response->getStatusCode())->toBe(404)
            ->and($response->hasHeader('X-Routing-Policy'))->toBeFalse()
            ->and((string) $response->getBody())->not->toBe('custom-routing-error');
    } finally {
        foundationRuntimeCleanupDirectory($directory);
    }
})->runInSeparateProcess();

it('routes compiled 404 handling through the application error handler only when explicitly requested', function (): void {
    [$kernel, $directory] = foundationRuntimeCompiledKernel(true);

    try {
        $response = $kernel->handle(Request::fake(uri: '/missing'));

        expect($response->getStatusCode())->toBe(404)
            ->and($response->getHeaderLine('X-Routing-Policy'))->toBe('custom')
            ->and((string) $response->getBody())->toBe('custom-routing-error');
    } finally {
        foundationRuntimeCleanupDirectory($directory);
    }
})->runInSeparateProcess();

it('exposes finalized route plans once for graph enrichment before InterMix validation and compile', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-foundation-release-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0775, true))->toBeTrue();

    $registerCalls = 0;
    $enrichmentCalls = 0;
    $builder = ContainerBuilder::create('webrick.foundation.release');

    try {
        $manifest = new ReleaseCompiler()->compile(
            builder: $builder,
            register: static function (Registrar $registrar) use (&$registerCalls): void {
                ++$registerCalls;
                $registrar->get('/known', [FoundationRuntimeController::class, 'known']);
            },
            environment: 'production',
            configFingerprint: 'foundation-release',
            intermixPath: $directory . '/container.php',
            routerPath: $directory . '/router.php',
            releaseManifestPath: $directory . '/release.json',
            preGlobalTags: [],
            postGlobalTags: [],
            enrichGraph: static function (
                ContainerBuilder $activeBuilder,
                RouterBuildResult $routerBuild,
            ) use (&$enrichmentCalls): void {
                ++$enrichmentCalls;
                expect($routerBuild->routes->all())->toHaveCount(1)
                    ->and($routerBuild->plans)->toHaveCount(1);
                $activeBuilder->value('webrick.route.plan.count', count($routerBuild->plans));
            },
        );

        $production = $builder->production($directory . '/container.php');
        expect($registerCalls)->toBe(1)
            ->and($enrichmentCalls)->toBe(1)
            ->and($production->get('webrick.route.plan.count'))->toBe(1)
            ->and($manifest['webrick']['routes'] ?? null)->toBe(1);
    } finally {
        foundationRuntimeCleanupDirectory($directory);
    }
});
