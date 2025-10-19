<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;
use Psr\Log\NullLogger;

class TestMiddleware1 {
    public function __invoke(Request $req, callable $next): Response {
        $req = $req->withAttribute('mw1', true);
        $resp = $next($req);
        return $resp->withHeader('X-MW1', 'passed');
    }
}

class TestMiddleware2 {
    public function __invoke(Request $req, callable $next): Response {
        $req = $req->withAttribute('mw2', true);
        $resp = $next($req);
        return $resp->withHeader('X-MW2', 'passed');
    }
}

// Helper class for tracking middleware execution order
class OrderTrackingMiddleware {
    public static array $executionOrder = [];

    public function __construct(private string $name) {}

    public function __invoke(Request $req, callable $next): Response {
        self::$executionOrder[] = $this->name . '-before';
        $resp = $next($req);
        self::$executionOrder[] = $this->name . '-after';
        return $resp;
    }

    public static function reset(): void {
        self::$executionOrder = [];
    }
}

describe('Middleware Pipeline', function () {
    it('executes global middleware', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/test', fn($req) => Response::json([
                    'mw1' => $req->getAttribute('mw1'),
                    'mw2' => $req->getAttribute('mw2'),
                ]));
            },
            preGlobal: [TestMiddleware1::class],
            postGlobal: [TestMiddleware2::class]
        );

        $request = mockRequest('GET', '/test');
        $response = $kernel->handle($request);

        expect($response)
            ->toHaveStatus(200)
            ->toHaveHeader('X-MW1', 'passed')
            ->toHaveHeader('X-MW2', 'passed');

        $body = json_decode((string)$response->getBody(), true);
        expect($body['mw1'])->toBeTrue();
        expect($body['mw2'])->toBeTrue();
    });

    it('executes route-specific middleware', function () {
        $routeMw = new class {
            public function __invoke(Request $req, callable $next): Response {
                $req = $req->withAttribute('route_mw', true);
                $resp = $next($req);
                return $resp->withHeader('X-Route-MW', 'passed');
            }
        };

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) use ($routeMw) {
                $r->get('/test', fn($req) => Response::json([
                    'route_mw' => $req->getAttribute('route_mw'),
                ]), [
                    'middleware' => [$routeMw],
                ]);
            }
        );

        $request = mockRequest('GET', '/test');
        $response = $kernel->handle($request);

        expect($response)
            ->toHaveStatus(200)
            ->toHaveHeader('X-Route-MW', 'passed');
    });

    it('executes middleware in correct order', function () {
        OrderTrackingMiddleware::reset();

        $mw1 = new OrderTrackingMiddleware('mw1');
        $mw2 = new OrderTrackingMiddleware('mw2');

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/test', function() {
                    OrderTrackingMiddleware::$executionOrder[] = 'handler';
                    return Response::plaintext('OK');
                });
            },
            preGlobal: [$mw1, $mw2]
        );

        $request = mockRequest('GET', '/test');
        $kernel->handle($request);

        expect(OrderTrackingMiddleware::$executionOrder)->toBe([
            'mw1-before',
            'mw2-before',
            'handler',
            'mw2-after',
            'mw1-after',
        ]);

        OrderTrackingMiddleware::reset();
    });

    it('allows middleware to short-circuit', function () {
        $shortCircuitMw = new class {
            public function __invoke(Request $req, callable $next): Response {
                // Short-circuit without calling next
                return Response::json(['short_circuited' => true], 403);
            }
        };

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) use ($shortCircuitMw) {
                $r->get('/protected', fn() => Response::json(['secret' => 'data']), [
                    'middleware' => [$shortCircuitMw],
                ]);
            }
        );

        $request = mockRequest('GET', '/protected');
        $response = $kernel->handle($request);

        expect($response)->toHaveStatus(403);

        $body = json_decode((string)$response->getBody(), true);
        expect($body['short_circuited'])->toBeTrue();
    });

    it('handles middleware exceptions', function () {
        $errorMw = new class {
            public function __invoke(Request $req, callable $next): Response {
                throw new \RuntimeException('Middleware error');
            }
        };

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) use ($errorMw) {
                $r->get('/error', fn() => Response::plaintext('OK'), [
                    'middleware' => [$errorMw],
                ]);
            }
        );

        $request = mockRequest('GET', '/error');
        $response = $kernel->handle($request);

        // ErrorHandler should catch and return 500
        expect($response)->toHaveStatus(500);
    });

    it('passes modified request through pipeline', function () {
        $addHeaderMw = new class {
            public function __invoke(Request $req, callable $next): Response {
                $req = $req->withAttribute('custom_attr', 'value123');
                return $next($req);
            }
        };

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) use ($addHeaderMw) {
                $r->get('/test', function($req) {
                    return Response::json([
                        'attr' => $req->getAttribute('custom_attr'),
                    ]);
                }, [
                    'middleware' => [$addHeaderMw],
                ]);
            }
        );

        $request = mockRequest('GET', '/test');
        $response = $kernel->handle($request);

        $body = json_decode((string)$response->getBody(), true);
        expect($body['attr'])->toBe('value123');
    });
});
