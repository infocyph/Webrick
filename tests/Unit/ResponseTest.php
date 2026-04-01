<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Facade\Router as RouteFacade;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;

describe('Response', function () {
    it('creates basic responses', function () {
        $response = Response::create('Hello World', 200);

        expect($response)
            ->toBeInstanceOf(\Infocyph\Webrick\Response\Response::class)
            ->getStatusCode()->toBe(200)
            ->and((string)$response->getBody())->toBe('Hello World');
    });

    it('creates JSON responses', function () {
        $data = ['name' => 'John', 'age' => 30];
        $response = Response::json($data);

        expect($response)->toHaveStatus(200);

        // Content-Type includes charset
        $ct = $response->getHeaderLine('Content-Type');
        expect($ct)->toContain('application/json');

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
        $response = Response::create('<h1>Hello</h1>', 200, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);

        expect($response)
            ->toHaveHeader('Content-Type')
            ->and($response->getHeaderLine('Content-Type'))->toContain('text/html');
    });

    it('is immutable', function () {
        $r1 = Response::create('test', 200);
        $r2 = $r1->withStatus(404);

        expect($r1->getStatusCode())
            ->toBe(200)
            ->and($r2->getStatusCode())->toBe(404)
            ->and($r1)->not->toBe($r2);
    });

    it('manages headers', function () {
        $response = Response::create('test')
            ->withHeader('X-Custom', 'value1')
            ->withAddedHeader('X-Custom', 'value2');

        expect($response->getHeader('X-Custom'))->toBe(['value1', 'value2']);

        // Header line separator (no space after comma in PSR-7)
        $line = $response->getHeaderLine('X-Custom');
        expect($line)
            ->toContain('value1')
            ->and($line)->toContain('value2');
    });

    it('uses smart header addition', function () {
        $response = Response::create('test')
            ->withSmartHeader('X-Test', 'first')
            ->withSmartHeader('X-Test', 'second');

        // withSmartHeader replaces by default
        expect($response->getHeader('X-Test'))->toBe(['second']);
    });

    it('handles streaming responses', function () {
        $response = Response::stream(function () {
            yield 'chunk1';
            yield 'chunk2';
        });

        expect($response->isStreaming())->toBeTrue();
    });

    it('creates download responses', function () {
        $response = Response::download(__FILE__, 'test.php');

        expect($response)
            ->toHaveHeader('Content-Disposition')
            ->toHaveHeader('Content-Type', 'application/octet-stream');
    });

    it('serves ranged downloads', function () {
        $req = Request::fake(headers: ['Range' => 'bytes=0-9'], uri: '/download');
        $response = Response::rangedDownload($req, __FILE__, 'response-test.php', 'text/plain');

        expect($response)
            ->toHaveStatus(206)
            ->toHaveHeader('Accept-Ranges', 'bytes')
            ->and($response->getHeaderLine('Content-Range'))->toContain('bytes 0-9/')
            ->and($response->getHeaderLine('Content-Length'))->toBe('10');
    });

    it('returns 416 for unsatisfiable ranges', function () {
        $req = Request::fake(headers: ['Range' => 'bytes=999999-1000000'], uri: '/download');
        $response = Response::rangedDownload($req, __FILE__, 'response-test.php', 'text/plain');

        expect($response)
            ->toHaveStatus(416)
            ->and($response->getHeaderLine('Content-Range'))->toContain('bytes */');
    });

    it('handles cache control', function () {
        $response = Response::create('test')
            ->withCache(fn ($cc) => $cc->public()->maxAge(3600));

        $cc = $response->getHeaderLine('Cache-Control');
        expect($cc)
            ->toContain('public')
            ->and($cc)->toContain('max-age=3600');
    });

    it('handles cookies', function () {
        $jar = new CookieJar();
        $cookie = Cookie::make('session', 'abc123');
        $jar = $jar->add($cookie);

        $response = Response::create('test');
        $response = $jar->apply($response);

        $setCookie = $response->getHeader('Set-Cookie');
        expect($setCookie)
            ->toHaveCount(1)
            ->and($setCookie[0])->toContain('session=abc123');
    });

    it('can reset bound URL services', function () {
        $routes = new Collection();
        $route = (new Route('GET', '/users/{id}', fn () => Response::noContent()))
            ->withName('users.show');
        $routes->add($route);

        RouteFacade::bindUrlServices($routes, 'test-sign-key', 60);
        expect(RouteFacade::urlFor('users.show', ['id' => 7]))->toBe('/users/7');

        RouteFacade::resetUrlServices();
        expect(fn () => RouteFacade::urlFor('users.show', ['id' => 7]))
            ->toThrow(\LogicException::class);
    });

    it('supports global route() helper', function () {
        $routes = new Collection();
        $route = (new Route('GET', '/users/{id}', fn () => Response::noContent()))
            ->withName('users.show');
        $routes->add($route);

        RouteFacade::bindUrlServices($routes, 'test-sign-key', 60);

        expect(route('users.show', ['id' => 7]))->toBe('/users/7')
            ->and(route('users.show', ['id' => 7], ['tab' => 'profile']))->toBe('/users/7?tab=profile');
    });

    it('throws when view factory is not bound', function () {
        expect(fn () => Response::view('home', [], 200, [], 'utf-8', '__missing_view_factory__'))
            ->toThrow(\RuntimeException::class);
    });
});
