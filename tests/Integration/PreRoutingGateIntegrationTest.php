<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Middleware\Maintenance\MaintenancePreRoutingGate;
use Infocyph\Webrick\Middleware\Maintenance\MemoryMaintenanceState;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class PreRoutingGateIntegrationTest extends TestCase
{
    public function testInactiveGateKeepsCompiledRequestlessRouteRequestless(): void
    {
        $state = new MemoryMaintenanceState();
        [$kernel, $paths] = self::kernel($state);
        $materializations = 0;

        try {
            $response = $kernel->handleRuntime(self::context('GET', '/known', $materializations));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('known', (string) $response->getBody());
            self::assertSame(0, $materializations);
        } finally {
            self::cleanup($paths);
        }
    }

    public function testActiveGateShortCircuitsBeforeRequestMaterialization(): void
    {
        $state = new MemoryMaintenanceState();
        $state->enable('Deploying');
        [$kernel, $paths] = self::kernel($state);
        $materializations = 0;

        try {
            $response = $kernel->handleRuntime(self::context('GET', '/known', $materializations));

            self::assertSame(503, $response->getStatusCode());
            self::assertSame("503 Service Unavailable\nDeploying", (string) $response->getBody());
            self::assertSame('3600', $response->getHeaderLine('Retry-After'));
            self::assertSame(0, $materializations);
        } finally {
            self::cleanup($paths);
        }
    }

    public function testActiveGatePreservesHeadSemanticsWithoutRequestMaterialization(): void
    {
        $state = new MemoryMaintenanceState();
        $state->enable('Deploying');
        [$kernel, $paths] = self::kernel($state);
        $materializations = 0;

        try {
            $response = $kernel->handleRuntime(self::context('HEAD', '/known', $materializations));

            self::assertSame(503, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
            self::assertSame((string) strlen("503 Service Unavailable\nDeploying"), $response->getHeaderLine('Content-Length'));
            self::assertSame(0, $materializations);
        } finally {
            self::cleanup($paths);
        }
    }

    public function testExactBypassFallsThroughToCompiledRouteWithoutMaterialization(): void
    {
        $state = new MemoryMaintenanceState();
        $state->enable('Deploying');
        [$kernel, $paths] = self::kernel($state, ['/ready']);
        $materializations = 0;

        try {
            $response = $kernel->handleRuntime(self::context('GET', '/ready', $materializations));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('ready', (string) $response->getBody());
            self::assertSame(0, $materializations);
        } finally {
            self::cleanup($paths);
        }
    }

    /** @param list<string> $bypassPaths @return array{0:CompiledRouterKernel,1:list<string>} */
    private static function kernel(MemoryMaintenanceState $state, array $bypassPaths = []): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $builder = ContainerBuilder::create('webrick_pre_gate_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get('/known', static fn(): Response => Response::plaintext('known'));
                $registrar->get('/ready', static fn(): Response => Response::plaintext('ready'));
            },
            environment: 'production',
            configFingerprint: 'wb5-pre-routing-gate',
        );

        $builder->compile($intermixPath);
        $container = $builder->production($intermixPath);
        new RouterArtifactCompiler()->compile($build, $routerPath);
        $kernel = CompiledRouterKernel::fromCompiledArtifact(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            container: $container,
            artifactPath: $routerPath,
            environment: 'production',
            configFingerprint: 'wb5-pre-routing-gate',
            preRoutingGate: new MaintenancePreRoutingGate(state: $state, bypassPaths: $bypassPaths),
        );

        return [$kernel, [$intermixPath, $routerPath]];
    }

    private static function context(string $method, string $path, int &$materializations): RuntimeRequestContext
    {
        return new RuntimeRequestContext(
            new RoutingInput($method, $path),
            static function () use (&$materializations, $method, $path): Request {
                ++$materializations;

                return Request::fake(method: $method, uri: $path);
            },
            new RuntimeCapabilities('test', persistent: true, concurrent: true),
        );
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-pre-gate-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new \RuntimeException("Unable to remove WB-5 fixture: {$candidate}");
                }
            }
        }
    }
}
