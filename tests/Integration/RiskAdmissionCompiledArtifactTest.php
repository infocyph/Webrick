<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class RiskAdmissionCompiledArtifactTest extends TestCase
{
    /**
     * @throws \Throwable
     */
    public function testCompiledRouterPreservesHostRiskDecisionsAndRouteOptIn(): void
    {
        $fingerprint = 'risk-admission-parity';
        $base = sys_get_temp_dir() . '/webrick-risk-parity-' . bin2hex(random_bytes(8));
        $intermixPath = $base . '.intermix.php';
        $routerPath = $base . '.router.php';

        $register = static function (Registrar $routes): void {
            $routes->get('/health', static fn(): Response => Response::plaintext('ok', 200));
            $routes->post(
                '/session',
                static fn(): Response => Response::plaintext('handler-reached', 200),
                ['middleware' => [
                    new RiskAdmissionMiddleware(
                        static fn(Request $request): RiskDecision => $request->getAttribute('host.approved') === true
                            ? RiskDecision::allow()
                            : RiskDecision::challenge(),
                    ),
                ]],
            );
        };

        try {
            $native = RouterKernel::bootWithRegistrar(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                register: $register,
                invoker: ContainerBuilder::create('risk-native-' . bin2hex(random_bytes(4)))
                    ->input(Request::class)
                    ->build(),
            );

            $health = $native->handle(mockRequest('GET', '/health'));
            $rejected = $native->handle(mockRequest('POST', '/session'));
            $allowed = $native->handle(mockRequest('POST', '/session')->withAttribute('host.approved', true));

            $builder = ContainerBuilder::create('risk-compiled-' . bin2hex(random_bytes(4)))
                ->input(Request::class);
            $build = new RouteCompiler()->compile(
                register: $register,
                environment: 'production',
                configFingerprint: $fingerprint,
            );
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
            new RouterArtifactCompiler()->compile($build, $routerPath);

            $compiled = CompiledRouterKernel::fromCompiledArtifact(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                container: $container,
                artifactPath: $routerPath,
                environment: 'production',
                configFingerprint: $fingerprint,
            );

            $compiledHealth = $compiled->handle(mockRequest('GET', '/health'));
            $compiledRejected = $compiled->handle(mockRequest('POST', '/session'));
            $compiledAllowed = $compiled->handle(
                mockRequest('POST', '/session')->withAttribute('host.approved', true),
            );

            self::assertSame(200, $health->getStatusCode());
            self::assertSame($health->getStatusCode(), $compiledHealth->getStatusCode());
            self::assertSame((string) $health->getBody(), (string) $compiledHealth->getBody());
            self::assertSame(429, $compiledRejected->getStatusCode());
            self::assertSame($rejected->getStatusCode(), $compiledRejected->getStatusCode());
            self::assertSame($rejected->getHeaderLine('Cache-Control'), $compiledRejected->getHeaderLine('Cache-Control'));
            self::assertSame((string) $rejected->getBody(), (string) $compiledRejected->getBody());
            self::assertSame($allowed->getStatusCode(), $compiledAllowed->getStatusCode());
            self::assertSame('handler-reached', (string) $compiledAllowed->getBody());
        } finally {
            foreach ([$intermixPath, $routerPath] as $path) {
                foreach ([$path, $path . '.meta.json'] as $candidate) {
                    if (is_file($candidate) && !unlink($candidate)) {
                        throw new RuntimeException("Unable to clean risk artifact fixture: {$candidate}");
                    }
                }
            }
        }
    }
}
