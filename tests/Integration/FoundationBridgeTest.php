<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\CompiledMiddlewarePipeline;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Kernel\RoutingControlRendererInterface;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Psr\Log\NullLogger;

final class FoundationBridgeParameterizedMiddleware
{
    public static int $constructed = 0;

    public function __construct(
        private readonly string $limit,
        private readonly string $window,
    ) {
        ++self::$constructed;
    }

    public function __invoke(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('X-Bridge-Params', $this->limit . ':' . $this->window);
    }
}

final readonly class FoundationBridgeScopedMarker
{
    public function __construct(public string $id) {}
}

final readonly class FoundationBridgeGraphDependency {}

final readonly class FoundationBridgeGraphController
{
    public function __construct(private FoundationBridgeGraphDependency $dependency) {}

    public function __invoke(): Response
    {
        return Response::plaintext($this->dependency::class);
    }
}

/** @return array{0:string,1:string} */
function foundationBridgeArtifactPaths(string $prefix): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(8));

    return [$base . '-intermix.php', $base . '-router.php'];
}

/** @param list<string> $paths */
function foundationBridgeCleanup(array $paths): void
{
    foreach ($paths as $path) {
        foreach ([$path, $path . '.meta.json'] as $candidate) {
            if (is_file($candidate) && !unlink($candidate)) {
                throw new RuntimeException("Unable to remove Foundation bridge fixture: {$candidate}");
            }
        }
    }
}

