<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Request\Request;

test('resource streams retain the externally consumed read and write contract', function (): void {
    $stream = new Stream('');
    expect($stream->isWritable())->toBeTrue()
        ->and($stream->write('payload'))->toBe(7);
    $stream->rewind();
    expect($stream->read(7))->toBe('payload');
    $stream->close();
    expect($stream->isWritable())->toBeFalse();
});

test('the public negotiator preserves explicit q-zero exclusions', function (): void {
    $headers = new RequestHeaders(Request::fake(headers: [
        'Accept-Encoding' => 'gzip;q=0, br;q=1',
        'Accept-Language' => 'en;q=0, fr;q=1',
    ]));
    $negotiator = new ContentNegotiator($headers);
    expect($negotiator->supportsEncoding('gzip'))->toBeFalse()
        ->and($negotiator->supportsEncoding('br'))->toBeTrue()
        ->and($negotiator->supportsLanguage('en'))->toBeFalse()
        ->and($negotiator->supportsLanguage('fr'))->toBeTrue();
});

test('the public XML accessor falls back to form data for non-XML content', function (): void {
    $request = Request::fake(post: ['value' => 'form']);
    expect($request->parsedXml('value'))->toBe('form');
});
