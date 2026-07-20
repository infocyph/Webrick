<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Response\Response;

describe('CacheValidatorsMiddleware', function () {
    it('returns not modified when a generated dynamic validator matches', function () {
        $middleware = new CacheValidatorsMiddleware(autoEtagMinSize: 1);
        $body = str_repeat('a', 32);
        $etag = '"' . hash('xxh128', $body) . '"';
        $called = false;

        $response = $middleware(
            mockRequest('GET', '/dynamic', ['If-None-Match' => $etag]),
            static function () use (&$called, $body): Response {
                $called = true;

                return Response::create($body);
            },
        );

        expect($called)->toBeTrue()
            ->and($response->getStatusCode())->toBe(304)
            ->and($response->getHeaderLine('ETag'))->toBe($etag)
            ->and((string) $response->getBody())->toBe('');
    });

    it('does not reuse synthetic validators across dynamic representations', function () {
        $middleware = new CacheValidatorsMiddleware(autoEtagMinSize: 1);
        $firstBody = str_repeat('a', 32);
        $secondBody = str_repeat('b', 32);

        $first = $middleware(
            mockRequest('GET', '/dynamic'),
            static fn () => Response::create($firstBody),
        );
        $firstTag = $first->getHeaderLine('ETag');

        $called = false;
        $second = $middleware(
            mockRequest('GET', '/dynamic', ['If-None-Match' => $firstTag]),
            static function () use (&$called, $secondBody): Response {
                $called = true;

                return Response::create($secondBody);
            },
        );

        expect($firstTag)
            ->toBe('"' . hash('xxh128', $firstBody) . '"')
            ->and($called)->toBeTrue()
            ->and($second->getStatusCode())->toBe(200)
            ->and($second->getHeaderLine('ETag'))->toBe('"' . hash('xxh128', $secondBody) . '"');
    });
});
