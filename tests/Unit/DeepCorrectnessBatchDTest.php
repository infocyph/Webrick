<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Response\Cache\CachePolicy;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Headers\HeaderPolicy;
use Infocyph\Webrick\Response\Range\ByteRangeStream;

it('rejects malformed protocol versions and method tokens', function () {
    $request = Request::fake();

    expect(fn() => $request->withProtocolVersion("1.1\r\nX-Test: injected"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => HttpMethodEnum::normalize("GET\r\nX-Test"))
        ->toThrow(InvalidArgumentException::class);
});

it('does not create phantom headers from empty added-value lists', function () {
    $bag = new HeaderBag();
    $same = $bag->withAdded('X-Test', []);

    expect($same)->toBe($bag)
        ->and($same->has('X-Test'))->toBeFalse();
});

it('validates smart-header field-name tokens', function () {
    expect(fn() => HeaderPolicy::mergeCsv('Vary', 'Accept', 'bad token'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => HeaderPolicy::register('bad header', HeaderPolicy::SINGLE))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects byte-range windows beyond a known base stream', function () {
    $base = new Stream('0123456789');

    expect(fn() => new ByteRangeStream($base, 8, 4))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps absolute cookie expiry independent from Max-Age', function () {
    $cookie = Cookie::make('session')->expires(new DateTimeImmutable('@2000000000'));
    $line = (string) $cookie;

    expect($line)->toContain('Expires=')
        ->and($line)->not->toContain('Max-Age=');
});

it('preserves quoted commas while parsing cache-control directives', function () {
    $directives = CachePolicy::directives('private="Set-Cookie, X-Token", max-age=60');

    expect($directives['private'])->toBe('Set-Cookie, X-Token')
        ->and($directives['max-age'])->toBe('60');
});
