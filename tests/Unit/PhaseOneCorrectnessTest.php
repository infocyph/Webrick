<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ArrayCsrfTokenStore;
use Infocyph\Webrick\Request\Http\Csrf;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Emitter\SwooleEmitter;
use Infocyph\Webrick\Response\Headers\HeaderPolicy;
use Infocyph\Webrick\Response\Range\ByteRangeStream;
use Infocyph\Webrick\Response\Range\RangeParseStatus;
use Infocyph\Webrick\Response\Range\RangeParser;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

describe('Webrick 5 Phase 1 correctness and security invariants', function () {
    it('keeps native Swoole transport state request-local', function () {
        $reflection = new ReflectionClass(SwooleEmitter::class);
        $propertyTypes = [];
        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            $propertyTypes[] = $type instanceof ReflectionNamedType ? $type->getName() : null;
        }

        expect($propertyTypes)->not->toContain('Swoole\\Http\\Response');
    });

    it('keeps reusable gateway middleware free of current EndUser state', function () {
        $reflection = new ReflectionClass(GatewayHardeningMiddleware::class);
        $propertyNames = [];
        $propertyTypes = [];
        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            $propertyNames[] = $property->getName();
            $propertyTypes[] = $type instanceof ReflectionNamedType ? $type->getName() : null;
        }

        expect($propertyNames)->not->toContain('endUser')
            ->and($propertyTypes)->not->toContain(EndUser::class);
    });

    it('does not install a PHP error handler around each request', function () {
        $seen = false;
        set_error_handler(static function () use (&$seen): bool {
            $seen = true;

            return true;
        });

        try {
            $response = (new ErrorHandler(capturePhpErrors: true))->handle(
                mockRequest('GET', '/'),
                static function (): Response {
                    trigger_error('phase-one-probe', E_USER_WARNING);

                    return Response::noContent();
                },
            );
        } finally {
            restore_error_handler();
        }

        expect($seen)->toBeTrue()
            ->and($response->getStatusCode())->toBe(204);
    });

    it('accepts CSRF proof from explicit headers but never from cookies alone', function () {
        $store = new ArrayCsrfTokenStore('known-token');
        $csrf = new Csrf($store);

        expect($csrf->matches(mockRequest('POST', '/', ['X-CSRF-TOKEN' => 'known-token'])))->toBeTrue()
            ->and($csrf->matches(mockRequest('POST', '/', ['Cookie' => 'XSRF-TOKEN=known-token'])))->toBeFalse();
    });

    it('keeps CORS disabled by default and rejects credentialed wildcard policies', function () {
        $middleware = new CorsMiddleware();
        $response = $middleware(
            mockRequest('GET', '/', ['Origin' => 'https://example.test']),
            static fn(): Response => Response::noContent(),
        );

        expect($response->hasHeader('Access-Control-Allow-Origin'))->toBeFalse()
            ->and(fn() => new Cors(origins: ['*'], allowCredentials: true))
            ->toThrow(InvalidArgumentException::class);
    });

    it('models byte ranges as a stable bounded window', function () {
        $range = new ByteRangeStream(new Stream('0123456789'), 2, 4);

        expect($range->getSize())->toBe(4)
            ->and($range->read(2))->toBe('23')
            ->and($range->tell())->toBe(2);

        $range->rewind();
        expect($range->getContents())->toBe('2345')
            ->and(fn() => $range->seek(5))->toThrow(RuntimeException::class);
    });

    it('distinguishes malformed and unsatisfiable ranges', function () {
        expect(RangeParser::parse('bytes=5-2', 10)->status)->toBe(RangeParseStatus::MALFORMED)
            ->and(RangeParser::parse('bytes=20-', 10)->status)->toBe(RangeParseStatus::UNSATISFIABLE)
            ->and(RangeParser::parse('bytes=2-5', 10)->status)->toBe(RangeParseStatus::SATISFIABLE);
    });

    it('preserves case-sensitive HTTP method tokens when combining headers', function () {
        expect(HeaderPolicy::mergeCsv('Allow', 'GET, POST', 'patch, get'))->toBe('GET, POST, PATCH')
            ->and(HeaderPolicy::mergeCsv('Access-Control-Allow-Methods', 'GET', 'post'))->toBe('GET, POST');
    });

    it('exposes only the real Authorization header for PHP basic-auth server state', function () {
        $headers = RequestHeaders::extractFromServer([
            'PHP_AUTH_USER' => 'alice',
            'PHP_AUTH_PW' => 'secret',
        ]);

        expect($headers)->toHaveKey('Authorization')
            ->and($headers['Authorization'][0])->toBe('Basic ' . base64_encode('alice:secret'))
            ->and($headers)->not->toHaveKey('PHP_AUTH_USER')
            ->and($headers)->not->toHaveKey('PHP_AUTH_PW');
    });

    it('uses cookie name domain and path as identity and enforces security prefixes', function () {
        $jar = (new CookieJar())
            ->add(Cookie::make('session', 'one')->path('/'))
            ->add(Cookie::make('session', 'two')->path('/admin'));
        $response = $jar->apply(Response::noContent());
        $cookies = $response->getHeader('Set-Cookie');

        expect($cookies)->toHaveCount(2)
            ->and(implode("\n", $cookies))->toContain('Path=/')
            ->and(implode("\n", $cookies))->toContain('Path=/admin')
            ->and(fn() => Cookie::make('__Host-session')->domain('example.test'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => Cookie::make('__Secure-session')->secure(false))
            ->toThrow(InvalidArgumentException::class);
    });

    it('carries Produces metadata through compiled route cache payloads', function () {
        $route = (new Route('GET', '/report', 'strlen'))
            ->withProduces(new Produces(['application/json'], ['utf-8']));
        $compiled = CompiledRoute::fromRoute($route);
        $restored = CompiledRoute::fromCachePayload($compiled->toCachePayload());

        expect($restored->getProduces())->toBeInstanceOf(Produces::class)
            ->and($restored->getProduces()?->types)->toBe(['application/json'])
            ->and($restored->getProduces()?->charsets)->toBe(['utf-8']);
    });
});
