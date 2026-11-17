<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final readonly class CompiledFastPathScopedMarker {}

final readonly class CompiledBoundRouteIdentifier
{
    public function __construct(public string $value = 'unbound') {}

    /**
     * @throws \DomainException If a route identifier does not exist.
     * @throws \InvalidArgumentException For invalid route identifier input.
     */
    public static function fromBoundValue(mixed $value): self
    {
        if ($value === 'missing') {
            throw new \DomainException('Route identifier was not found.');
        }

        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Compiled route identifier must be a string or integer path value.');
        }

        return new self((string) $value);
    }
}

final readonly class CompiledNativeRequestController
{
    public function __construct(private Request $request) {}

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __invoke(Request $request, CompiledBoundRouteIdentifier $id): Response
    {
        return Response::plaintext($id->value . '|' . $this->request->getUri()->getPath()
            . '|' . $request->getUri()->getPath(), 200);
    }
}

final readonly class CompiledFastPathScopedHandler
{
    public function __construct(private CompiledFastPathScopedMarker $marker) {}

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __invoke(): Response
    {
        return Response::plaintext($this->marker::class, 200);
    }
}

final class CompiledFastPathScopeProbe
{
    public static int $leaves = 0;


    public static function leaveCount(): int
    {
        return self::$leaves;
    }

