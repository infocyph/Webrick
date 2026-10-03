<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final readonly class CompiledFastPathScopedMarker {}

final readonly class CompiledFastPathScopedHandler
{
    public function __construct(private CompiledFastPathScopedMarker $marker) {}

    public function __invoke(): Response
    {
        return Response::plaintext($this->marker::class);
    }
}

final class CompiledFastPathScopeProbe
{
    public static int $leaves = 0;

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
                    $registrar->get('/release', static fn(): Response => Response::plaintext('release-ok'));
                },
                environment: 'production',
                configFingerprint: 'coordinated-release',
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $releasePath,
                preGlobalTags: [],
                postGlobalTags: [],
            );

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
            );

            $response = $kernel->handle(Request::fake(uri: 'http://localhost/release'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('release-ok', (string) $response->getBody());

            $stale = $manifest;
            $stale['intermix']['build'] = str_repeat('0', 32);
            $stale['release_fingerprint'] = ReleaseCompiler::fingerprintManifest($stale);
            file_put_contents(
                $releasePath,
                json_encode($stale, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('InterMix release build identity mismatch');

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
                    $registrar->get('/release', static fn(): Response => Response::plaintext('release-ok'));
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
            $this->expectExceptionMessage('Unsupported Webrick coordinated release manifest format');

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

    public function testDirectCompiledFastPathsRemainScopeless(): void
    {
        [$intermixPath, $routerPath] = self::artifactPaths('fast-path');
        $releasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'webrick-fast-path-release-' . bin2hex(random_bytes(8)) . '.json';
        $builder = ContainerBuilder::create('webrick_fast_path_' . bin2hex(random_bytes(4)))
            ->releaseIdentity('webrick-fast-path')
            ->onScopeLeave('webrick.request', [CompiledFastPathScopeProbe::class, 'scopeLeft']);

        CompiledFastPathScopeProbe::$leaves = 0;

        try {
            new ReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar): void {
                    $registrar->get('/zero', static fn(): Response => Response::plaintext('zero'));
                    $registrar->get('/arg/{id}', static fn(string $id): Response => Response::plaintext($id));
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

            $kernel = CompiledRouterKernel::fromReleaseManifest(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                builder: $builder,
                releaseManifestPath: $releasePath,
                environment: 'production',
                configFingerprint: 'compiled-fast-path',
            );

            self::assertSame('zero', (string) $kernel->handle(Request::fake(uri: 'http://localhost/zero'))->getBody());
            self::assertSame(0, CompiledFastPathScopeProbe::$leaves);

            self::assertSame('42', (string) $kernel->handle(Request::fake(uri: 'http://localhost/arg/42'))->getBody());
            self::assertSame(0, CompiledFastPathScopeProbe::$leaves);

            $scoped = $kernel->handle(Request::fake(uri: 'http://localhost/scoped'));
            self::assertSame(CompiledFastPathScopedMarker::class, (string) $scoped->getBody());
            self::assertSame(1, CompiledFastPathScopeProbe::$leaves);
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

    /** @param list<string> $paths */
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
