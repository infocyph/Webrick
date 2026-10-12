<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Exceptions\HttpExceptionInterface;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Core\StringBody;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Request\NativeServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\PayloadParseState;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Runtime\Http\ResponseWriterSupport;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Support\HttpUtils;

it('validates response protocol versions and custom reason phrases', function () {
    expect(fn () => new Response(200, '', [], '1.x'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Response(200, '', [], '1.1', "OK\rInjected"))
        ->toThrow(InvalidArgumentException::class);
});

it('does not let constrained locale resolution poison unconstrained locale cache', function () {
    $request = Request::fake(headers: ['Accept-Language' => 'zh-Hant-TW, en;q=0.5']);

    expect($request->locale(['en']))->toBe('en')
        ->and($request->locale())->toBe('zh-hant-tw');
});

it('classifies structured json suffixes without accepting json lookalikes', function () {
    $json = new NativeServerRequest(
        'POST',
        '/payload',
        headers: ['Content-Type' => 'application/problem+json'],
        body: new StringBody('{}'),
    );
    $jsonp = new NativeServerRequest(
        'POST',
        '/payload',
        headers: ['Content-Type' => 'application/jsonp'],
        body: new StringBody('{}'),
    );

    expect($json->jsonParseState())->toBe(PayloadParseState::NOT_PARSED)
        ->and($jsonp->jsonParseState())->toBe(PayloadParseState::NOT_APPLICABLE);
});

it('fails closed on malformed quoted accept lists', function () {
    $request = Request::fake(headers: ['Accept' => 'application/json;profile="unterminated']);

    expect($request->headers()->accept('Accept'))->toBe([]);
});

it('fails conditional if-match closed on malformed quoted etag lists', function () {
    $validator = new ConditionalValidator('"current"', representationExists: true);
    $outcome = $validator->evaluate(Request::fake(headers: ['If-Match' => '"current']));

    expect($outcome->state)->toBe(Outcome::FAIL)
        ->and($outcome->http)->toBe(StatusEnum::PRECONDITION_FAILED->value);
});

it('uses one quote-aware HTTP list splitter for malformed quoted syntax', function () {
    expect(HttpUtils::splitQuoted('a, "unterminated', ','))->toBeNull()
        ->and(HttpUtils::splitQuoted('a, "b,c"', ','))->toBe(['a', '"b,c"']);
});

it('preserves optional empty HTTP list elements while rejecting empty proxy hops', function (string $line) {
    expect(HttpUtils::splitQuoted($line, ','))->toBe(['for=8.8.8.8'])
        ->and(HttpUtils::splitQuoted($line, ',', true))->toBeNull();

    $request = new Request('GET', '/', ['REMOTE_ADDR' => '10.0.0.9'], [
        'Forwarded' => $line,
        'X-Forwarded-For' => '1.1.1.1',
    ]);
    $endUser = EndUser::from(
        $request,
        ['10.0.0.0/8'],
        Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR,
    );

    expect($endUser->ip())->toBe('10.0.0.9');
})->with([
    'leading empty hop' => ',for=8.8.8.8',
    'trailing empty hop' => 'for=8.8.8.8, ',
    'consecutive empty hops' => 'for=8.8.8.8,, ',
]);

it('keeps quoted commas and escaped quotes inside a forwarded element', function () {
    $line = 'for=8.8.8.8;extension="a,b\\"c", for=10.0.0.8';
    $request = new Request('GET', '/', ['REMOTE_ADDR' => '10.0.0.9'], ['Forwarded' => $line]);

    expect(EndUser::from($request, ['10.0.0.0/8'], Request::HEADER_FORWARDED)->ip())->toBe('8.8.8.8');
});

it('uses the first HTTP quality parameter and fails invalid quality closed', function (array $parameters, float $expected) {
    expect(HttpUtils::parameterQuality($parameters))->toBe($expected);
})->with([
    'default quality' => [[], 1.0],
    'unrelated parameter' => [['level=1'], 1.0],
    'case and spaces' => [['Q = 0.125'], 0.125],
    'first quality wins' => [['q=0.2', 'q=1'], 0.2],
    'invalid first quality wins' => [['q=2', 'q=1'], 0.0],
    'too many decimal places' => [['q=0.1234'], 0.0],
]);

it('validates framework http exception headers immediately', function () {
    expect(fn () => new HttpException(400, 'bad', ['Bad Header' => 'value']))
        ->toThrow(InvalidArgumentException::class);
});

it('validates error handler wire configuration at bootstrap', function () {
    expect(fn () => new ErrorHandler(requestIdHeader: 'Bad Header'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ErrorHandler(exceptionMap: [RuntimeException::class => 200]))
        ->toThrow(InvalidArgumentException::class);
});

it('degrades invalid third-party http exception statuses to 500', function () {
    $error = new class extends RuntimeException implements HttpExceptionInterface {
        public function getHeaders(): array
        {
            return [];
        }

        public function getPublicMessage(): string
        {
            return 'invalid status';
        }

        public function getStatusCode(): int
        {
            return 200;
        }
    };
    $handler = new ErrorHandler();
    $response = $handler->handle(
        Request::fake(),
        static function () use ($error): Response {
            throw $error;
        },
    );

    expect($response->getStatusCode())->toBe(StatusEnum::INTERNAL_SERVER_ERROR->value);
});

it('honors exception response headers case-insensitively', function () {
    $error = new HttpException(400, 'bad', ['content-type' => 'application/problem+json']);
    $response = (new ErrorHandler())->handle(
        Request::fake(headers: ['Accept' => 'application/json']),
        static function () use ($error): Response {
            throw $error;
        },
    );

    expect($response->getHeaderLine('Content-Type'))->toBe('application/problem+json');
});

it('rejects malformed compression configuration at bootstrap', function () {
    expect(fn () => new CompressionMiddleware(etagMode: 'unknown'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CompressionMiddleware(minBytes: 10, maxBufferBytes: 9))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects any transfer-encoding combined with content-length', function () {
    $middleware = new RequestLimitsMiddleware(maxBodyBytes: 1024);
    $request = Request::fake(
        headers: ['Transfer-Encoding' => 'identity', 'Content-Length' => '1'],
        method: 'POST',
    );

    expect(fn () => $middleware($request, static fn (): Response => Response::create('ok')))
        ->toThrow(HttpException::class);
});

it('keeps stricter application body limits active when the transport advertises request limits', function () {
    $middleware = new RequestLimitsMiddleware(maxBodyBytes: 4);
    $capabilities = new RuntimeCapabilities('runwire', transportRequestLimits: true);
    $request = Request::fake(method: 'POST')
        ->withBody(new StringBody('12345'))
        ->withHeader('Content-Length', '5')
        ->withAttribute(RuntimeCapabilities::ATTRIBUTE, $capabilities);
    $withoutLength = Request::fake(method: 'POST')
        ->withBody(new StringBody('12345'))
        ->withAttribute(RuntimeCapabilities::ATTRIBUTE, $capabilities);

    expect(fn () => $middleware($request, static fn (): Response => Response::create('ok')))
        ->toThrow(HttpException::class, 'Payload exceeds maximum allowed size.')
        ->and(fn () => $middleware($withoutLength, static fn (): Response => Response::create('ok')))
        ->toThrow(HttpException::class, 'Payload exceeds maximum allowed size.');
});

it('keeps stricter application header limits active when the transport advertises request limits', function () {
    $capabilities = new RuntimeCapabilities('runwire', transportRequestLimits: true);
    $countLimited = new RequestLimitsMiddleware(
        maxHeaderBytes: 8192,
        maxHeaderCount: 1,
        maxBodyBytes: 1024,
    );
    $byteLimited = new RequestLimitsMiddleware(
        maxHeaderBytes: 8,
        maxHeaderCount: 100,
        maxBodyBytes: 1024,
    );
    $countRequest = Request::fake(
        method: 'POST',
        headers: ['X-One' => '1', 'X-Two' => '2'],
    )->withAttribute(RuntimeCapabilities::ATTRIBUTE, $capabilities);
    $byteRequest = Request::fake(
        method: 'POST',
        headers: ['X-Test' => 'value'],
    )->withAttribute(RuntimeCapabilities::ATTRIBUTE, $capabilities);

    expect(fn () => $countLimited($countRequest, static fn (): Response => Response::create('ok')))
        ->toThrow(HttpException::class, 'Too many header fields')
        ->and(fn () => $byteLimited($byteRequest, static fn (): Response => Response::create('ok')))
        ->toThrow(HttpException::class, 'Request headers too large');
});

it('rejects malformed wildcard cors origins', function () {
    $middleware = new CorsMiddleware(origins: ['*']);
    $request = Request::fake(headers: ['Origin' => 'not-an-origin']);
    $response = $middleware($request, static fn (): Response => Response::create('ok'));

    expect($response->hasHeader('Access-Control-Allow-Origin'))->toBeFalse();
});

it('turns malformed cors requested methods into controlled http errors', function () {
    $middleware = new CorsMiddleware(origins: ['https://client.example']);
    $request = Request::fake(
        headers: [
            'Origin' => 'https://client.example',
            'Access-Control-Request-Method' => 'BAD METHOD',
        ],
        method: 'OPTIONS',
    );

    expect(fn () => $middleware($request, static fn (): Response => Response::create('ok')))
        ->toThrow(HttpException::class);
});

it('validates response cache configuration before resolving a default store', function () {
    expect(fn () => new ResponseCacheMiddleware(ttlSeconds: -1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ResponseCacheMiddleware(defaultVary: ['Bad Header']))
        ->toThrow(InvalidArgumentException::class);
});

it('validates telemetry header names and nel ttl at bootstrap', function () {
    expect(fn () => new TelemetryMiddleware(requestIdHeader: 'Bad Header'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TelemetryMiddleware(nelTtlSeconds: -1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects zero-progress non-eof response streams instead of truncating them', function () {
    $body = new class implements BodyStream {
        public function __toString(): string
        {
            return '';
        }

        public function close(): void {}

        public function detach(): mixed
        {
            return null;
        }

        public function eof(): bool
        {
            return false;
        }

        public function getContents(): string
        {
            return '';
        }

        public function getMetadata(?string $key = null): mixed
        {
            return $key === null ? [] : null;
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function isWritable(): bool
        {
            return false;
        }

        public function read(int $length): string
        {
            unset($length);

            return '';
        }

        public function rewind(): void {}

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function tell(): int
        {
            return 0;
        }

        public function write(string $string): int
        {
            unset($string);

            return 0;
        }
    };

    expect(fn () => iterator_to_array(ResponseWriterSupport::chunks(new Response(200, $body))))
        ->toThrow(RuntimeException::class);
});

it('rejects overflowing content-length values in the response linter', function () {
    $linter = new ResponseLinterMiddleware(ResponseLinterMiddleware::CONTENT_LENGTH_MATCH);
    $response = new Response(200, 'x', ['Content-Length' => '999999999999999999999999999']);

    expect(fn () => $linter(Request::fake(), static fn (): Response => $response))
        ->toThrow(RuntimeException::class);
});
