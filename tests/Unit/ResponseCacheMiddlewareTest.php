<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

test('response cache fails open when its backend cannot read', function (): void {
    $store = $this->createMock(CacheInterface::class);
    $store->expects($this->once())
        ->method('get')
        ->willThrowException(new RuntimeException('cache read unavailable'));
    $middleware = new ResponseCacheMiddleware($store);
    $calls = 0;

    $response = $middleware(
        Request::fake(uri: 'http://localhost/cache-read'),
        static function () use (&$calls): Response {
            $calls++;

            return Response::json(['ok' => true]);
        },
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($calls)->toBe(1);
});

test('response cache preserves the response when its backend cannot write', function (): void {
    $store = $this->createMock(CacheInterface::class);
    $store->expects($this->once())->method('get')->willReturn(null);
    $store->expects($this->once())
        ->method('set')
        ->willThrowException(new RuntimeException('cache write unavailable'));
    $middleware = new ResponseCacheMiddleware($store);

    $response = $middleware(
        Request::fake(uri: 'http://localhost/cache-write'),
        static fn(): Response => Response::json(['ok' => true]),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('{"ok":true}');
});

test('response cache serves a CacheLayer 3.1 hit without invoking the handler again', function (): void {
    $middleware = new ResponseCacheMiddleware(Cache::memory('webrick-response-hit'));
    $request = Request::fake(uri: 'http://localhost/cache-hit');
    $calls = 0;
    $next = static function () use (&$calls): Response {
        $calls++;

        return Response::json(['call' => $calls]);
    };

    $first = $middleware($request, $next);
    $second = $middleware($request, $next);

    expect((string) $first->getBody())->toBe('{"call":1}')
        ->and((string) $second->getBody())->toBe('{"call":1}')
        ->and($calls)->toBe(1);
});

test('response cache never shares requests carrying credentials or cookies', function (string $header): void {
    $middleware = new ResponseCacheMiddleware(Cache::memory('webrick-private-' . strtolower($header)));
    $request = Request::fake(uri: 'http://localhost/private')->withHeader($header, 'secret');
    $calls = 0;
    $next = static function () use (&$calls): Response {
        return Response::json(['call' => ++$calls]);
    };

    $middleware($request, $next);
    $middleware($request, $next);

    expect($calls)->toBe(2);
})->with(['Authorization', 'Cookie']);

test('response cache rejects unsafe response privacy signals', function (Response $response): void {
    $middleware = new ResponseCacheMiddleware(Cache::memory('webrick-response-privacy-' . bin2hex(random_bytes(4))));
    $request = Request::fake(uri: 'http://localhost/privacy');
    $calls = 0;
    $next = static function () use (&$calls, $response): Response {
        ++$calls;

        return $response;
    };

    $middleware($request, $next);
    $middleware($request, $next);

    expect($calls)->toBe(2);
})->with([
    'private' => Response::json(['ok' => true])->withHeader('Cache-Control', 'private'),
    'no-store' => Response::json(['ok' => true])->withHeader('Cache-Control', 'no-store'),
    'set-cookie' => Response::json(['ok' => true])->withAddedHeader('Set-Cookie', 'session=secret'),
    'vary authorization' => Response::json(['ok' => true])->withHeader('Vary', 'Authorization'),
    'vary cookie' => Response::json(['ok' => true])->withHeader('Vary', 'Cookie'),
    'unkeyed vary' => Response::json(['ok' => true])->withHeader('Vary', 'X-Tenant'),
    'vary wildcard' => Response::json(['ok' => true])->withHeader('Vary', '*'),
]);

test('response cache canonicalizes query order and isolates host port and negotiated variants', function (): void {
    $middleware = new ResponseCacheMiddleware(Cache::memory('webrick-response-variants'));
    $calls = 0;
    $next = static function () use (&$calls): Response {
        return Response::json(['call' => ++$calls]);
    };

    $first = $middleware(Request::fake(uri: 'https://example.test/items?b=2&a=1'), $next);
    $sameQuery = $middleware(Request::fake(uri: 'https://example.test/items?a=1&b=2'), $next);
    $otherPort = $middleware(Request::fake(uri: 'https://example.test:8443/items?a=1&b=2'), $next);
    $otherQuery = $middleware(Request::fake(uri: 'https://example.test/items?a=2&b=2'), $next);
    $otherAccept = $middleware(
        Request::fake(uri: 'https://example.test/items?a=1&b=2')->withHeader('Accept', 'text/plain'),
        $next,
    );

    expect((string) $first->getBody())->toBe('{"call":1}')
        ->and((string) $sameQuery->getBody())->toBe('{"call":1}')
        ->and((string) $otherPort->getBody())->toBe('{"call":2}')
        ->and((string) $otherQuery->getBody())->toBe('{"call":3}')
        ->and((string) $otherAccept->getBody())->toBe('{"call":4}')
        ->and($calls)->toBe(4);
});

test('response cache keys are versioned and valid for CacheLayer 3.1', function (): void {
    $store = $this->createMock(CacheInterface::class);
    $store->expects($this->once())
        ->method('get')
        ->with($this->callback(static fn(string $key): bool => str_starts_with($key, 'webrick.hr.v1.')
            && strlen($key) <= 64))
        ->willReturn(null);
    $store->expects($this->once())->method('set')->willReturn(true);

    $middleware = new ResponseCacheMiddleware($store);
    $middleware(
        Request::fake(uri: 'https://example.test/cache-key'),
        static fn(): Response => Response::json(['ok' => true]),
    );
});

test('response cache keeps explicit HEAD and GET representations isolated', function (): void {
    $middleware = new ResponseCacheMiddleware(Cache::memory('webrick-head-get'));
    $calls = 0;
    $next = static function (Request $request) use (&$calls): Response {
        ++$calls;

        return Response::create($request->getMethod() === 'HEAD' ? 'head-handler' : 'get-handler')
            ->withHeader('X-Handler-Method', $request->getMethod());
    };

    $get = $middleware(Request::fake(method: 'GET', uri: 'https://example.test/resource'), $next);
    $head = $middleware(Request::fake(method: 'HEAD', uri: 'https://example.test/resource'), $next);
    $getHit = $middleware(Request::fake(method: 'GET', uri: 'https://example.test/resource'), $next);

    expect($get->getHeaderLine('X-Handler-Method'))->toBe('GET')
        ->and($head->getHeaderLine('X-Handler-Method'))->toBe('HEAD')
        ->and($getHit->getHeaderLine('X-Handler-Method'))->toBe('GET')
        ->and($calls)->toBe(2);
});
