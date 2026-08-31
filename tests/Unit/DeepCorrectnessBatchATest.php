<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Http\UAParser;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\TelemetrySupport;

describe('Deep correctness batch A', function () {
    it('preserves duplicate values for generic added headers', function () {
        $request = Request::fake()->withHeader('X-Test', 'a')->withAddedHeader('X-Test', 'a');

        expect($request->getHeader('X-Test'))->toBe(['a', 'a']);
    });

    it('preserves the URI port when preserveHost synthesizes Host', function () {
        $request = Request::fake()->withoutHeader('Host')
            ->withUri(Uri::from('https://example.com:8443/path'), true);

        expect($request->getHeaderLine('Host'))->toBe('example.com:8443');
    });

    it('parses content metadata strictly', function () {
        $request = Request::fake(headers: [
            'Content-Type' => 'Application/Problem+Json; Charset="UTF-8"',
            'Content-Length' => '12abc',
        ]);

        expect($request->headers()->content())->toMatchArray([
            'type' => 'application/problem+json',
            'charset' => 'utf-8',
            'length' => null,
        ]);
    });

    it('rejects malformed explicit qvalues instead of upgrading them', function () {
        $request = Request::fake(headers: ['Accept' => 'application/json;q=1.1, text/plain;q=0.5']);

        expect(new ContentNegotiator($request->headers())->preferred(['application/json', 'text/plain']))
            ->toBe('text/plain');
    });

    it('accepts structured JSON and XML media types without accepting jsonp', function () {
        expect(HttpUtils::isJsonContentType('application/problem+json'))->toBeTrue()
            ->and(HttpUtils::isJsonContentType('application/jsonp'))->toBeFalse()
            ->and(HttpUtils::isXmlContentType('application/problem+xml'))->toBeTrue()
            ->and(MediaTypeEnum::PROBLEM_JSON->isTextual())->toBeTrue()
            ->and(MediaTypeEnum::SVG->isTextual())->toBeTrue();
    });

    it('rejects non HTTP-date conditional values', function () {
        $request = Request::fake(headers: ['If-Modified-Since' => 'tomorrow']);
        $result = new ConditionalValidator(lastModified: time() - 3600, representationExists: true)->evaluate($request);

        expect($result->state)->toBe(Outcome::PASS)
            ->and(HttpUtils::parseHttpDate('tomorrow'))->toBeNull();
    });

    it('varies private-network CORS preflights on the request header', function () {
        $middleware = new CorsMiddleware(
            origins: ['https://example.com'],
            allowPrivateNetwork: true,
        );
        $request = Request::fake(method: 'OPTIONS', headers: [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Private-Network' => 'true',
        ]);
        $response = $middleware($request, static fn(Request $request): Response => Response::create('unused'));

        expect($response->getHeaderLine('Access-Control-Allow-Private-Network'))->toBe('true')
            ->and($response->getHeaderLine('Vary'))->toContain('Access-Control-Request-Private-Network');
    });

    it('normalizes Android and Client Hint platforms', function () {
        $android = new UAParser('Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/140.0.0.0 Mobile Safari/537.36');
        $hinted = new UAParser(Request::fake(headers: [
            'Sec-CH-UA-Platform' => '"Android"',
            'Sec-CH-UA-Platform-Version' => '"15.0.0"',
        ]));

        expect($android->parse()['platform'])->toBe('Android 15')
            ->and($hinted->parse()['platform'])->toBe('Android 15.0.0');
    });

    it('regenerates invalid reflected request ids', function () {
        $request = Request::fake(headers: ['X-Request-Id' => str_repeat('a', 129)]);
        $id = TelemetrySupport::deriveRequestId($request, true, 'X-Request-Id', true);

        expect($id)->not->toBe(str_repeat('a', 129))
            ->and($id)->not->toBeNull()
            ->and(strlen((string) $id))->toBeLessThanOrEqual(128);
    });

    it('rejects noncanonical signed URL numeric configuration', function () {
        expect(fn() => SignedUrlConfig::fromArray(['leeway' => '1e3']))
            ->toThrow(InvalidArgumentException::class);

        $middleware = new VerifySignedUrlMiddleware('secret');
        $request = Request::fake(query: ['_sig' => 'x', '_exp' => '1e9'], uri: '/signed');

        expect(fn() => $middleware($request, static fn(Request $request): Response => Response::create('ok')))
            ->toThrow(HttpException::class);
    });

    it('keeps ignored signed query parameters outside the signature payload', function () {
        $config = new SignedUrlConfig(
            generationKey: 'secret',
            ignoredQueryParams: ['utm_source'],
        );
        $generator = new UrlGenerator('', ['route' => ['/signed', null]], signedConfig: $config);
        $url = $generator->signed(
            'route',
            query: ['foo' => 'bar', 'utm_source' => 'campaign-a'],
            absolute: false,
        );
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query['utm_source'] = 'campaign-b';

        $request = Request::fake(query: $query, uri: '/signed');
        $middleware = new VerifySignedUrlMiddleware($config);
        $response = $middleware($request, static fn(Request $request): Response => Response::create('ok'));

        expect($response->getStatusCode())->toBe(200);
    });
});
