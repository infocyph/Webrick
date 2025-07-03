<?php

// tests/RequestSpec.php
declare(strict_types=1);

use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Testing\RequestFactory;
use Infocyph\Webrick\Request\Http\Csrf;
use Infocyph\Webrick\Request\Http\ContentNegotiator;

/* ---------------------------------------------------------------------
   1. URI  (RFC 3986 path normalisation + serverParam factory)
   ------------------------------------------------------------------ */
dataset('uris', [
    ['https://例え.テスト:443/../a/./b?foo=1#bar', 'https://xn--r8jz45g.xn--zckzah/a/b?foo=1#bar'],
    ['mailto:foo@bar',                             'mailto:foo@bar'],
    ['[fe80::1%eth0]:8080',                        '[fe80::1%eth0]:8080'], // zone-ID kept
]);

it('normalises/path/casing/and/punycode', function (string $in, string $exp) {
    $u = new Uri($in);
    $out = (string)$u;
    if (!function_exists('idn_to_ascii')) {
        $exp = str_replace('xn--r8jz45g.xn--zckzah', '例え.テスト', $exp);
    }
    expect($out)->toBe($exp);
})->with('uris');

it('builds from $_SERVER params', function () {
    $srv = [
        'HTTPS'         => 'on',
        'HTTP_HOST'     => 'Example.com:8443',
        'REQUEST_URI'   => '/foo?bar=1',
        'SERVER_PORT'   => 8443,
    ];
    $uri = Uri::fromServerParams($srv);
    expect((string)$uri)->toBe('https://example.com:8443/foo?bar=1');
});

/* ---------------------------------------------------------------------
   2. EndUser IP helpers  (trusted proxies, public-first)
   ------------------------------------------------------------------ */
it('resolves first public IP after trusted proxies', function () {
    Request::setTrustedProxies(['10.0.0.0/8']);
    $req = RequestFactory::make(headers: [
        'Forwarded'  => 'for=10.0.0.2, for=198.51.100.10',
        'X-Real-IP'  => '10.0.0.2',
    ], server:['REMOTE_ADDR' => '10.0.0.1']);          // load-balancer

    expect($req->ip(proxyAware:true))->toBe('198.51.100.10');
});

/* ---------------------------------------------------------------------
   3. Accept negotiation  (wildcards + q-weights)
   ------------------------------------------------------------------ */
it('picks best mime according to q-values', function () {
    $req = RequestFactory::make(
        headers:['Accept' => 'text/html; q=0.2, application/json, */*;q=0.1']
    );
    $mime = (new ContentNegotiator($req->headers()))
        ->preferred(['application/json','text/html']);
    expect($mime)->toBe('application/json');
});

it('supports +json suffix wildcard', function () {
    $req = RequestFactory::make(headers:['Accept' => 'application/vnd.api+json']);
    expect($req->prefers(['+json']))->toBe('application/vnd.api+json');
});

/* ---------------------------------------------------------------------
   4. JSON / XML helpers & method-override
   ------------------------------------------------------------------ */
it('parses json bodies and honours override', function () {
    $body = ['a' => 1];
    $req  = RequestFactory::json('POST', '/x', $body, headers:['X-HTTP-Method-Override' => 'PATCH']);
    expect($req->parsedJson())->toMatchArray($body)
        ->and($req->getEffectiveMethod())->toBe('PATCH');
});

it('detects xml via content-type', function () {
    $xml = '<root><foo>bar</foo></root>';
    $req = RequestFactory::make(
        'POST',
        '/x',
        body:$xml,
        headers:['Content-Type' => 'application/xml']
    );
    expect($req->parsedXml('foo'))->toBe('bar');
});

/* ---------------------------------------------------------------------
   5. CSRF token masking (Laravel-compatible)
   ------------------------------------------------------------------ */
it('accepts masked CSRF token', function () {
    $_SESSION = [];                          // isolate test
    $plain = Csrf::token();                  // creates + stores
    $masked = Csrf::maskedToken();

    $req = RequestFactory::make(
        'POST',
        '/x',
        body:['_token' => $masked],
        cookies:['XSRF-TOKEN' => $plain]       // typical SPA double-submit
    );
    expect($req->matchesCsrfToken())->toBeTrue();
});

/* ---------------------------------------------------------------------
   6. Content negotiation helper on Request facade
   ------------------------------------------------------------------ */
it('Request::expectsJson() honours Ajax header fallback', function () {
    $req = RequestFactory::make(server:['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
    expect($req->expectsJson())->toBeTrue();
});
