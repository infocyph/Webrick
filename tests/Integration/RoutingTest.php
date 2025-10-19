<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

describe('Routing Integration', function () {
    it('matches static routes', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/about', fn() => Response::plaintext('About Page'), 'about');
            }
        );

        $request = mockRequest('GET', '/about');
        $response = $kernel->handle($request);

        expect($response)
            ->toHaveStatus(200)
            ->and((string)$response->getBody())->toBe('About Page');
    });

    it('matches dynamic routes with parameters', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/users/{id}', function ($req, $id) {
                    return Response::json(['user_id' => $id]);
                });
            }
        );

        $request = mockRequest('GET', '/users/42');
        $response = $kernel->handle($request);

        expect($response)
            ->toHaveStatus(200)
            ->toHaveJsonBody(['user_id' => '42']);
    });

    it('returns 404 for unknown routes', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/exists', fn() => Response::plaintext('OK'));
            }
        );

        $request = mockRequest('GET', '/not-found');
        $response = $kernel->handle($request);

        expect($response)->toHaveStatus(404);
    });

    it('returns 405 for wrong method', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/users', fn() => Response::json([]));
            }
        );

        $request = mockRequest('POST', '/users');
        $response = $kernel->handle($request);

        expect($response)
            ->toHaveStatus(405)
            ->toHaveHeader('Allow');
    });

    it('handles HEAD requests', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/test', fn() => Response::plaintext('Body content'));
            }
        );

        $request = mockRequest('HEAD', '/test');
        $response = $kernel->handle($request);

        expect($response)->toHaveStatus(200);
        expect((string)$response->getBody())->toBe('');
    });

    it('validates route constraints', function () {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                $r->get('/posts/{id:int}', function ($req, $id) {
                    return Response::json(['post_id' => (int)$id]);
                });
            }
        );

        // Valid integer
        $validRequest = mockRequest('GET', '/posts/123');
        $validResponse = $kernel->handle($validRequest);
        expect($validResponse)->toHaveStatus(200);

        // Invalid (non-integer)
        $invalidRequest = mockRequest('GET', '/posts/abc');
        $invalidResponse = $kernel->handle($invalidRequest);
        expect($invalidResponse)->toHaveStatus(404);
    });
});
