<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Response;

function encryptedCookieForTest(CookieEncryptionMiddleware $middleware, string $value, string $name = 'enc_session'): string
{
    $response = $middleware(
        Request::fake(),
        static function () use ($name, $value): Response {
            return (new CookieJar())->add(Cookie::make($name, $value))->apply(Response::create('ok'));
        },
    );
    preg_match('/' . preg_quote($name, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches);

    return rawurldecode($matches[1] ?? '');
}

function decryptedCookieForTest(
    CookieEncryptionMiddleware $middleware,
    string $cipher,
    string $name = 'enc_session',
): mixed {
    $decrypted = null;
    $middleware(
        Request::fake()->withCookieParams([$name => $cipher]),
        static function (Request $request) use (&$decrypted, $name): Response {
            $decrypted = $request->cookie($name);

            return Response::create('ok');
        },
    );

    return $decrypted;
}

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

        $next = function () {
            $jar = new CookieJar;
            $jar = $jar->add(Cookie::make('enc_session', 'secret_value'));

            return $jar->apply(Response::create('test'));
        };

        $response = ($this->middleware)($request, $next);

        $setCookie = $response->getHeader('Set-Cookie');
        expect($setCookie)
            ->toHaveCount(1)
            ->and($setCookie[0])->not
            ->toContain('secret_value')
            ->and($setCookie[0])->toContain('enc_session=');

        // Cookie value should be encrypted (not plain text)
    });

    it('decrypts inbound cookies', function () {
        // First, encrypt a cookie
        $request1 = mockRequest('GET', '/');
        $next1 = function () {
            $jar = new CookieJar;
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

        $next = function () {
            $jar = new CookieJar;
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
        $next = function () {
            $jar = new CookieJar;
            $jar = $jar->add(Cookie::make('enc_session', 'value'));

            return $jar->apply(Response::create('test'));
        };

        $response = $middleware($request, $next);

        $setCookie = $response->getHeader('Set-Cookie')[0];

        expect($setCookie)
            ->toContain('Secure')
            ->and($setCookie)->toContain('HttpOnly')
            ->and($setCookie)->toMatch('/SameSite=(Strict|Lax)/');
        // Check that SameSite is set (Strict or Lax)
    });

    it('decrypts active and previous keys during rotation', function () {
        $old = random_bytes(32);
        $current = random_bytes(32);
        $oldWriter = new CookieEncryptionMiddleware([$old, $current]);
        $reader = new CookieEncryptionMiddleware([$old, $current]);
        $currentWriter = new CookieEncryptionMiddleware([$old, $current]);
        $currentWriter = $currentWriter->rotateToKid(1);

        expect(decryptedCookieForTest($reader, encryptedCookieForTest($oldWriter, 'old-value')))->toBe('old-value')
            ->and(decryptedCookieForTest($reader, encryptedCookieForTest($currentWriter, 'current-value')))
            ->toBe('current-value');
    });

    it('rejects modified authenticated frame fields', function (int $offset): void {
        $cipher = encryptedCookieForTest($this->middleware, 'authenticated-value');
        $raw = base64_decode($cipher, true);
        expect($raw)->toBeString()->not->toBe('');
        $raw[$offset] = chr(ord($raw[$offset]) ^ 0x01);

        expect(decryptedCookieForTest($this->middleware, base64_encode($raw)))->toBeNull();
    })->with([
        'key id' => 2,
        'nonce' => 3,
        'authentication tag' => 15,
        'ciphertext' => 31,
    ]);

    it('rejects malformed truncated unknown-key and cross-cookie payloads', function (Closure $mutate): void {
        $cipher = encryptedCookieForTest($this->middleware, 'secret');

        expect(decryptedCookieForTest($this->middleware, $mutate($cipher)))->toBeNull();
    })->with([
        'malformed base64' => static fn(string $cipher): string => '***' . $cipher,
        'truncated frame' => static fn(string $cipher): string => base64_encode(substr((string) base64_decode($cipher, true), 0, 20)),
        'unknown kid' => static function (string $cipher): string {
            $raw = (string) base64_decode($cipher, true);
            $raw[2] = chr(255);

            return base64_encode($raw);
        },
    ]);

    it('binds ciphertext to its cookie name and validates key configuration', function (): void {
        $cipher = encryptedCookieForTest($this->middleware, 'secret', 'enc_session');

        expect(decryptedCookieForTest($this->middleware, $cipher, 'enc_other'))->toBeNull()
            ->and(fn() => new CookieEncryptionMiddleware('short'))->toThrow(InvalidArgumentException::class)
            ->and(fn() => new CookieEncryptionMiddleware([]))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $this->middleware->rotateToKid(99))->toThrow(InvalidArgumentException::class);
    });

    it('rejects oversized incompressible payloads without a backing store', function (): void {
        $middleware = new CookieEncryptionMiddleware($this->key, maxBytes: 256);
        $value = base64_encode(random_bytes(4_096));

        expect(fn() => encryptedCookieForTest($middleware, $value))->toThrow(LengthException::class);
    });

    it('rejects cookie attribute injection and preserves expiry boundaries', function (): void {
        expect(fn() => Cookie::make("enc_session\r\nX-Injected", 'value'))
            ->toThrow(InvalidArgumentException::class);

        $expired = (new CookieJar())->add(
            Cookie::make('enc_expired', 'value')->expires(new DateTimeImmutable('-1 second')),
        )->apply(Response::create('ok'));
        $future = (new CookieJar())->add(
            Cookie::make('enc_future', 'value')->expires(new DateTimeImmutable('+1 hour')),
        )->apply(Response::create('ok'));

        $expiredEncrypted = ($this->middleware)(Request::fake(), static fn(): Response => $expired);
        $futureEncrypted = ($this->middleware)(Request::fake(), static fn(): Response => $future);

        expect($expiredEncrypted->getHeaderLine('Set-Cookie'))->toContain('Max-Age=0')
            ->and($futureEncrypted->getHeaderLine('Set-Cookie'))->toMatch('/Max-Age=3[0-9]{3}/');
    });
});
