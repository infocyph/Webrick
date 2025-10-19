<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Core\Stream;

describe('Response', function () {
    it('creates basic responses', function () {
        $response = Response::create('Hello World', 200);

        expect($response)
            ->toBeResponse()
            ->getStatusCode()->toBe(200)
            ->and((string)$response->getBody())->toBe('Hello World');
    });

    it('creates JSON responses', function () {
        $data = ['name' => 'John', 'age' => 30];
        $response = Response::json($data);

        expect($response)
            ->toHaveStatus(200)
            ->toHaveHeader('Content-Type', 'application/json');

        $decoded = json_decode((string)$response->getBody(), true);
        expect($decoded)->toBe($data);
    });

    it('creates redirect responses', function () {
        $response = Response::redirect('/dashboard', 302);

        expect($response)
            ->toHaveStatus(302)
            ->toHaveHeader('Location', '/dashboard');
    });

    it('creates empty responses', function () {
        $response = Response::empty(204);

        expect($response)
            ->toHaveStatus(204)
            ->and((string)$response->getBody())->toBe('');
    });

    it('creates plaintext responses', function () {
        $response = Response::plaintext('Hello', 200);

        expect($response)
            ->toHaveHeader('Content-Type', 'text/plain; charset=utf-8');
    });

    it('creates HTML responses', function () {
        $response = Response::html('<h1>Hello</h1>');

        expect($response)
            ->toHaveHeader('Content-Type', 'text/html; charset=utf-8');
    });

    it('is immutable', function () {
        $r1 = Response::create('test', 200);
        $r2 = $r1->withStatus(404);

        expect($r1->getStatusCode())->toBe(200);
        expect($r2->getStatusCode())->toBe(404);
        expect($r1)->not->toBe($r2);
    });

    it('manages headers', function () {
        $response = Response::create('test')
            ->withHeader('X-Custom', 'value1')
            ->withAddedHeader('X-Custom', 'value2');

        expect($response->getHeader('X-Custom'))->toBe(['value1', 'value2']);
        expect($response->getHeaderLine('X-Custom'))->toBe('value1, value2');
    });

    it('uses smart header addition', function () {
        $response = Response::create('test')
            ->withSmartHeader('X-Test', 'first')
            ->withSmartHeader('X-Test', 'second');

        expect($response->getHeader('X-Test'))->toBe(['first', 'second']);
    });

    it('handles streaming responses', function () {
        $response = Response::stream(function () {
            yield 'chunk1';
            yield 'chunk2';
        });

        expect($response->isStreaming())->toBeTrue();

        $chunks = [];
        foreach ($response->getBody()->readChunks() as $chunk) {
            $chunks[] = $chunk;
        }

        expect($chunks)->toBe(['chunk1', 'chunk2']);
    });

    it('creates download responses', function () {
        $response = Response::download(__FILE__, 'test.php');

        expect($response)
            ->toHaveHeader('Content-Disposition')
            ->toHaveHeader('Content-Type', 'application/octet-stream');
    });

    it('handles cache control', function () {
        $response = Response::create('test')
            ->withCache(fn($cc) => $cc->public()->maxAge(3600));

        $cc = $response->getHeaderLine('Cache-Control');
        expect($cc)->toContain('public');
        expect($cc)->toContain('max-age=3600');
    });

    it('handles cookies', function () {
        $response = Response::create('test')
            ->withCookie('session', 'abc123', 3600);

        $setCookie = $response->getHeader('Set-Cookie');
        expect($setCookie)->toHaveCount(1);
        expect($setCookie[0])->toContain('session=abc123');
    });
});
