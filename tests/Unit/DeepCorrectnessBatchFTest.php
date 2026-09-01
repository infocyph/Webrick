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
use Infocyph\Webrick\Request\NativeServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\PayloadParseState;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Runtime\Http\ResponseWriterSupport;
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
