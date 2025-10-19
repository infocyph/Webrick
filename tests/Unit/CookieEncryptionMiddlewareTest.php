<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Response\Response;

describe('CookieEncryptionMiddleware', function () {
    beforeEach(function () {
        $this->key = testEncryptionKey();
        $this->middleware = new CookieEncryptionMiddleware(
            keyOrKeys: $this->key,
            cookiePrefix: 'enc_'
        );
    });

    it('encrypts outbound cookies', function () {
        $request = mockRequest('GET', '/');

        $next = function ($req) {
            return Response::create('test')->withCookie('enc_session', 'secret_value');
        };

        $response = ($this->middleware)($request, $next);

        $setCookie = $response->getHeader('Set-Cookie');
        expect($setCookie)->toHaveCount(1);

        // Cookie value should be encrypted (not plain text)
        expect($setCookie[0])->not->toContain('secret_value');
        expect($setCookie[0])->toContain('enc_session=');
    });

    it('decrypts inbound cookies', function () {
        // First, encrypt a cookie
        $request1 = mockRequest('GET', '/');
        $next1 = fn($req) => Response::create('test')->withCookie('enc_session', 'my_secret');
        $response1 = ($this->middleware)($request1, $next1);

        // Extract encrypted cookie value
        $setCookie = $response1->getHeader('Set-Cookie')[0];
        preg_match('/enc_session=([^;]+)/', $setCookie, $matches);
        $encryptedValue = urldecode($matches[1]);

        // Now test decryption
        $_COOKIE = ['enc_session' => $encryptedValue];
        $request2 = Request::fromGlobals();

        $decrypted = null;
        $next2 = function ($req) use (&$decrypted) {
            $decrypted = $req->cookie('enc_session');
            return Response::create('ok');
        };

        ($this->middleware)($request2, $next2);

        expect($decrypted)->toBe('my_secret');
    });

    it('ignores non-prefixed cookies', function () {
        $request = mockRequest('GET', '/');

        $next = function ($req) {
            return Response::create('test')->withCookie('normal_session', 'plain_value');
        };

        $response = ($this->middleware)($request, $next);

        $setCookie = $response->getHeader('Set-Cookie')[0];

        // Should NOT be encrypted
        expect($setCookie)->toContain('normal_session=plain_value');
    });

    it('handles large cookies with chunking', function () {
        $middleware = new CookieEncryptionMiddleware(
            keyOrKeys: $this->key,
            cookiePrefix: 'enc_',
            maxBytes: 100  // Force chunking
        );

        $request = mockRequest('GET', '/');
        $largeValue = str_repeat('x', 500);

        $next = fn($req) => Response::create('test')->withCookie('enc_data', $largeValue);

        $response = $middleware($request, $next);

        $setCookie = $response->getHeader('Set-Cookie');

        // Should create multiple cookie parts
        expect(count($setCookie))->toBeGreaterThan(1);
        expect($setCookie[0])->toContain('enc_data=');
        expect($setCookie[1])->toContain('enc_data.p2=');
    });

    it('enforces security attributes', function () {
        $middleware = new CookieEncryptionMiddleware(
            keyOrKeys: $this->key,
            cookiePrefix: 'enc_',
            forceSecure: true,
            forceHttpOnly: true,
            defaultSameSite: 'Strict'
        );

        $request = mockRequest('GET', '/');
        $next = fn($req) => Response::create('test')->withCookie('enc_session', 'value');

        $response = $middleware($request, $next);

        $setCookie = $response->getHeader('Set-Cookie')[0];

        expect($setCookie)->toContain('Secure');
        expect($setCookie)->toContain('HttpOnly');
        expect($setCookie)->toContain('SameSite=Strict');
    });
});
