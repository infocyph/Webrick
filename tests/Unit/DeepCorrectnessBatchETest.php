<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
use Infocyph\Webrick\Response\Headers\HeaderPolicy;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\ResponseWriterSupport;
use Infocyph\Webrick\Runtime\Http\TransportRequestFactory;
use Infocyph\Webrick\Support\InputSanitizer;

it('keeps HTTP method body semantics and rejects malformed method tokens', function () {
    expect(HttpMethodEnum::DELETE->allowsBody())->toBeTrue()
        ->and(fn() => HttpMethodEnum::normalize('BAD METHOD'))
        ->toThrow(InvalidArgumentException::class);
});

it('respects explicit Accept exclusions when detecting JSON expectations', function () {
    $request = Request::fake(headers: ['Accept' => 'application/json;q=0, text/plain;q=1']);

    expect($request->expectsJson())->toBeFalse();
});

it('rejects malformed explicit request targets', function () {
    expect(fn() => Request::fake()->withRequestTarget("/ok\r\nX-Test: injected"))
        ->toThrow(InvalidArgumentException::class);
});

it('treats a zero sanitizer byte limit as a real zero-byte limit', function () {
    $sanitizer = new InputSanitizer(maxBytes: 0);

    expect($sanitizer->sanitizeString('payload'))->toBe('');
});

it('rejects invalid sanitizer regex configuration at bootstrap', function () {
    expect(fn() => new InputSanitizer(skipKeyPatterns: ['/[broken/']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects malformed merged HTTP method values', function () {
    expect(fn() => HeaderPolicy::mergeCsv('Allow', 'GET', 'BAD METHOD'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects negative maintenance retry intervals', function () {
    expect(fn() => new MaintenanceModeMiddleware(retryAfter: -1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects undefined PHP upload error codes', function () {
    expect(fn() => new UploadedFile('', error: 5))
        ->toThrow(InvalidArgumentException::class);
});

it('derives transport query parameters from REQUEST_URI when omitted', function () {
    $request = TransportRequestFactory::fromParts(
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/items?foo=bar'],
        [],
    );

    expect($request->getQueryParams())->toBe(['foo' => 'bar']);
});

it('rejects invalid runtime response chunk sizes', function () {
    expect(fn() => iterator_to_array(ResponseWriterSupport::chunks(Response::create('body'), 0)))
        ->toThrow(InvalidArgumentException::class);
});

it('requires streaming producers to yield strings', function () {
    $response = Response::stream([1]);

    expect(fn() => iterator_to_array(ResponseWriterSupport::chunks($response)))
        ->toThrow(UnexpectedValueException::class);
});

it('rejects unknown SAPI finish modes', function () {
    expect(fn() => new DefaultEmitter('unknown'))
        ->toThrow(InvalidArgumentException::class);
});
