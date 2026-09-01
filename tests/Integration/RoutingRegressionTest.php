<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

function routingRegressionInvoker(): Invoker
{
    return Invoker::with(new Container('webrick.tests.routing-regression'));
}

dataset('routing regression matchers', [
    'fused' => [static fn(): MatcherInterface => FusedMatcher::make()],
    'sharded' => [static fn(): MatcherInterface => ShardedMatcher::make()],
    'generated' => [static fn(): MatcherInterface => GeneratedMatcher::make()],
]);

describe('Routing Regressions', function () {
    it('routes POST method-override requests using effective method', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: FusedMatcher::make(),
            register: static function (Registrar $r): void {
                $r->put('/resource', static fn () => Response::plaintext('updated', 200));
            },
            invoker: routingRegressionInvoker(),
        );

        $request = mockRequest('POST', '/resource', [
            'X-HTTP-Method-Override' => 'PUT',
        ]);

        $response = $kernel->handle($request);

        expect($response)->toHaveStatus(200)
            ->and((string) $response->getBody())->toBe('updated');
    });

    it('answers automatic OPTIONS without executing a business handler', function (Closure $matcherFactory) {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: $matcherFactory(),
            register: static function (Registrar $r): void {
                $r->get('/resource', static function (): Response {
                    throw new RuntimeException('GET business handler must not execute for automatic OPTIONS.');
                });
                $r->post('/resource', static function (): Response {
                    throw new RuntimeException('POST business handler must not execute for automatic OPTIONS.');
                });
            },
            invoker: routingRegressionInvoker(),
        );

        $response = $kernel->handle(mockRequest('OPTIONS', '/resource'));
        $allow = array_map(trim(...), explode(',', $response->getHeaderLine('Allow')));

        expect($response)->toHaveStatus(204)
            ->and($allow)->toContain('GET', 'HEAD', 'POST', 'OPTIONS');
    })->with('routing regression matchers');

    it('honors an explicit OPTIONS route', function (Closure $matcherFactory) {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: $matcherFactory(),
            register: static function (Registrar $r): void {
                $r->get('/resource', static fn(): Response => Response::plaintext('get', 200));
                $r->options('/resource', static fn(): Response => Response::plaintext('explicit-options', 200));
            },
            invoker: routingRegressionInvoker(),
        );

        $response = $kernel->handle(mockRequest('OPTIONS', '/resource'));

        expect($response)->toHaveStatus(200)
            ->and((string) $response->getBody())->toBe('explicit-options');
    })->with('routing regression matchers');

    it('does not expose throwable details with the default kernel error boundary', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: FusedMatcher::make(),
            register: static function (Registrar $r): void {
                $r->get('/explode', static function (): Response {
                    throw new RuntimeException('internal-secret-message');
                });
            },
            invoker: routingRegressionInvoker(),
        );

        $response = $kernel->handle(mockRequest('GET', '/explode'));
        $body = (string) $response->getBody();

        expect($response)->toHaveStatus(500)
            ->and($body)->not->toContain('internal-secret-message')
            ->and($body)->not->toContain(RuntimeException::class);
    });

    it('exposes route params under compatibility attribute keys', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger,
            matcher: FusedMatcher::make(),
            register: static function (Registrar $r): void {
                $r->get('/lang/{locale}', static fn (): Response => Response::plaintext('ok'), [
                    'middleware' => [
                        static function (Request $request): Response {
                            return Response::json([
                                'route_params' => $request->getAttribute('route_params'),
                                'route.params' => $request->getAttribute('route.params'),
                                'params' => $request->getAttribute('params'),
                            ]);
                        },
                    ],
                ]);
            },
            invoker: routingRegressionInvoker(),
        );

        $request = mockRequest('GET', '/lang/en');
        $response = $kernel->handle($request);

        $body = json_decode((string) $response->getBody(), true);

        expect($response)->toHaveStatus(200)
            ->and($body['route_params']['locale'] ?? null)->toBe('en')
            ->and($body['route.params']['locale'] ?? null)->toBe('en')
            ->and($body['params']['locale'] ?? null)->toBe('en');
    });
});
