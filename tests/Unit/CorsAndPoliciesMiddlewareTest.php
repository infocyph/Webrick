<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
use Infocyph\Webrick\Response\Response;

describe('CorsAndPoliciesMiddleware', function () {
    it('handles simple CORS request', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            origins: ['https://app.example.com'],
            allowCredentials: true,
        );

        $request = mockRequest('GET', '/api/users', [
            'Origin' => 'https://app.example.com',
        ]);

        $next = fn(): Response => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Access-Control-Allow-Origin'))
            ->toBeTrue()
            ->and($response->hasHeader('Access-Control-Allow-Credentials'))->toBeTrue();
    });

    it('handles preflight request', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            origins: ['https://app.example.com'],
            maxAgeSeconds: 600,
        );

        $request = mockRequest('OPTIONS', '/api/users', [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type',
        ]);

        $next = fn(): Response => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->getStatusCode())
            ->toBe(204)
            ->and($response->hasHeader('Access-Control-Allow-Origin'))->toBeTrue()
            ->and($response->hasHeader('Access-Control-Allow-Methods'))->toBeTrue()
            ->and($response->hasHeader('Access-Control-Allow-Headers'))->toBeTrue()
            ->and($response->hasHeader('Access-Control-Max-Age'))->toBeTrue();
    });

    it('rejects unauthorized origins', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            origins: ['https://allowed.com'],
        );

        $request = mockRequest('GET', '/api/users', [
            'Origin' => 'https://evil.com',
        ]);

        $next = fn(): Response => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Access-Control-Allow-Origin'))->toBeFalse();
    });

    it('handles wildcard origins without credentials', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            origins: ['*'],
            allowCredentials: false,
        );

        $request = mockRequest('GET', '/api/users', [
            'Origin' => 'https://any.com',
        ]);

        $next = fn(): Response => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Access-Control-Allow-Origin'))
            ->toBeTrue()
            ->and($response->hasHeader('Access-Control-Allow-Credentials'))->toBeFalse();
    });

    it('applies security headers', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            hsts: false,
            csp: "default-src 'self'",
        );

        $request = mockRequest('GET', '/');
        $next = fn(): Response => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Content-Security-Policy'))
            ->toBeTrue()
            ->and($response->hasHeader('X-Content-Type-Options'))->toBeTrue()
            ->and($response->hasHeader('X-Frame-Options'))->toBeTrue();
    });

    it('adds Client Hints headers', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            acceptCh: ['Sec-CH-UA', 'Sec-CH-UA-Mobile'],
        );

        $request = mockRequest('GET', '/');
        $next = fn(): Response => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response->hasHeader('Accept-CH'))->toBeTrue();

        $ach = $response->getHeaderLine('Accept-CH');
        expect($ach)
            ->toContain('Sec-CH-UA')
            ->and($ach)->toContain('Sec-CH-UA-Mobile');
    });
});
