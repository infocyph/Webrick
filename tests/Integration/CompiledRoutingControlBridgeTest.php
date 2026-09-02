<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[BackupStaticProperties(true)]
final class CompiledRoutingControlBridgeTest extends TestCase
{
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
