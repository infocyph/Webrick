<?php

declare(strict_types=1);

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
            ->toBeRequest()
            ->getMethod()->toBe('GET')
            ->getUri()->getPath()->toBe('/test')
            ->getUri()->getHost()->toBe('example.com');
    });

    it('can be created with factory methods', function () {
        $request = Request::get('/users', ['Accept' => 'application/json']);

        expect($request)
            ->getMethod()->toBe('GET')
            ->getUri()->getPath()->toBe('/users')
            ->getHeaderLine('Accept')->toBe('application/json');
    });

    it('is immutable', function () {
        $r1 = Request::get('/test');
        $r2 = $r1->withMethod('POST');

        expect($r1->getMethod())->toBe('GET');
        expect($r2->getMethod())->toBe('POST');
        expect($r1)->not->toBe($r2);
    });

    it('handles query parameters', function () {
        $request = Request::get('/search?q=test&page=2');

        expect($request->getQueryParams())->toBe(['q' => 'test', 'page' => '2']);
        expect($request->query('q'))->toBe('test');
        expect($request->query('missing', 'default'))->toBe('default');
    });

    it('handles POST data', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['name' => 'John', 'age' => '30'];

        $request = Request::fromGlobals();

        expect($request->getParsedBody())->toBe(['name' => 'John', 'age' => '30']);
        expect($request->input('name'))->toBe('John');
        expect($request->integer('age'))->toBe(30);
    });

    it('handles JSON body', function () {
        $json = json_encode(['key' => 'value']);

        $request = mockRequest('POST', '/api', [
            'Content-Type' => 'application/json',
        ]);

        $request = $request->withBody(
            new \Infocyph\Webrick\Request\Core\Stream($json)
        );

        expect($request->getParsedBody())->toBe(['key' => 'value']);
    });

    it('detects effective method', function () {
        $request = mockRequest('GET', '/');
        expect($request->getEffectiveMethod())->toBe('GET');

        $headRequest = $request->withMethod('HEAD');
        expect($headRequest->getEffectiveMethod())->toBe('GET');

        $postRequest = mockRequest('POST', '/', [], ['_method' => 'PUT']);
        expect($postRequest->getEffectiveMethod())->toBe('PUT');
    });

    it('handles cookies', function () {
        $_COOKIE = ['session' => 'abc123', 'theme' => 'dark'];

        $request = Request::fromGlobals();

        expect($request->getCookieParams())->toBe(['session' => 'abc123', 'theme' => 'dark']);
        expect($request->cookie('session'))->toBe('abc123');
        expect($request->cookie('missing'))->toBeNull();
    });

    it('handles attributes', function () {
        $request = mockRequest('GET', '/');

        $request = $request
            ->withAttribute('user_id', 42)
            ->withAttribute('locale', 'en');

        expect($request->getAttribute('user_id'))->toBe(42);
        expect($request->getAttribute('locale'))->toBe('en');
        expect($request->getAttribute('missing', 'default'))->toBe('default');
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

        expect($locale)->toBe('fr');
        expect($source)->toBe('header');
    });

    it('validates input', function () {
        $request = mockRequest('POST', '/', [], [
            'email' => 'user@example.com',
            'age' => '25',
        ]);

        expect($request->has('email'))->toBeTrue();
        expect($request->has('missing'))->toBeFalse();
        expect($request->filled('email'))->toBeTrue();
    });
});
