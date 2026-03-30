<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Log\NullLogger;

describe('Routing Regressions', function () {
    it('routes POST method-override requests using effective method', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $r): void {
                $r->put('/resource', static fn () => Response::plaintext('updated', 200));
            },
        );

        $request = mockRequest('POST', '/resource', [
            'X-HTTP-Method-Override' => 'PUT',
        ]);

        $response = $kernel->handle($request);

        expect($response)->toHaveStatus(200)
            ->and((string)$response->getBody())->toBe('updated');
    });

    it('exposes route params under compatibility attribute keys', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $r): void {
                $r->get('/lang/{locale}', static fn (): Response => Response::plaintext('ok'), [
                    'middleware' => [
                        static function (Request $request, callable $next): Response {
                            return Response::json([
                                'route_params' => $request->getAttribute('route_params'),
                                'route.params' => $request->getAttribute('route.params'),
                                'params' => $request->getAttribute('params'),
                            ]);
                        },
                    ],
                ]);
            },
        );

        $request = mockRequest('GET', '/lang/en');
        $response = $kernel->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        expect($response)->toHaveStatus(200)
            ->and($body['route_params']['locale'] ?? null)->toBe('en')
            ->and($body['route.params']['locale'] ?? null)->toBe('en')
            ->and($body['params']['locale'] ?? null)->toBe('en');
    });
});
