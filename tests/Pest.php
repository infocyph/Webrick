<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
require_once __DIR__ . '/TestHelpers.php';
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function () {
    // Reset any global state
    $_SERVER = [];
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = [];

    // Clean request time
    $_SERVER['REQUEST_TIME'] = time();
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
})->in('Unit', 'Integration', 'Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeRequest', function () {
    return $this->toBeInstanceOf(Request::class);
});

expect()->extend('toBeResponse', function () {
    return $this->toBeInstanceOf(Response::class);
});

expect()->extend('toHaveStatus', function (int $expected) {
    expect($this->value)->toBeResponse();
    expect($this->value->getStatusCode())->toBe($expected);
    return $this;
});

expect()->extend('toHaveHeader', function (string $name, ?string $value = null) {
    expect($this->value)->toBeResponse();
    expect($this->value->hasHeader($name))->toBeTrue();

    if ($value !== null) {
        expect($this->value->getHeaderLine($name))->toBe($value);
    }

    return $this;
});

expect()->extend('toHaveJsonBody', function (array $expected) {
    expect($this->value)->toBeResponse();
    $body = json_decode((string)$this->value->getBody(), true);
    expect($body)->toBe($expected);
    return $this;
});

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a mock request with given parameters.
 */
function mockRequest(
    string $method = 'GET',
    string $uri = '/',
    array $headers = [],
    mixed $body = null,
    array $query = [],
    array $server = []
): Request {
    $defaultServer = [
        'REQUEST_METHOD' => strtoupper($method),
        'REQUEST_URI' => $uri,
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST' => 'localhost',
        'REMOTE_ADDR' => '127.0.0.1',
    ];

    $_SERVER = array_merge($defaultServer, $server);
    $_GET = $query;

    if ($body !== null) {
        if (is_array($body)) {
            $_POST = $body;
        }
    }

    $request = Request::fromGlobals();

    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    return $request;
}

/**
 * Create a test response.
 */
function mockResponse(
    int $status = 200,
    string $body = '',
    array $headers = []
): Response {
    return Response::create($body, $status, $headers);
}

/**
 * Capture output from emitter.
 */
function captureEmitted(Response $response): array {
    ob_start();
    $headers = [];

    // Mock header() function behavior
    $headerCallback = function($header) use (&$headers) {
        $headers[] = $header;
    };

    try {
        $emitter = new \Infocyph\Webrick\Response\Emitter\AutoEmitter();
        $emitter->emit($response);

        return [
            'body' => ob_get_clean(),
            'headers' => $headers,
        ];
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}
