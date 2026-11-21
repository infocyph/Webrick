<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Log\NullLogger;


final readonly class RiskAdmissionHostScopeIdentity
{
    public function __construct(public string $id) {}
}

final readonly class RiskAdmissionHostScopedMiddleware
{
    public function __construct(private RiskAdmissionHostScopeIdentity $identity) {}

    /**
     * @param \Closure(Request):Response $next
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     */
    public function __invoke(Request $request, \Closure $next): Response
    {
        $admission = new RiskAdmissionMiddleware(
            static fn(Request $current): RiskDecision => $current->getAttribute('host.proof_verified') === true
                ? RiskDecision::allow()
                : RiskDecision::challenge(),
        );

        return $admission(
            $request->withAttribute('fixture.middleware_scope', $this->identity->id),
            $next,
        );
    }
}

test('host-selected protected paths are equivalent and unprotected traffic skips policy', function (string $path): void {
    $evaluations = 0;
    $handlerCalls = 0;
    $middleware = new RiskAdmissionMiddleware(
        static function (Request $request) use (&$evaluations): RiskDecision {
            ++$evaluations;

            return $request->getAttribute('host.proof_verified') === true
                ? RiskDecision::allow(reasonCodes: ['proof_verified'])
                : RiskDecision::challenge(reasonCodes: ['proof_required']);
        },
    );

    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger(),
        matcher: FusedMatcher::make(),
        register: static function (Registrar $routes) use ($path, $middleware, &$handlerCalls): void {
            $routes->get('/health', static fn(): Response => Response::plaintext('ok', 200));
            $routes->post($path, static function () use (&$handlerCalls): Response {
                ++$handlerCalls;

                return Response::json(['ok' => true]);
            }, ['middleware' => [$middleware]]);
        },
        invoker: ContainerBuilder::create('risk-host-path-' . bin2hex(random_bytes(6)))
            ->input(Request::class)
            ->build(),
    );

    $health = $kernel->handle(mockRequest('GET', '/health'));
    $rejected = $kernel->handle(mockRequest('POST', $path));
    $allowed = $kernel->handle(mockRequest('POST', $path)->withAttribute('host.proof_verified', true));

    expect($health->getStatusCode())->toBe(200);
    expect($rejected->getStatusCode())->toBe(429);
    expect($rejected->getHeaderLine('Cache-Control'))->toBe('no-store');
    expect((string) $rejected->getBody())->toContain('challenge_required');
    expect($allowed->getStatusCode())->toBe(200);
    expect($handlerCalls)->toBe(1);
    expect($evaluations)->toBe(2);
})->with([
    '/auth/session',
    '/v3/identity/token',
]);

test('shadow policy forwards a valid unavailable verdict without becoming a live provider', function (): void {
    $evaluations = 0;
    $handlerCalls = 0;
    $middleware = new RiskAdmissionMiddleware(
        static function () use (&$evaluations): RiskDecision {
            ++$evaluations;

            return RiskDecision::unavailable(reasonCodes: ['provider_unavailable']);
        },
        mode: RiskAdmissionMiddleware::SHADOW,
    );

    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger(),
        matcher: FusedMatcher::make(),
        register: static function (Registrar $routes) use ($middleware, &$handlerCalls): void {
            $routes->post('/account/signin', static function () use (&$handlerCalls): Response {
                ++$handlerCalls;

                return Response::plaintext('host-owned handler', 200);
            }, ['middleware' => [$middleware]]);
        },
        invoker: ContainerBuilder::create('risk-host-shadow-' . bin2hex(random_bytes(6)))
            ->input(Request::class)
            ->build(),
    );

    $response = $kernel->handle(mockRequest('POST', '/account/signin'));

    expect($response->getStatusCode())->toBe(200);
    expect($evaluations)->toBe(1);
    expect($handlerCalls)->toBe(1);
});

test('host risk middleware resolves a fresh scoped identity per protected request', function (): void {
    $created = 0;
    $handled = 0;
    $builder = ContainerBuilder::create('risk-host-scope-' . bin2hex(random_bytes(6)))
        ->input(Request::class)
        ->factory(
            RiskAdmissionHostScopeIdentity::class,
            static function () use (&$created): RiskAdmissionHostScopeIdentity {
                ++$created;

                return new RiskAdmissionHostScopeIdentity(bin2hex(random_bytes(8)));
            },
            LifetimeEnum::Scoped,
        );

    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger(),
        matcher: FusedMatcher::make(),
        register: static function (Registrar $routes) use (&$handled): void {
            $routes->get('/health', static fn(): Response => Response::plaintext('ok', 200));
            $routes->post('/auth/session',
                static function (Request $request, RiskAdmissionHostScopeIdentity $identity) use (&$handled): Response {
                    ++$handled;
                    if ($request->getAttribute('fixture.middleware_scope') !== $identity->id) {
                        throw new RuntimeException('Scoped middleware and handler identities differ.');
                    }

                    return Response::json(['scope' => $identity->id], 200);
                },
                ['middleware' => [RiskAdmissionHostScopedMiddleware::class]],
            );
        },
        invoker: $builder->build(),
    );

    $health = $kernel->handle(mockRequest('GET', '/health'));
    expect($health->getStatusCode())->toBe(200)
        ->and($created)->toBe(0);

    $first = $kernel->handle(mockRequest('POST', '/auth/session')->withAttribute('host.proof_verified', true));
    $rejected = $kernel->handle(mockRequest('POST', '/auth/session'));
    $second = $kernel->handle(mockRequest('POST', '/auth/session')->withAttribute('host.proof_verified', true));

    $firstBody = json_decode((string) $first->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $secondBody = json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($firstBody) || !is_array($secondBody)) {
        throw new UnexpectedValueException('Scoped host responses must be JSON objects.');
    }

    expect($first->getStatusCode())->toBe(200)
        ->and($rejected->getStatusCode())->toBe(429)
        ->and($rejected->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($second->getStatusCode())->toBe(200)
        ->and($firstBody['scope'] ?? null)->toBeString()
        ->and($secondBody['scope'] ?? null)->toBeString()
        ->and($firstBody['scope'] ?? null)->not->toBe($secondBody['scope'] ?? null)
        ->and($created)->toBe(3)
        ->and($handled)->toBe(2);
});
