<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Infocyph\Webrick\Request\Request;

describe('Request', function () {
    it('creates from globals', function () {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
            'HTTP_HOST' => 'example.com',
        ];

        $request = Request::fromGlobals();

        expect($request)
            ->toBeInstanceOf(Request::class)
            ->getMethod()->toBe('GET')
            ->getUri()->getPath()->toBe('/test')
            ->getUri()->getHost()->toBe('example.com');
    });

    it('can be created with factory methods', function () {
        $request = mockRequest('GET', '/users', ['Accept' => 'application/json']);

        expect($request)
            ->getMethod()->toBe('GET')
            ->getUri()->getPath()->toBe('/users')
            ->getHeaderLine('Accept')->toBe('application/json');
    });

    it('is immutable', function () {
        $r1 = mockRequest('GET', '/test');
        $r2 = $r1->withMethod('POST');

        expect($r1->getMethod())
            ->toBe('GET')
            ->and($r2->getMethod())->toBe('POST')
            ->and($r1)->not->toBe($r2);
    });

    it('handles query parameters', function () {
        $request = mockRequest('GET', '/search?q=test&page=2');

        $params = $request->getQueryParams();
        expect($params)
            ->toBe(['q' => 'test', 'page' => '2'])
            ->and($params['q'])->toBe('test')
            ->and($params['missing'] ?? 'default')->toBe('default');

        // Request doesn't have query() method, use getQueryParams()
    });

    it('lazily exposes variables through magic properties after immutable changes', function () {
        $request = Request::fake(query: ['query_key' => 'first'])
            ->withQueryParams(['query_key' => 'second']);

        expect($request->query_key)->toBe('second')
            ->and(isset($request->query_key))->toBeTrue()
            ->and(isset($request->missing_key))->toBeFalse();
    });

    it('preserves zero-like URI components and request targets', function () {
        $uri = new Uri('https://0@example.com/path?0');
        $request = new ServerRequest('GET', $uri);

        expect($uri->getAuthority())
            ->toBe('0@example.com')
            ->and($request->getRequestTarget())->toBe('/path?0');
    });

    it('preserves zero-byte upload sizes and rejects negative sizes', function () {
        $upload = new UploadedFile(new Stream(''), 0);

        expect($upload->getSize())
            ->toBe(0)
            ->and(fn() => new UploadedFile(new Stream(''), -1))
            ->toThrow(InvalidArgumentException::class);
    });

    it('keeps data and all caches in sync after immutable mutations', function () {
        $request = Request::fake(query: ['q' => 'old'], post: ['name' => 'first']);

        expect($request->data('q'))->toBe('old')
            ->and($request->all()['name'])->toBe('first');

        $request2 = $request->withQueryParams(['q' => 'new']);
        expect($request2->data('q'))->toBe('new')
            ->and($request->data('q'))->toBe('old');

        $request3 = $request2->withParsedBody(['name' => 'second']);
        expect($request3->data('name'))->toBe('second')
            ->and($request3->all()['name'])->toBe('second');
    });

    it('handles POST data', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['name' => 'John', 'age' => '30'];

        $request = Request::fromGlobals();

        expect($request->getParsedBody())
            ->toBe(['name' => 'John', 'age' => '30'])
            ->and($request->input('name'))->toBe('John')
            ->and((int) $request->input('age'))->toBe(30);
    });

    it('handles JSON body', function () {
        $json = json_encode(['key' => 'value']);

        $request = mockRequest('POST', '/api', [
            'Content-Type' => 'application/json',
        ]);

        $stream = new Stream($json);
        $request = $request->withBody($stream);

        // Request doesn't auto-parse JSON, that's done by middleware
        $body = json_decode((string) $request->getBody(), true);
        expect($body)->toBe(['key' => 'value']);
    });

    it('detects effective method', function () {
        $request = mockRequest('GET', '/');
        expect($request->getEffectiveMethod())->toBe('GET');

        $headRequest = $request->withMethod('HEAD');
        expect($headRequest->getEffectiveMethod())->toBe('GET');

        $postRequest = mockRequest('POST', '/', ['X-HTTP-Method-Override' => 'PUT']);
        expect($postRequest->getEffectiveMethod())->toBe('PUT');
    });

    it('handles cookies', function () {
        $_COOKIE = ['session' => 'abc123', 'theme' => 'dark'];

        $request = Request::fromGlobals();

        expect($request->getCookieParams())
            ->toBe(['session' => 'abc123', 'theme' => 'dark'])
            ->and($request->cookie('session'))->toBe('abc123')
            ->and($request->cookie('missing'))->toBeNull();
    });

    it('handles attributes', function () {
        $request = mockRequest('GET', '/');

        $request = $request
            ->withAttribute('user_id', 42)
            ->withAttribute('locale', 'en');

        expect($request->getAttribute('user_id'))
            ->toBe(42)
            ->and($request->getAttribute('locale'))->toBe('en')
            ->and($request->getAttribute('missing', 'default'))->toBe('default');
    });

    it('prefers content type', function () {
        $request = mockRequest('GET', '/', [
            'Accept' => 'application/json, text/html;q=0.9, */*;q=0.8',
        ]);

        expect($request->prefers(['text/html', 'application/json']))
            ->toBe('application/json');
    });

    it('detects locale', function () {
        $request = mockRequest('GET', '/', [
            'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
        ]);

        [$locale, $source] = $request->detectLocale(['en', 'fr'], 'en');

        expect($locale)
            ->toBe('fr')
            ->and($source)->toBe('header');
    });

    it('validates input', function () {
        $_POST = [
            'email' => 'user@example.com',
            'age' => '25',
        ];

        $request = Request::fromGlobals();

        expect($request->has('email'))
            ->toBeTrue()
            ->and($request->has('missing'))->toBeFalse()
            ->and($request->filled('email'))->toBeTrue();
    });
});
