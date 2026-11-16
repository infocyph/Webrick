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

test('registrar risk middleware only evaluates selected routes and suppresses HEAD rejection bodies', function (): void {
    $evaluations = 0;
    $protectedCalls = 0;
    $middleware = new RiskAdmissionMiddleware(static function () use (&$evaluations): RiskDecision {
        ++$evaluations;

        return RiskDecision::challenge(85, ['proof_required']);
    });

    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger(),
        matcher: FusedMatcher::make(),
        register: static function (Registrar $routes) use ($middleware, &$protectedCalls): void {
            $routes->get('/health', static fn(): Response => Response::plaintext('ok'));
            $routes->get(
                '/protected',
                static function () use (&$protectedCalls): Response {
                    ++$protectedCalls;

                    return Response::plaintext('should not execute');
                },
                ['middleware' => [$middleware]],
            );
        },
        invoker: ContainerBuilder::create('risk-admission-test-' . bin2hex(random_bytes(4)))
            ->input(Request::class)
            ->build(),
    );

    $health = $kernel->handle(Request::fake(uri: 'http://localhost/health'));
    $rejection = $kernel->handle(Request::fake(uri: 'http://localhost/protected'));
    $head = $kernel->handle(Request::fake(method: 'HEAD', uri: 'http://localhost/protected'));

    expect($health->getStatusCode())->toBe(200);
    expect((string) $health->getBody())->toBe('ok');
    expect($rejection->getStatusCode())->toBe(429);
    expect($rejection->getHeaderLine('Cache-Control'))->toBe('no-store');
    expect((string) $rejection->getBody())->toContain('challenge_required');
    expect($head->getStatusCode())->toBe(429);
    expect($head->getHeaderLine('Cache-Control'))->toBe('no-store');
    expect((string) $head->getBody())->toBe('');
    expect($head->getHeaderLine('Content-Length'))->toBe((string) strlen((string) $rejection->getBody()));
    expect($evaluations)->toBe(2);
    expect($protectedCalls)->toBe(0);
});
