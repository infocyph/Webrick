<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/** @param array<string, mixed> $server */
function gatewayRequest(array $server, string $uri = 'http://origin.example/resource'): Request
{
    return new Request('GET', new Uri($uri), $server);
}

test('gateway ignores forwarded context from an untrusted peer', function (): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['origin.example'],
        enforceHttps: false,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '203.0.113.20',
        'HTTP_HOST' => 'origin.example',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/resource',
        'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'attacker.example',
    ]);

    $response = $gateway($request, static function (Request $normalized): Response {
        expect($normalized->getUri()->getScheme())->toBe('http')
            ->and($normalized->getUri()->getHost())->toBe('origin.example')
            ->and($normalized->getAttribute('client_ip'))->toBe('203.0.113.20')
            ->and($normalized->getAttribute('is_trusted_proxy'))->toBeFalse();

        return Response::json(['ok' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
});

test('gateway normalizes trusted proxy context before security checks', function (): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['public.example'],
        enforceHttps: true,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '10.0.0.9',
        'HTTP_HOST' => 'internal.example:8080',
        'SERVER_PORT' => '8080',
        'REQUEST_URI' => '/resource?x=1',
        'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 10.0.0.8',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'public.example',
        'HTTP_X_FORWARDED_PORT' => '443',
    ]);

    $response = $gateway($request, static function (Request $normalized): Response {
        expect((string) $normalized->getUri())->toBe('https://public.example/resource?x=1')
            ->and($normalized->getAttribute('client_ip'))->toBe('8.8.8.8')
            ->and($normalized->getAttribute('peer_ip'))->toBe('10.0.0.9')
            ->and($normalized->getAttribute('is_trusted_proxy'))->toBeTrue()
            ->and($normalized->getAttribute('effective_scheme'))->toBe('https')
            ->and($normalized->getAttribute('effective_host'))->toBe('public.example')
            ->and($normalized->getAttribute('effective_port'))->toBeNull();

        return Response::json(['ok' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
});

test('gateway resolves an IPv6 client through an IPv6 trusted proxy', function (): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['2001:db8:feed::/48'],
        trustedHosts: ['origin.example'],
        enforceHttps: false,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '2001:db8:feed:1::9',
        'HTTP_HOST' => 'origin.example',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/resource',
        'HTTP_FORWARDED' => 'for="[2001:db8:abcd::42]"',
    ]);

    $response = $gateway($request, static function (Request $normalized): Response {
        expect($normalized->getAttribute('client_ip'))->toBe('2001:db8:abcd::42')
            ->and($normalized->getAttribute('peer_ip'))->toBe('2001:db8:feed:1::9')
            ->and($normalized->getAttribute('is_trusted_proxy'))->toBeTrue();

        return Response::json(['ok' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
});

test('gateway keeps proxy policies isolated between instances', function (): void {
    $trusting = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['public.example'],
        enforceHttps: false,
    );
    $strict = new GatewayHardeningMiddleware(
        trustedProxyCidrs: [],
        trustedHosts: ['origin.example'],
        enforceHttps: false,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '10.0.0.9',
        'HTTP_HOST' => 'origin.example',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/',
        'HTTP_X_FORWARDED_HOST' => 'public.example',
    ]);

    $trusting($request, static fn(Request $r): Response => Response::json(['host' => $r->getUri()->getHost()]));
    $response = $strict($request, static function (Request $normalized): Response {
        expect($normalized->getUri()->getHost())->toBe('origin.example');

        return Response::json(['ok' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
});

test('gateway preserves intentional host and port semantics', function (array $server, string $expected): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['*'],
        enforceHttps: false,
    );

    $gateway(gatewayRequest($server), static function (Request $normalized) use ($expected): Response {
        expect((string) $normalized->getUri())->toBe($expected);

        return Response::json(['ok' => true]);
    });
})->with([
    'http default port' => [[
        'REMOTE_ADDR' => '10.0.0.2', 'HTTP_HOST' => 'public.example', 'SERVER_PORT' => '80',
        'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_PORT' => '80',
    ], 'http://public.example/'],
    'https default port' => [[
        'REMOTE_ADDR' => '10.0.0.2', 'HTTP_HOST' => 'public.example', 'SERVER_PORT' => '80',
        'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443',
    ], 'https://public.example/'],
    'custom port' => [[
        'REMOTE_ADDR' => '10.0.0.2', 'HTTP_HOST' => 'internal.example', 'SERVER_PORT' => '80',
        'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'public.example:8443',
    ], 'https://public.example:8443/'],
    'IPv6 host' => [[
        'REMOTE_ADDR' => '10.0.0.2', 'HTTP_HOST' => 'internal.example', 'SERVER_PORT' => '80',
        'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => '[2001:db8::1]:8443',
    ], 'https://[2001:db8::1]:8443/'],
    'first forwarded host is authoritative' => [[
        'REMOTE_ADDR' => '10.0.0.2', 'HTTP_HOST' => 'internal.example', 'SERVER_PORT' => '80',
        'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_HOST' => 'public.example, ignored.example',
    ], 'http://public.example/'],
    'standard Forwarded takes precedence' => [[
        'REMOTE_ADDR' => '10.0.0.2', 'HTTP_HOST' => 'internal.example', 'SERVER_PORT' => '80',
        'REQUEST_URI' => '/', 'HTTP_FORWARDED' => 'host=standard.example;proto=https',
        'HTTP_X_FORWARDED_HOST' => 'legacy.example',
    ], 'https://standard.example/'],
]);

test('gateway rejects a forwarded host that violates its allow-list', function (): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['public.example'],
        enforceHttps: false,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_HOST' => 'internal.example',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/',
        'HTTP_X_FORWARDED_HOST' => 'evil.example',
    ]);

    expect(fn() => $gateway($request, static fn(): Response => Response::json([])))
        ->toThrow(HttpException::class, 'Untrusted Host header');
});

test('gateway treats non-wildcard allow-list characters literally', function (): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['public[.]example'],
        enforceHttps: false,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_HOST' => 'internal.example',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/',
        'HTTP_X_FORWARDED_HOST' => 'public.example',
    ]);

    expect(fn() => $gateway($request, static fn(): Response => Response::json([])))
        ->toThrow(HttpException::class, 'Untrusted Host header');
});

test('gateway rejects malformed forwarded hosts', function (): void {
    $gateway = new GatewayHardeningMiddleware(
        trustedProxyCidrs: ['10.0.0.0/8'],
        trustedHosts: ['*'],
        enforceHttps: false,
    );
    $request = gatewayRequest([
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_HOST' => 'internal.example',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/',
        'HTTP_X_FORWARDED_HOST' => 'bad host',
    ]);

    expect(fn() => $gateway($request, static fn(): Response => Response::json([])))
        ->toThrow(InvalidArgumentException::class);
});
