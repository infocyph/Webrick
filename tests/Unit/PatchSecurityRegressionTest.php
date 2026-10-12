<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Interop\Psr7\PsrBridge;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Core\UriServerParams;
use Infocyph\Webrick\Request\Core\StringBody;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cache\CachePolicy;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Emitter\BaseEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Runtime\Http\ResponseWriterSupport;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\VaryContext;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

test('invalid encryption and signing configuration redacts secrets from exception traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        foreach ([
            static fn() => new CookieEncryptionMiddleware('test-secret-invalid-length'),
            static fn() => new SignedUrlConfig(generationKey: 'test-secret-signing', algorithm: 'unsupported'),
            static fn() => UrlGenerator::makeSignature('payload', 'test-secret-signing', 'unsupported'),
        ] as $operation) {
            try {
                $operation();
                throw new RuntimeException('Expected configuration validation to fail.');
            } catch (InvalidArgumentException|ValueError $exception) {
                expect($exception->getTraceAsString())->not->toContain('test-secret-');
                expect(array_any($exception->getTrace(), static fn(array $frame): bool =>
                    array_any($frame['args'] ?? [], static fn(mixed $argument): bool => $argument instanceof SensitiveParameterValue)
                ))->toBeTrue();
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

test('untrusted Front-End-Https cannot bypass HTTPS enforcement', function (): void {
    $server = [
        'HTTP_HOST' => 'example.test',
        'REQUEST_URI' => '/secure',
        'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_FRONT_END_HTTPS' => 'on',
    ];
    $request = new Request('GET', Uri::fromServerParams($server), $server);
    $middleware = new GatewayHardeningMiddleware(trustedHosts: ['example.test']);
    $response = $middleware($request, static fn(): Response => Response::create('handler'));

    expect($request->getUri()->getScheme())->toBe('http')
        ->and($response->getStatusCode())->toBe(308)
        ->and($response->getHeaderLine('Location'))->toBe('https://example.test/secure');
});

test('Forwarded protocol requires a complete unambiguous parameter', function (string $header): void {
    expect(UriServerParams::detectScheme([
        'HTTP_FORWARDED' => $header,
        'SERVER_PORT' => '80',
    ], Request::HEADER_FORWARDED))->toBe('http');
})->with(['proto=httpsjunk', 'proto="https"junk', 'proto="https', 'proto=https;proto=http', 'proto=https,']);

test('Forwarded host rejects quoted value suffixes and duplicate parameters', function (string $header): void {
    expect(UriServerParams::detectHostPort([
        'HTTP_FORWARDED' => $header,
        'HTTP_HOST' => 'origin.test',
    ], Request::HEADER_FORWARDED)[0])->toBe('origin.test');
})->with(['host="trusted.test"junk', 'host=trusted.test;host=evil.test']);

test('CORS marks every active origin variant for shared caches', function (array $headers): void {
    $middleware = new CorsMiddleware(origins: ['https://allowed.test']);
    $response = $middleware(
        Request::fake(headers: $headers),
        static fn(): Response => Response::create('public')->withHeader('Vary', 'Accept'),
    );

    expect($response->getHeaderLine('Vary'))->toContain('Origin')->toContain('Accept');
})->with([[[]], [['Origin' => 'https://denied.test']], [['Origin' => 'null']], [['Origin' => 'https://allowed.test']]]);

test('response cache preserves conditional evaluation downstream', function (string $header): void {
    $policy = new CachePolicy();

    expect($policy->lookupAllowed(Request::fake(headers: [$header => '"version"'])))->toBeFalse();
})->with(['If-Match', 'If-None-Match', 'If-Modified-Since', 'If-Unmodified-Since']);

test('response cache rejects invalid freshness directives', function (string $line): void {
    $policy = new CachePolicy();

    expect($policy->storeTtl(Request::fake(), Response::create('body')->withHeader('Cache-Control', $line), 30))->toBe(0);
})->with(['max-age=invalid', 's-maxage=invalid, max-age=60', 'max-age', 'max-age=0, max-age=60', 'extension="unterminated, no-store']);

test('response cache subtracts existing response age from freshness', function (): void {
    $policy = new CachePolicy();
    $response = Response::create('body', headers: ['Cache-Control' => 'max-age=60', 'Age' => '55']);

    expect($policy->storeTtl(Request::fake(), $response, 30))->toBe(5)
        ->and($policy->storeTtl(Request::fake(), $response->withHeader('Age', '60'), 30))->toBe(0);
});

test('response cache treats incomplete payloads as misses', function (): void {
    $item = $this->createMock(CacheItemInterface::class);
    $item->method('isHit')->willReturn(true);
    $item->method('get')->willReturn(['s' => 200]);
    $pool = $this->createMock(CacheItemPoolInterface::class);
    $pool->method('getItem')->willReturn($item);
    $middleware = new ResponseCacheMiddleware($pool);
    $response = $middleware(Request::fake(), static fn(): Response => Response::create('fresh', headers: ['Cache-Control' => 'no-store']));

    expect((string) $response->getBody())->toBe('fresh');
});

test('multiplexed SAPI responses exclude connection-specific fields', function (string $protocol): void {
    $emitter = new class extends BaseEmitter {
        public array $headers = [];

        public string $protocol;

        protected function finish(): void {}

        protected function headersAlreadySent(): bool { return false; }

        protected function removePoweredByHeader(): void {}

        protected function sendRawHeader(string $name, string $value): void { $this->headers[$name] = $value; }

        protected function setStatusCode(int $code): void {}

        protected function serverProtocol(): string { return $this->protocol; }

        protected function write(string $chunk): void {}
    };
    $emitter->protocol = $protocol;
    $emitter->emit(Response::create('body', headers: ['Connection' => 'keep-alive, X-Hop', 'X-Hop' => 'private', 'TE' => 'trailers', 'Transfer-Encoding' => 'chunked', 'X-Valid' => 'yes']), Request::fake());

    expect($emitter->headers)->not->toHaveKey('Connection')->not->toHaveKey('Transfer-Encoding')
        ->not->toHaveKey('X-Hop')->not->toHaveKey('Te')
        ->and($emitter->headers['X-Valid'])->toBe('yes');
})->with(['HTTP/2', 'HTTP/3']);

test('cookie serialization rejects invalid names after public mutation', function (): void {
    $cookie = Cookie::make('valid');
    $cookie->name = "injected\r\nSet-Cookie: second";

    expect(fn(): string => (string) $cookie)->toThrow(InvalidArgumentException::class);
});

test('Forwarded authority parsing preserves valid quoted IPv6 and case insensitive protocols', function (): void {
    $server = ['HTTP_FORWARDED' => 'for="[2001:db8::1]";host="[2001:db8::2]:8443";proto="HTTPS"'];

    expect(UriServerParams::detectScheme($server, Request::HEADER_FORWARDED))->toBe('https')
        ->and(UriServerParams::detectHostPort($server, Request::HEADER_FORWARDED))->toBe(['[2001:db8::2]', 8443]);
});

test('cached responses include resident time in Age', function (): void {
    $item = $this->createMock(CacheItemInterface::class);
    $item->method('isHit')->willReturn(true);
    $item->method('get')->willReturn([
        's' => 200, 'h' => ['Age' => ['7']], 'b' => 'cached', 'pv' => '1.1', 'rp' => 'OK', 't' => time() - 5,
    ]);
    $pool = $this->createMock(CacheItemPoolInterface::class);
    $pool->method('getItem')->willReturn($item);
    $middleware = new ResponseCacheMiddleware($pool);
    $response = $middleware(Request::fake(), static fn(): Response => Response::create('unexpected'));

    expect((string) $response->getBody())->toBe('cached')
        ->and((int) $response->getHeaderLine('Age'))->toBeGreaterThanOrEqual(12);
});

test('cache freshness respects Date and Expires without reviving stale representations', function (): void {
    $policy = new CachePolicy();
    $response = Response::create('body', headers: [
        'Date' => gmdate('D, d M Y H:i:s', time() - 90) . ' GMT',
        'Expires' => gmdate('D, d M Y H:i:s', time() - 30) . ' GMT',
    ]);

    expect($policy->storeTtl(Request::fake(), $response, 30))->toBe(0)
        ->and($policy->storeTtl(Request::fake(), $response->withHeader('Cache-Control', 'max-age=60'), 30))->toBe(0);
});

test('absolute signature verification cannot degrade to a relative signature', function (): void {
    $key = 'patch-signature-secret';
    $signature = UrlGenerator::makeSignature('/download', $key);
    $request = new Request('GET', '/download?_sig=' . $signature);
    $middleware = new VerifySignedUrlMiddleware(new SignedUrlConfig(verificationKeys: [$key], payloadMode: SignedUrlConfig::MODE_ABSOLUTE));

    expect(fn(): Response => $middleware($request, static fn(): Response => Response::create('authorized')))
        ->toThrow(\Infocyph\Webrick\Exceptions\HttpException::class, 'Absolute signed URLs require a request scheme and host.');
});

test('PSR bridge preserves an explicitly supplied request target', function (): void {
    $streams = $this->createMock(StreamFactoryInterface::class);
    $streams->method('createStream')->willReturn($this->createMock(StreamInterface::class));
    $target = $this->createMock(ServerRequestInterface::class);
    foreach (['withProtocolVersion', 'withQueryParams', 'withCookieParams', 'withParsedBody', 'withBody'] as $method) {
        $target->method($method)->willReturnSelf();
    }
    $target->expects($this->once())->method('withRequestTarget')->with('*')->willReturnSelf();
    $factory = $this->createMock(ServerRequestFactoryInterface::class);
    $factory->method('createServerRequest')->willReturn($target);

    expect(new PsrBridge($streams)->serverRequest(Request::fake(method: 'OPTIONS')->withRequestTarget('*'), $factory))->toBe($target);
});

test('PSR bridge enforces the native streaming chunk contract', function (): void {
    $target = $this->createMock(ResponseInterface::class);
    $target->method('withProtocolVersion')->willReturnSelf();
    $factory = $this->createMock(ResponseFactoryInterface::class);
    $factory->method('createResponse')->willReturn($target);
    $bridge = new PsrBridge($this->createMock(StreamFactoryInterface::class));
    $source = Response::stream(static function (): iterable { yield 123; });

    expect(fn(): ResponseInterface => $bridge->response($source, $factory))->toThrow(UnexpectedValueException::class);
});

test('native response filtering preserves HTTP1 and strips HTTP2 and HTTP3 connection fields', function (): void {
    $response = Response::create('body', headers: ['Connection' => 'X-Hop', 'X-Hop' => 'private', 'TE' => 'trailers', 'X-Valid' => 'yes']);

    expect(ResponseWriterSupport::headerMap($response, false))->toHaveKey('X-Hop')->toHaveKey('Te')
        ->and(ResponseWriterSupport::headerMap($response, true))->toBe(['X-Valid' => ['yes']]);
});

test('unsigned HTTP decimals accept leading zeroes without admitting overflow', function (): void {
    expect(HttpUtils::parseUnsignedDecimal('0004'))->toBe(4)
        ->and(HttpUtils::parseUnsignedDecimal('0000'))->toBe(0)
        ->and(HttpUtils::parseUnsignedDecimal('00' . PHP_INT_MAX))->toBe(PHP_INT_MAX)
        ->and(HttpUtils::parseUnsignedDecimal(PHP_INT_MAX . '0'))->toBeNull()
        ->and(HttpUtils::parseUnsignedDecimal('+4'))->toBeNull();
    $request = new Request('POST', '/', headers: ['Content-Length' => '0004'], body: new StringBody('body'));
    $middleware = new RequestLimitsMiddleware(maxBodyBytes: 8);

    expect($middleware($request, static fn(): Response => Response::create('accepted'))->getStatusCode())->toBe(200);
});

test('late Vary requirements cannot store a tenant response under an unvaried key', function (bool $responseVary): void {
    $middleware = new ResponseCacheMiddleware(\Infocyph\CacheLayer\Cache\Cache::memory('patch-late-vary-' . (int) $responseVary));
    $next = static function (Request $request) use ($responseVary): Response {
        $request->getAttribute(VaryContext::ATTRIBUTE)->add('X-Tenant');
        $response = Response::create($request->getHeaderLine('X-Tenant'));

        return $responseVary ? $response->withHeader('Vary', 'X-Tenant') : $response;
    };
    $first = Request::fake(headers: ['X-Tenant' => 'tenant-one'])->withAttribute(VaryContext::ATTRIBUTE, new VaryContext());
    $second = Request::fake(headers: ['X-Tenant' => 'tenant-two'])->withAttribute(VaryContext::ATTRIBUTE, new VaryContext());

    expect((string) $middleware($first, $next)->getBody())->toBe('tenant-one')
        ->and((string) $middleware($second, $next)->getBody())->toBe('tenant-two');
})->with([true, false]);