describe('Foundation Webrick bridge', function () {
    beforeEach(function () {
        FoundationBridgeParameterizedMiddleware::$constructed = 0;
        if (!MiddlewareAliases::frozen()) {
            MiddlewareAliases::reset();
        }
    });

    afterEach(function () {
        if (!MiddlewareAliases::frozen()) {
            MiddlewareAliases::reset();
        }
    });

    it('defers parameterized middleware aliases and transports their parameters through artifacts', function () {
        MiddlewareAliases::register('bridge_params', FoundationBridgeParameterizedMiddleware::class);

        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get('/bridge-params', static fn(): Response => Response::plaintext('ok'), [
                    'middleware' => ['bridge_params:30,60'],
                ]);
            },
            environment: 'production',
            configFingerprint: 'foundation-bridge',
        );

        $plan = array_values($build->plans)[0] ?? null;
        $descriptor = $plan?->middleware[0] ?? null;

        expect(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(0)
            ->and($descriptor)->toBeInstanceOf(RuntimeMiddlewareDescriptor::class)
            ->and($descriptor->resolver)->toBe(FoundationBridgeParameterizedMiddleware::class)
            ->and($descriptor->parameters)->toBe(['30', '60']);

        $decoded = ArtifactValueCodec::decode(ArtifactValueCodec::encode($descriptor));

        expect($decoded)->toBeInstanceOf(RuntimeMiddlewareDescriptor::class)
            ->and($decoded->resolver)->toBe(FoundationBridgeParameterizedMiddleware::class)
            ->and($decoded->parameters)->toBe(['30', '60'])
            ->and(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(0);
    });

    it('constructs parameterized middleware only inside the active runtime scope', function () {
        [$intermixPath] = foundationBridgeArtifactPaths('webrick-bridge-middleware');
        $builder = ContainerBuilder::create('webrick_bridge_middleware_' . bin2hex(random_bytes(4)));

        try {
            $builder->compile($intermixPath);
            $production = $builder->production($intermixPath);
            $runtime = new InterMixRuntime($production);
            $pipeline = new CompiledMiddlewarePipeline(
                [new RuntimeMiddlewareDescriptor(FoundationBridgeParameterizedMiddleware::class, ['30', '60'])],
                static fn(Request $request): Response => Response::plaintext('ok'),
                $runtime,
            );
            $request = mockRequest('GET', '/bridge-runtime');

            expect(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(0);

            $response = $runtime->withinScope(
                'webrick.request',
                static fn() => $pipeline->handle($request),
                [Request::class => $request],
            );

            expect($response)->toHaveStatus(200)
                ->and($response->getHeaderLine('X-Bridge-Params'))->toBe('30:60')
                ->and(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(1);
        } finally {
            foundationBridgeCleanup([$intermixPath]);
        }
    });

    it('keeps the stable request scope isolated across concurrent Fibers', function () {
        $alias = 'webrick_bridge_fiber_' . bin2hex(random_bytes(4));
        $container = Container::instance($alias);
        $container->definitions()->bind(
            FoundationBridgeScopedMarker::class,
            static fn(): FoundationBridgeScopedMarker => new FoundationBridgeScopedMarker(bin2hex(random_bytes(6))),
            LifetimeEnum::Scoped,
        );
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $registrar) use ($container): void {
                $registrar->get('/fiber-scope', static function (FoundationBridgeScopedMarker $marker) use ($container): Response {
                    $first = $marker->id;
                    Fiber::suspend($first);

                    return Response::plaintext($container->get(FoundationBridgeScopedMarker::class)->id);
                });
            },
            invoker: Invoker::with($container),
            preGlobalTags: [],
            postGlobalTags: [],
        );

        try {
            $first = new Fiber(static fn(): Response => $kernel->handle(mockRequest('GET', '/fiber-scope')));
            $second = new Fiber(static fn(): Response => $kernel->handle(mockRequest('GET', '/fiber-scope')));

            $firstId = $first->start();
            $secondId = $second->start();

            expect($firstId)->toBeString()
                ->and($secondId)->toBeString()
                ->and($firstId)->not->toBe($secondId);

            $first->resume();
            $second->resume();

            $firstResponse = $first->getReturn();
            $secondResponse = $second->getReturn();

            expect((string) $firstResponse->getBody())->toBe($firstId)
                ->and((string) $secondResponse->getBody())->toBe($secondId);
        } finally {
            $container->unset();
        }
    });

    it('keeps default routing controls independent from the application error handler', function () {
        [$intermixPath, $routerPath] = foundationBridgeArtifactPaths('webrick-bridge-controls');
        $builder = ContainerBuilder::create('webrick_bridge_controls_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get('/known', static fn(): Response => Response::plaintext('known'));
            },
            environment: 'production',
            configFingerprint: 'foundation-controls',
        );
        $applicationErrors = 0;
        $errorHandler = new ErrorHandler(
            logger: new NullLogger(),
            responseRenderer: static function () use (&$applicationErrors): Response {
                ++$applicationErrors;

                return Response::plaintext('application-error', 599);
            },
        );

        try {
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
            new RouterArtifactCompiler()->compile($build, $routerPath);
            $kernel = CompiledRouterKernel::fromCompiledArtifact(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                container: $container,
                artifactPath: $routerPath,
                environment: 'production',
                configFingerprint: 'foundation-controls',
                errorHandler: $errorHandler,
            );

            $notFound = $kernel->handle(mockRequest('GET', '/missing'));
            $methodNotAllowed = $kernel->handle(mockRequest('POST', '/known'));

            expect($notFound)->toHaveStatus(404)
                ->and($notFound->getHeaderLine('Cache-Control'))->toBe('no-store')
                ->and($methodNotAllowed)->toHaveStatus(405)
                ->and($methodNotAllowed->getHeaderLine('Allow'))->toContain('GET')
                ->and($applicationErrors)->toBe(0);
        } finally {
            foundationBridgeCleanup([$intermixPath, $routerPath]);
        }
    });

    it('allows explicit routing-control rendering without changing application exception handling', function () {
        [$intermixPath, $routerPath] = foundationBridgeArtifactPaths('webrick-bridge-custom-controls');
        $builder = ContainerBuilder::create('webrick_bridge_custom_controls_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get('/known', static fn(): Response => Response::plaintext('known'));
            },
            environment: 'production',
            configFingerprint: 'foundation-custom-controls',
        );
        $renderer = new class implements RoutingControlRendererInterface
        {
            public function render(RoutingInput $routing, MatchOutcome $outcome): Response
            {
                return Response::plaintext('custom-routing-control', 418, ['X-Routing-Control' => 'custom']);
            }
        };

        try {
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
            new RouterArtifactCompiler()->compile($build, $routerPath);
            $kernel = CompiledRouterKernel::fromCompiledArtifact(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                container: $container,
                artifactPath: $routerPath,
                environment: 'production',
                configFingerprint: 'foundation-custom-controls',
                routingControlRenderer: $renderer,
            );

            $response = $kernel->handle(mockRequest('GET', '/missing'));

            expect($response)->toHaveStatus(418)
                ->and((string) $response->getBody())->toContain('custom-routing-control')
                ->and($response->getHeaderLine('X-Routing-Control'))->toBe('custom');
        } finally {
            foundationBridgeCleanup([$intermixPath, $routerPath]);
        }
    });

    it('enriches the host graph after one route discovery and before InterMix compilation', function () {
        [$intermixPath, $routerPath] = foundationBridgeArtifactPaths('webrick-bridge-release');
        $releasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-bridge-release-' . bin2hex(random_bytes(8)) . '.json';
        $builder = ContainerBuilder::create('webrick_bridge_release_' . bin2hex(random_bytes(4)));
        $registrations = 0;
        $enrichments = 0;

        try {
            $manifest = new ReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar) use (&$registrations): void {
                    ++$registrations;
                    $registrar->get('/graph', FoundationBridgeGraphController::class);
                },
                environment: 'production',
                configFingerprint: 'foundation-release',
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $releasePath,
                enrichGraph: static function (ContainerBuilder $activeBuilder, RouterBuildResult $routes) use (&$enrichments): void {
                    ++$enrichments;
                    $plan = array_values($routes->plans)[0] ?? null;
                    expect($plan?->handler)->toBe([FoundationBridgeGraphController::class, '__invoke']);

                    $activeBuilder
                        ->singleton(FoundationBridgeGraphDependency::class)
                        ->transient(FoundationBridgeGraphController::class);
                },
            );

            expect($registrations)->toBe(1)
                ->and($enrichments)->toBe(1)
                ->and($manifest['webrick']['routes'] ?? null)->toBe(1)
                ->and(is_file($intermixPath))->toBeTrue()
                ->and(is_file($routerPath))->toBeTrue()
                ->and(is_file($releasePath))->toBeTrue();
        } finally {
            foundationBridgeCleanup([$intermixPath, $routerPath]);
            foreach ([$releasePath, ReleaseCompiler::runtimeManifestPath($releasePath)] as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException("Unable to remove Foundation bridge release fixture: {$path}");
                }
            }
        }
    });
});
