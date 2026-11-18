<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
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


final readonly class RiskCompiledHostIdentity
{
    public string $value;

    public function __construct()
    {
        $this->value = bin2hex(random_bytes(12));
    }
}

final readonly class RiskCompiledHostPolicy
{
    public function __construct(private RiskCompiledHostIdentity $identity) {}

    /**
     * @param Closure(Request):Response $next
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        $middleware = new RiskAdmissionMiddleware(
            static fn(Request $current): RiskDecision => $current->getAttribute('host.proof_valid') === true
                ? RiskDecision::allow()
                : RiskDecision::challenge(),
        );

        return $middleware($request->withAttribute('host.middleware_scope_id', $this->identity->value), $next);
    }
}

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

    /**
     * @throws \Throwable
     */
    public function testCompiledRouterResolvesFreshScopedClassPolicyForEachTenant(): void
    {
        $fingerprint = 'risk-host-scoped-artifact';
        $base = sys_get_temp_dir() . '/webrick-risk-scoped-' . bin2hex(random_bytes(8));
        $intermixPath = $base . '.intermix.php';
        $routerPath = $base . '.router.php';
        $register = static function (Registrar $routes): void {
            $routes->get('/health', static fn(): Response => Response::plaintext('ok', 200));
            $routes->post('/auth/session',
                static fn(Request $request, RiskCompiledHostIdentity $identity): Response => Response::json([
                    'same_scope' => $identity->value === $request->getAttribute('host.middleware_scope_id'),
                    'scope' => $identity->value,
                    'tenant' => $request->getAttribute('host.tenant'),
                ], 200),
                ['middleware' => [RiskCompiledHostPolicy::class]],
            );
        };

        try {
            $nativeBuilder = ContainerBuilder::create('risk-host-native-' . bin2hex(random_bytes(4)))
                ->input(Request::class)
                ->autowire(RiskCompiledHostIdentity::class, RiskCompiledHostIdentity::class, lifetime: LifetimeEnum::Scoped)
                ->autowire(RiskCompiledHostPolicy::class, RiskCompiledHostPolicy::class, lifetime: LifetimeEnum::Transient);
            $native = RouterKernel::bootWithRegistrar(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                register: $register,
                invoker: $nativeBuilder->build(),
            );

            $builder = ContainerBuilder::create('risk-host-compiled-' . bin2hex(random_bytes(4)))
                ->input(Request::class)
                ->autowire(RiskCompiledHostIdentity::class, RiskCompiledHostIdentity::class, lifetime: LifetimeEnum::Scoped)
                ->autowire(RiskCompiledHostPolicy::class, RiskCompiledHostPolicy::class, lifetime: LifetimeEnum::Transient);
            $build = new RouteCompiler()->compile(
                register: $register,
                environment: 'production',
                configFingerprint: $fingerprint,
            );
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
            unset($builder);
            new RouterArtifactCompiler()->compile($build, $routerPath);

            $compiled = CompiledRouterKernel::fromCompiledArtifact(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                container: $container,
                artifactPath: $routerPath,
                environment: 'production',
                configFingerprint: $fingerprint,
            );

            foreach ([$native, $compiled] as $kernel) {
                $health = $kernel->handle(mockRequest('GET', '/health'));
                $rejected = $kernel->handle(mockRequest('POST', '/auth/session'));
                $first = $kernel->handle(mockRequest('POST', '/auth/session')
                    ->withAttribute('host.proof_valid', true)
                    ->withAttribute('host.tenant', 'tenant-a'));
                $second = $kernel->handle(mockRequest('POST', '/auth/session')
                    ->withAttribute('host.proof_valid', true)
                    ->withAttribute('host.tenant', 'tenant-b'));

                self::assertSame(200, $health->getStatusCode());
                self::assertSame('ok', (string) $health->getBody());
                self::assertSame(429, $rejected->getStatusCode());
                self::assertSame('no-store', $rejected->getHeaderLine('Cache-Control'));
                self::assertSame(200, $first->getStatusCode());
                self::assertSame(200, $second->getStatusCode());

                $firstBody = json_decode((string) $first->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $secondBody = json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($firstBody);
                self::assertIsArray($secondBody);
                self::assertTrue($firstBody['same_scope'] === true);
                self::assertTrue($secondBody['same_scope'] === true);
                self::assertSame('tenant-a', $firstBody['tenant']);
                self::assertSame('tenant-b', $secondBody['tenant']);
                self::assertIsString($firstBody['scope']);
                self::assertIsString($secondBody['scope']);
                self::assertNotSame($firstBody['scope'], $secondBody['scope']);
            }
        } finally {
            foreach ([$intermixPath, $routerPath] as $path) {
                foreach ([$path, $path . '.meta.json'] as $candidate) {
                    if (is_file($candidate) && !unlink($candidate)) {
                        throw new RuntimeException("Unable to remove scoped risk artifact fixture: {$candidate}");
                    }
                }
            }
        }
    }

}
