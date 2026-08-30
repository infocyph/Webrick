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

        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)
            ->toHaveHeader('Access-Control-Allow-Origin', 'https://app.example.com')
            ->toHaveHeader('Access-Control-Allow-Credentials', 'true');
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

        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)
            ->toHaveStatus(204)
            ->toHaveHeader('Access-Control-Allow-Origin')
            ->toHaveHeader('Access-Control-Allow-Methods')
            ->toHaveHeader('Access-Control-Allow-Headers')
            ->toHaveHeader('Access-Control-Max-Age', '600');
    });

    it('rejects unauthorized origins', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            origins: ['https://allowed.com'],
        );

        $request = mockRequest('GET', '/api/users', [
            'Origin' => 'https://evil.com',
        ]);

        $next = fn() => Response::json(['ok' => true]);

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

        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)
            ->toHaveHeader('Access-Control-Allow-Origin', '*')
            ->and($response->hasHeader('Access-Control-Allow-Credentials'))->toBeFalse();
    });

    it('applies security headers', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            hsts: false,
            csp: "default-src 'self'",
        );

        $request = mockRequest('GET', '/');
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)
            ->toHaveHeader('Content-Security-Policy', "default-src 'self'")
            ->toHaveHeader('X-Content-Type-Options', 'nosniff')
            ->toHaveHeader('X-Frame-Options');
    });

    it('adds Client Hints headers', function () {
        $middleware = new CorsAndPoliciesMiddleware(
            acceptCh: ['Sec-CH-UA', 'Sec-CH-UA-Mobile'],
        );

        $request = mockRequest('GET', '/');
        $next = fn() => Response::json(['ok' => true]);

        $response = $middleware($request, $next);

        expect($response)->toHaveHeader('Accept-CH');

        $ach = $response->getHeaderLine('Accept-CH');
        expect($ach)
            ->toContain('Sec-CH-UA')
            ->and($ach)->toContain('Sec-CH-UA-Mobile');
    });
});
