<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\CompiledMiddlewarePipeline;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
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

    /** @param Closure(Request):Response $next */
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
        if (!$descriptor instanceof RuntimeMiddlewareDescriptor) {
            throw new UnexpectedValueException('Compiled middleware fixture must contain a runtime descriptor.');
        }

        expect(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(0)
            ->and($descriptor->resolver)->toBe(FoundationBridgeParameterizedMiddleware::class)
            ->and($descriptor->parameters)->toBe(['30', '60']);

        $decoded = ArtifactValueCodec::decode(ArtifactValueCodec::encode($descriptor));
        if (!$decoded instanceof RuntimeMiddlewareDescriptor) {
            throw new UnexpectedValueException('Decoded middleware artifact must retain the descriptor type.');
        }

        expect($decoded->resolver)->toBe(FoundationBridgeParameterizedMiddleware::class)
            ->and($decoded->parameters)->toBe(['30', '60'])
            ->and(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(0);
    });

    it('constructs parameterized middleware only inside the active runtime scope', function () {
        [$intermixPath] = foundationBridgeArtifactPaths('webrick-bridge-middleware');
        $builder = ContainerBuilder::create('webrick_bridge_middleware_' . bin2hex(random_bytes(4)))
            ->input(Request::class);

        try {
            $builder->compile($intermixPath);
            $production = $builder->production($intermixPath);
            $runtime = new InterMixRuntime($production);
            $pipeline = new CompiledMiddlewarePipeline(
                [new RuntimeMiddlewareDescriptor(FoundationBridgeParameterizedMiddleware::class, ['30', '60'])],
                static fn(Request $request): Response => Response::plaintext($request->getMethod(), 200),
                $runtime,
            );
            $request = mockRequest('GET', '/bridge-runtime');

            expect(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(0);

            $response = $runtime->withinScope(
                'webrick.request',
                static fn() => $pipeline->handle($request),
                [Request::class => $request],
            );
            if (!$response instanceof Response) {
                throw new UnexpectedValueException('Scoped middleware fixture must produce a Response.');
            }

            expect($response)->toHaveStatus(200)
                ->and($response->getHeaderLine('X-Bridge-Params'))->toBe('30:60')
                ->and(FoundationBridgeParameterizedMiddleware::$constructed)->toBe(1);
        } finally {
            foundationBridgeCleanup([$intermixPath]);
        }
    });

    it('keeps the stable request scope isolated across concurrent Fibers', function () {
        $alias = 'webrick_bridge_fiber_' . bin2hex(random_bytes(4));
        $container = ContainerBuilder::create($alias)
            ->input(Request::class)
            ->factory(
                FoundationBridgeScopedMarker::class,
                static fn(): FoundationBridgeScopedMarker => new FoundationBridgeScopedMarker(bin2hex(random_bytes(6))),
                LifetimeEnum::Scoped,
            )
            ->build();
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $registrar) use ($container): void {
                $registrar->get('/fiber-scope', static function (FoundationBridgeScopedMarker $marker) use ($container): Response {
                    $first = $marker->id;
                    Fiber::suspend($first);

                    $scopedMarker = $container->get(FoundationBridgeScopedMarker::class);
                    if (!$scopedMarker instanceof FoundationBridgeScopedMarker) {
                        throw new UnexpectedValueException('Scoped container must return the scoped marker.');
                    }

                    return Response::plaintext($scopedMarker->id);
                });
            },
            invoker: $container,
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
            if (!$firstResponse instanceof Response || !$secondResponse instanceof Response) {
                throw new UnexpectedValueException('Both fiber executions must produce Responses.');
            }

            expect((string) $firstResponse->getBody())->toBe($firstId)
                ->and((string) $secondResponse->getBody())->toBe($secondId);
        } finally {
            unset($container);
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
                        ->autowire(
                            FoundationBridgeGraphDependency::class,
                            FoundationBridgeGraphDependency::class,
                            lifetime: LifetimeEnum::Singleton,
                        )
                        ->autowire(
                            FoundationBridgeGraphController::class,
                            FoundationBridgeGraphController::class,
                            lifetime: LifetimeEnum::Transient,
                        );
                },
            );

            $routerMetadata = $manifest['webrick'] ?? null;
            if (!is_array($routerMetadata) || !is_int($routerMetadata['routes'] ?? null)) {
                throw new UnexpectedValueException('Compiled Webrick manifest must contain a route count.');
            }

            expect($registrations)->toBe(1)
                ->and($enrichments)->toBe(1)
                ->and($routerMetadata['routes'])->toBe(1)
                ->and(is_file($intermixPath))->toBeTrue()
                ->and(is_file($routerPath))->toBeTrue()
                ->and(is_file($releasePath))->toBeTrue();

            $expected = $manifest;
            unset($expected['release_manifest'], $expected['release_runtime_manifest']);
            $loader = new ReleaseManifestLoader();
            $artifacts = new RouterArtifactLoader();
            foreach (['php', 'json'] as $representation) {
                if ($representation === 'json') {
                    unlink(ReleaseCompiler::runtimeManifestPath($releasePath));
                }
                $loaded = $loader->load($releasePath);
                expect($loaded)->toBe($expected);
                $loadedRouter = $loaded['webrick'] ?? null;
                $identity = is_array($loadedRouter) ? ($loadedRouter['fingerprint'] ?? null) : null;
                if (!is_string($identity)) {
                    throw new UnexpectedValueException('Loaded Webrick manifest must contain an artifact fingerprint.');
                }
                expect($artifacts->load($routerPath, 'production', 'foundation-release')->artifactFingerprint)
                    ->toBe($identity)
                    ->and($artifacts->loadPrevalidated($routerPath, $identity, 'production', 'foundation-release')->artifactFingerprint)
                    ->toBe($identity);

                expect(fn() => $artifacts->loadPrevalidated($routerPath, str_repeat('0', 32), 'production', 'foundation-release'))
                    ->toThrow(RuntimeException::class, 'Trusted Webrick artifact fingerprint does not match the release manifest');
                expect(fn() => $artifacts->loadPrevalidated($routerPath, $identity, 'development', 'foundation-release'))
                    ->toThrow(RuntimeException::class, 'Webrick router artifact identity mismatch');
                expect(fn() => $artifacts->loadPrevalidated($routerPath, $identity, 'production', 'other-config'))
                    ->toThrow(RuntimeException::class, 'Webrick router artifact identity mismatch');
            }

            file_put_contents($routerPath, "\n// altered artifact\n", FILE_APPEND);
            expect(fn() => $artifacts->load($routerPath, 'production', 'foundation-release'))
                ->toThrow(RuntimeException::class, 'Webrick router artifact digest mismatch');
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
