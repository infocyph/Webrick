<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Log\NullLogger;

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
