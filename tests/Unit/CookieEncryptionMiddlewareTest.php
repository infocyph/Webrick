<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Request\Request;

describe('CookieEncryptionMiddleware', function () {
    beforeEach(function () {
        $this->key = random_bytes(32);
        $this->middleware = new CookieEncryptionMiddleware(
            keyOrKeys: $this->key,
            cookiePrefix: 'enc_'
        );
    });

    it('encrypts outbound cookies', function () {
        $request = mockRequest('GET', '/');

        $next = function ($req) {
            $jar = new CookieJar();
            $jar = $jar->add(Cookie::make('enc_session', 'secret_value'));
            return $jar->apply(Response::create('test'));
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
        $next1 = function ($req) {
            $jar = new CookieJar();
            $jar = $jar->add(Cookie::make('enc_session', 'my_secret'));
            return $jar->apply(Response::create('test'));
        };
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
            $jar = new CookieJar();
            $jar = $jar->add(Cookie::make('normal_session', 'plain_value'));
            return $jar->apply(Response::create('test'));
        };

        $response = ($this->middleware)($request, $next);

        $setCookie = $response->getHeader('Set-Cookie')[0];

        // Should NOT be encrypted
        expect($setCookie)->toContain('normal_session=plain_value');
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
        $next = function ($req) {
            $jar = new CookieJar();
            $jar = $jar->add(Cookie::make('enc_session', 'value'));
            return $jar->apply(Response::create('test'));
        };

        $response = $middleware($request, $next);

        $setCookie = $response->getHeader('Set-Cookie')[0];

        expect($setCookie)->toContain('Secure');
        expect($setCookie)->toContain('HttpOnly');
        // Check that SameSite is set (Strict or Lax)
        expect($setCookie)->toMatch('/SameSite=(Strict|Lax)/');
    });
});