    public static function scopeLeft(string $scope, RuntimeContainerInterface $container): void
    {
        unset($container);
        if ($scope === 'webrick.request') {
            ++self::$leaves;
        }
    }
}

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class CompiledRoutingControlBridgeTest extends TestCase
{
    /**
     * Validate the exact manifest fixture fields consumed by compiled-boot tests.
     *
     * @param array<string,mixed> $manifest
     * @phpstan-assert array{format:int,environment:string,config_fingerprint:string,intermix:array{path:string,digest:string,graph:string,build:string,compiled:array<array-key,mixed>,skipped:array<array-key,mixed>},webrick:array{path:string,digest:string,fingerprint:string}} $manifest
     * @psalm-assert array{format:int,environment:string,config_fingerprint:string,intermix:array{path:string,digest:string,graph:string,build:string,compiled:array<array-key,mixed>,skipped:array<array-key,mixed>},webrick:array{path:string,digest:string,fingerprint:string}} $manifest
     * @throws \UnexpectedValueException For a missing or malformed expected fixture field.
     */
    private static function assertReleaseFixture(array $manifest): void
    {
        if (
            !is_int($manifest['format'] ?? null)
            || !is_string($manifest['environment'] ?? null)
            || !is_string($manifest['config_fingerprint'] ?? null)
            || !is_array($manifest['intermix'] ?? null)
            || !is_array($manifest['webrick'] ?? null)
            || !is_string($manifest['intermix']['path'] ?? null)
            || !is_string($manifest['intermix']['digest'] ?? null)
            || !is_string($manifest['intermix']['graph'] ?? null)
            || !is_string($manifest['intermix']['build'] ?? null)
            || !is_array($manifest['intermix']['compiled'] ?? null)
            || !is_array($manifest['intermix']['skipped'] ?? null)
            || !is_string($manifest['webrick']['path'] ?? null)
            || !is_string($manifest['webrick']['digest'] ?? null)
            || !is_string($manifest['webrick']['fingerprint'] ?? null)
        ) {
            throw new \UnexpectedValueException('Compiled release fixture has an invalid manifest shape.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \LogicException
     */
    public function testCoordinatedReleaseBootRejectsStaleAndObsoleteMetadata(): void
    {
        [$intermixPath, $routerPath] = self::artifactPaths('release');
        $releasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'webrick-coordinated-release-' . bin2hex(random_bytes(8)) . '.json';
        $builder = ContainerBuilder::create('webrick_coordinated_release_' . bin2hex(random_bytes(4)));

        try {
            $manifest = new ReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar): void {
                    $registrar->get('/release', static fn(): Response => Response::plaintext('release-ok', 200));
                },
                environment: 'production',
                configFingerprint: 'coordinated-release',
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $releasePath,
                preGlobalTags: [],
                postGlobalTags: [],
            );

            self::assertReleaseFixture($manifest);
            self::assertSame(ReleaseCompiler::RELEASE_FORMAT, $manifest['format']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $manifest['intermix']['graph']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $manifest['intermix']['build']);

            $kernel = CompiledRouterKernel::fromReleaseManifest(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                builder: $builder,
                releaseManifestPath: $releasePath,
                environment: 'production',
                configFingerprint: 'coordinated-release',
                errorHandler: new ErrorHandler(logger: new NullLogger(), debug: true),
            );

            $response = $kernel->handle(Request::fake(uri: 'http://localhost/release'));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('release-ok', (string) $response->getBody());

            $stale = $manifest;
            $stale['intermix']['build'] = str_repeat('0', 32);
            $stale['release_fingerprint'] = ReleaseCompiler::fingerprintManifest($stale);
            file_put_contents(
                $releasePath,
                json_encode($stale, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/InterMix release build identity mismatch/');

            CompiledRouterKernel::fromReleaseManifest(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                builder: $builder,
                releaseManifestPath: $releasePath,
                environment: 'production',
                configFingerprint: 'coordinated-release',
            );
        } finally {
            self::cleanup([$intermixPath, $routerPath]);
            foreach ([$releasePath, ReleaseCompiler::runtimeManifestPath($releasePath)] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function testCoordinatedReleaseRejectsObsoleteManifestFormat(): void
    {
        [$intermixPath, $routerPath] = self::artifactPaths('obsolete-release');
        $releasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'webrick-obsolete-release-' . bin2hex(random_bytes(8)) . '.json';
        $builder = ContainerBuilder::create('webrick_obsolete_release_' . bin2hex(random_bytes(4)));

        try {
            $manifest = new ReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar): void {
                    $registrar->get('/release', static fn(): Response => Response::plaintext('release-ok', 200));
                },
                environment: 'production',
                configFingerprint: 'obsolete-release',
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $releasePath,
                preGlobalTags: [],
                postGlobalTags: [],
            );
            $manifest['format'] = 2;
            file_put_contents(
                $releasePath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Unsupported Webrick coordinated release manifest format/');

            CompiledRouterKernel::fromReleaseManifest(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                builder: $builder,
                releaseManifestPath: $releasePath,
                environment: 'production',
                configFingerprint: 'obsolete-release',
            );
        } finally {
            self::cleanup([$intermixPath, $routerPath]);
            foreach ([$releasePath, ReleaseCompiler::runtimeManifestPath($releasePath)] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function compiledReleaseBootModes(): iterable
    {
        foreach (['fused', 'generated', 'sharded'] as $matcher) {
            foreach (['php', 'json'] as $manifest) {
                foreach ([false, true] as $trusted) {
                    yield $matcher . '-' . $manifest . ($trusted ? '-trusted' : '-verified') => [$matcher, $manifest, $trusted];
                }
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \LogicException
     */
    #[DataProvider('compiledReleaseBootModes')]
    public function testDirectCompiledFastPathsRemainScopeless(string $matcherMode, string $manifestRepresentation, bool $trusted): void
    {
        [$intermixPath, $routerPath] = self::artifactPaths('fast-path');
        $releasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'webrick-fast-path-release-' . bin2hex(random_bytes(8)) . '.json';
        $builder = ContainerBuilder::create('webrick_fast_path_' . bin2hex(random_bytes(4)))
            ->releaseIdentity('webrick-fast-path')
            ->factory('789', static fn(): string => 'numeric-service')
            ->onScopeLeave('webrick.request', [CompiledFastPathScopeProbe::class, 'scopeLeft']);

        CompiledFastPathScopeProbe::$leaves = 0;

        try {
            new ReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar): void {
                    $registrar->get('/zero', static fn(): Response => Response::plaintext('zero', 200));
                    $registrar->get('/arg/{id}', static fn(string $id): Response => Response::plaintext($id, 200), [
                        'name' => '123',
                        'aliases' => ['456'],
                    ]);
                    $registrar->get('/scoped', CompiledFastPathScopedHandler::class);
                },
                environment: 'production',
                configFingerprint: 'compiled-fast-path',
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $releasePath,
                preGlobalTags: [],
                postGlobalTags: [],
                enrichGraph: static function (ContainerBuilder $activeBuilder, RouterBuildResult $routes): void {
                    unset($routes);
                    $activeBuilder
                        ->autowire(
                            CompiledFastPathScopedMarker::class,
                            CompiledFastPathScopedMarker::class,
                            lifetime: LifetimeEnum::Scoped,
                        )
                        ->autowire(
                            CompiledFastPathScopedHandler::class,
                            CompiledFastPathScopedHandler::class,
                            lifetime: LifetimeEnum::Transient,
                        );
                },
            );

            if ($manifestRepresentation === 'json') {
                unlink(ReleaseCompiler::runtimeManifestPath($releasePath));
            }
            $release = new ReleaseManifestLoader()->load($releasePath);
            self::assertReleaseFixture($release);
            self::assertArrayHasKey('789', $release['intermix']['skipped']);
            self::assertSame('numeric-service', $builder->production($intermixPath)->get('789'));
            $matcher = match ($matcherMode) {
                'fused' => FusedMatcher::make(),
                'generated' => GeneratedMatcher::make(),
                'sharded' => ShardedMatcher::make(),
                default => throw new \UnexpectedValueException('Unsupported matcher fixture mode.'),
            };
            $kernel = $trusted
                ? CompiledRouterKernel::fromPrevalidatedArtifact(
                    log: new NullLogger(),
                    matcher: $matcher,
                    container: $builder->productionPrevalidated($release['intermix']['path'], $release['intermix']['digest']),
                    artifactPath: $release['webrick']['path'],
                    trustedArtifactFingerprint: $release['webrick']['fingerprint'],
                    environment: $release['environment'],
                    configFingerprint: $release['config_fingerprint'],
                )
                : CompiledRouterKernel::fromReleaseManifest(
                    log: new NullLogger(),
                    matcher: $matcher,
                    builder: $builder,
                    releaseManifestPath: $releasePath,
                    environment: 'production',
                    configFingerprint: 'compiled-fast-path',
                );

            self::assertSame('zero', (string) $kernel->handle(Request::fake(uri: 'http://localhost/zero'))->getBody());
            self::assertSame(0, CompiledFastPathScopeProbe::leaveCount());

            self::assertSame('42', (string) $kernel->handle(Request::fake(uri: 'http://localhost/arg/42'))->getBody());
            self::assertSame(0, CompiledFastPathScopeProbe::leaveCount());
            self::assertSame('/arg/42', UrlGeneratorRegistry::get()->urlFor('123', ['id' => '42']));
            self::assertSame('/arg/42', UrlGeneratorRegistry::get()->urlFor('456', ['id' => '42']));

            $scoped = $kernel->handle(Request::fake(uri: 'http://localhost/scoped'));
            self::assertSame(CompiledFastPathScopedMarker::class, (string) $scoped->getBody());
            self::assertSame(1, CompiledFastPathScopeProbe::leaveCount());
        } finally {
            CompiledFastPathScopeProbe::$leaves = 0;
            self::cleanup([$intermixPath, $routerPath]);
            foreach ([$releasePath, ReleaseCompiler::runtimeManifestPath($releasePath)] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \LogicException
     */
    #[DataProvider('compiledReleaseBootModes')]
    public function testNativeRequestInputsAndHostAutoBindingSurviveCompiledBoot(
        string $matcherMode,
        string $manifestRepresentation,
        bool $trusted,
    ): void {
        [$intermixPath, $routerPath] = self::artifactPaths('native-input');
        $releasePath = $routerPath . '.release.json';
        $builder = ContainerBuilder::create('webrick_native_input_' . bin2hex(random_bytes(4)))
            ->enableAutoBind()
            ->value('count', 0)
            ->value('enabled', false)
            ->value('label', null)
            ->autowire(CompiledNativeRequestController::class, CompiledNativeRequestController::class,
                lifetime: LifetimeEnum::Transient);

        try {
            new ReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar): void {
                    $registrar->get('/bound/{id}', static fn(CompiledBoundRouteIdentifier $id): Response => Response::plaintext($id->value, 200));
                    $registrar->get('/native/{id}', CompiledNativeRequestController::class);
                    $registrar->get('/primitives', static fn(int $count = 1, bool $enabled = true, ?string $label = 'default'): Response => Response::json([$count, $enabled, $label]));
                    $registrar->get('/bound-options/{id}', static fn(string $id, int $count = 1): Response => Response::json([$id, $count]));
                },
                environment: 'production',
                configFingerprint: 'native-input',
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $releasePath,
                preGlobalTags: [],
                postGlobalTags: [],
            );
            if ($manifestRepresentation === 'json') {
                unlink(ReleaseCompiler::runtimeManifestPath($releasePath));
            }
            $release = new ReleaseManifestLoader()->load($releasePath);
            self::assertReleaseFixture($release);
            self::assertContains(Request::class, $release['intermix']['compiled']);
            self::assertSame([], $release['intermix']['skipped']);
            $container = $trusted
                ? $builder->productionPrevalidated($intermixPath, $release['intermix']['digest'])
                : ContainerBuilder::loadProductionArtifact($intermixPath, $release['intermix']['graph']);
            unset($builder);
            $matcher = match ($matcherMode) {
                'fused' => FusedMatcher::make(),
                'generated' => GeneratedMatcher::make(),
                'sharded' => ShardedMatcher::make(),
                default => throw new \UnexpectedValueException('Unsupported matcher fixture mode.'),
            };
            $kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
                log: new NullLogger(),
                matcher: $matcher,
                container: $container,
                artifactPath: $routerPath,
                trustedArtifactFingerprint: $release['webrick']['fingerprint'],
                environment: 'production',
                configFingerprint: 'native-input',
                errorHandler: new ErrorHandler(logger: new NullLogger(),
                    exceptionMap: [\DomainException::class => 422], debug: true),
            );

            foreach (['0', '42'] as $id) {
                $bound = $kernel->handle(Request::fake(uri: 'http://localhost/bound/' . $id));
                self::assertSame(200, $bound->getStatusCode(), (string) $bound->getBody());
                self::assertSame($id, (string) $bound->getBody());
                $native = $kernel->handle(Request::fake(uri: 'http://localhost/native/' . $id));
                self::assertSame(200, $native->getStatusCode(), (string) $native->getBody());
                self::assertSame($id . '|/native/' . $id . '|/native/' . $id, (string) $native->getBody());
                try {
                    $container->get(Request::class);
                    self::fail('The request input escaped its execution scope.');
                } catch (ContainerException) {
                    // The native input must fail closed after each response.
                }
            }
            self::assertSame(422, $kernel->handle(Request::fake(uri: 'http://localhost/bound/missing'))->getStatusCode());
            self::assertSame('43', (string) $kernel->handle(Request::fake(uri: 'http://localhost/bound/43'))->getBody());
            self::assertSame('[0,false,null]', (string) $kernel->handle(Request::fake(uri: 'http://localhost/primitives'))->getBody());
            self::assertSame('["42",0]', (string) $kernel->handle(Request::fake(uri: 'http://localhost/bound-options/42'))->getBody());
        } finally {
            self::cleanup([$intermixPath, $routerPath]);
            foreach ([$releasePath, ReleaseCompiler::runtimeManifestPath($releasePath)] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \LogicException
     */
    public function testDevelopmentBindingUsesTheHostsOptInSetting(): void
    {
        foreach ([null, false, true] as $enabled) {
            $builder = ContainerBuilder::create('webrick_binding_' . bin2hex(random_bytes(4)))
                ->input(Request::class)
                ->value('count', 0)
                ->value('enabled', false)
                ->value('label', null);
            if ($enabled !== null) {
                $builder->enableAutoBind($enabled);
            }
            $kernel = RouterKernel::bootWithRegistrar(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                register: static function (Registrar $registrar): void {
                    $registrar->get('/bound/{id}', static fn(CompiledBoundRouteIdentifier $id): Response => Response::plaintext($id->value, 200));
                    $registrar->get('/primitives', static fn(int $count = 1, bool $enabled = true, ?string $label = 'default'): Response => Response::json([$count, $enabled, $label]));
                },
                invoker: $builder->build(),
                preGlobalTags: [],
                postGlobalTags: [],
                errorHandler: new ErrorHandler(exceptionMap: [\DomainException::class => 422]),
            );

            $response = $kernel->handle(Request::fake(uri: 'http://localhost/bound/42'));
            self::assertSame($enabled === true ? 200 : 500, $response->getStatusCode());
            if ($enabled === true) {
                self::assertSame('42', (string) $response->getBody());
            }
            self::assertSame($enabled === true ? 422 : 500,
                $kernel->handle(Request::fake(uri: 'http://localhost/bound/missing'))->getStatusCode());
            self::assertSame('[0,false,null]', (string) $kernel->handle(Request::fake(uri: 'http://localhost/primitives'))->getBody());
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \LogicException
     */
    public function testDefaultRoutingControlsStayIndependentFromApplicationErrors(): void
    {
        [$intermixPath, $routerPath] = self::artifactPaths('default');
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

            $notFound = $kernel->handle(Request::fake(uri: 'http://localhost/missing'));
            $methodNotAllowed = $kernel->handle(Request::fake(method: 'POST', uri: 'http://localhost/known'));

            self::assertSame(404, $notFound->getStatusCode());
            self::assertSame('no-store', $notFound->getHeaderLine('Cache-Control'));
            self::assertSame(405, $methodNotAllowed->getStatusCode());
            self::assertStringContainsString('GET', $methodNotAllowed->getHeaderLine('Allow'));
            self::assertSame(0, $applicationErrors);
        } finally {
            self::cleanup([$intermixPath, $routerPath]);
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \LogicException
     */
    public function testRoutingControlsUseApplicationErrorHandlerOnlyWhenEnabled(): void
    {
        [$intermixPath, $routerPath] = self::artifactPaths('opt-in');
        $builder = ContainerBuilder::create('webrick_bridge_routed_controls_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get('/known', static fn(): Response => Response::plaintext('known'));
            },
            environment: 'production',
            configFingerprint: 'foundation-routed-controls',
        );
        $applicationErrors = 0;
        $errorHandler = new ErrorHandler(
            logger: new NullLogger(),
            responseRenderer: static function () use (&$applicationErrors): Response {
                ++$applicationErrors;

                return Response::plaintext('application-routing-control', 599);
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
                configFingerprint: 'foundation-routed-controls',
                errorHandler: $errorHandler,
                routeErrorsThroughErrorHandler: true,
            );

            $response = $kernel->handle(Request::fake(uri: 'http://localhost/missing'));

            self::assertSame(599, $response->getStatusCode());
            self::assertStringContainsString('application-routing-control', (string) $response->getBody());
            self::assertSame(1, $applicationErrors);
        } finally {
            self::cleanup([$intermixPath, $routerPath]);
        }
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(string $suffix): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-bridge-controls-' . $suffix . '-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /**
     * @param list<string> $paths
     * @throws \RuntimeException
     */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new \RuntimeException("Unable to remove compiled routing-control fixture: {$candidate}");
                }
            }
        }
    }
}
