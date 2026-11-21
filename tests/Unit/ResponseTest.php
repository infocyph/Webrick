<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\View\ViewFactoryInterface;
use Infocyph\Webrick\Response\View\ViewResponder;
use Infocyph\Webrick\Router\Facade\Router as RouteFacade;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\Route;

describe('Response', function () {
    it('creates basic responses', function () {
        $response = Response::create('Hello World', 200);

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('Hello World');
    });

    it('creates JSON responses', function () {
        $data = ['name' => 'John', 'age' => 30];
        $response = Response::json($data);

        expect($response->getStatusCode())->toBe(200);

        $ct = $response->getHeaderLine('Content-Type');
        expect($ct)->toContain('application/json');

        $decoded = json_decode((string) $response->getBody(), true);
        expect($decoded)->toBe($data);
    });

    it('creates redirect responses', function () {
        $response = Response::redirect('/dashboard', 302);

        expect($response->getStatusCode())
            ->toBe(302)
            ->and($response->hasHeader('Location'))->toBeTrue();
    });

    it('creates empty responses', function () {
        $response = Response::empty(204);

        expect($response->getStatusCode())
            ->toBe(204)
            ->and((string) $response->getBody())->toBe('');
    });

    it('creates plaintext responses', function () {
        $response = Response::plaintext('Hello', 200);

        expect($response->hasHeader('Content-Type'))
            ->toBeTrue();
    });

    it('creates HTML responses', function () {
        $response = Response::create('<h1>Hello</h1>', 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);

        expect($response->hasHeader('Content-Type'))
            ->toBeTrue()
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

        $line = $response->getHeaderLine('X-Custom');
        expect($line)
            ->toContain('value1')
            ->and($line)->toContain('value2');
    });

    it('rejects invalid header names and values', function () {
        expect(fn () => Response::create('test', headers: ['Bad Header' => 'value']))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => Response::create('test', headers: ['X-Test' => "safe\r\nInjected: value"]))
            ->toThrow(InvalidArgumentException::class)
            ->and(static fn (): mixed => (new ReflectionMethod(Response::class, 'create'))->invoke(null, 'test', 200, ['X-Test' => ['safe', 1]]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('uses smart header addition', function () {
        $response = Response::create('test')
            ->withSmartHeader('X-Test', 'first')
            ->withSmartHeader('X-Test', 'second');

        expect($response->getHeader('X-Test'))->toBe(['second']);
    });

    it('handles streaming responses', function () {
        $response = Response::stream(function () {
            yield 'chunk1';
            yield 'chunk2';
        });

        expect($response->isStreaming())->toBeTrue();
    });

    it('preserves callable scalar stream producer coercion from 6.0', function () {
        $integer = Response::stream(static fn(): int => 42)->getProducer();
        $stringable = Response::stream(static fn(): \Stringable => new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'from-stringable';
            }
        })->getProducer();

        if ($integer === null || $stringable === null) {
            throw new UnexpectedValueException('Stream producers must remain available.');
        }

        expect($integer())->toBe(['42'])
            ->and($stringable())->toBe(['from-stringable']);
    });

    it('creates download responses', function () {
        $response = Response::download(__FILE__, 'test.php');

        expect($response->hasHeader('Content-Disposition'))
            ->toBeTrue()
            ->and($response->hasHeader('Content-Type'))->toBeTrue();
    });

    it('serves ranged downloads', function () {
        $req = Request::fake(headers: ['Range' => 'bytes=0-9'], uri: '/download');
        $response = Response::rangedDownload($req, __FILE__, 'response-test.php', 'text/plain');

        expect($response->getStatusCode())
            ->toBe(206)
            ->and($response->hasHeader('Accept-Ranges'))->toBeTrue()
            ->and($response->getHeaderLine('Content-Range'))->toContain('bytes 0-9/')
            ->and($response->getHeaderLine('Content-Length'))->toBe('10');
    });

    it('returns 416 for unsatisfiable ranges', function () {
        $req = Request::fake(headers: ['Range' => 'bytes=999999-1000000'], uri: '/download');
        $response = Response::rangedDownload($req, __FILE__, 'response-test.php', 'text/plain');

        expect($response->getStatusCode())
            ->toBe(416)
            ->and($response->getHeaderLine('Content-Range'))->toContain('bytes */');
    });

    it('handles cache control', function () {
        $response = Response::create('test')
            ->withCache(static fn (CacheControl $cc): CacheControl => $cc->public()->maxAge(3600));

        $cc = $response->getHeaderLine('Cache-Control');
        expect($cc)
            ->toContain('public')
            ->and($cc)->toContain('max-age=3600');
    });

    it('handles cookies', function () {
        $jar = new CookieJar;
        $cookie = Cookie::make('session', 'abc123');
        $jar = $jar->add($cookie);

        $response = Response::create('test');
        $response = $jar->apply($response);

        $setCookie = $response->getHeader('Set-Cookie');
        expect($setCookie)
            ->toHaveCount(1)
            ->and($setCookie[0])->toContain('session=abc123');
    });

    it('rejects cookie attribute injection and preserves epoch expiry', function () {
        expect(fn () => Cookie::make('session')->domain("example.com\r\nX-Test: injected"))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => Cookie::make('session')->path('/safe; injected=true'))
            ->toThrow(InvalidArgumentException::class);

        $cookie = Cookie::make('session')->expires(new DateTimeImmutable('@0'));
        expect((string) $cookie)->toContain('Expires=Thu, 01 Jan 1970 00:00:00 GMT');
    });

    it('can reset bound URL services', function () {
        $routes = new Collection;
        $route = (new Route('GET', '/users/{id}', fn () => Response::noContent()))
            ->withName('users.show');
        $routes->add($route);

        RouteFacade::bindUrlServices($routes, 'test-sign-key', 60);
        expect(RouteFacade::urlFor('users.show', ['id' => 7]))->toBe('/users/7');

        RouteFacade::resetUrlServices();
        expect(fn () => RouteFacade::urlFor('users.show', ['id' => 7]))
            ->toThrow(LogicException::class);
    });

    it('supports the opt-in global route() helper', function () {
        require_once __DIR__ . '/../../src/functions.php';

        $routes = new Collection;
        $route = (new Route('GET', '/users/{id}', fn () => Response::noContent()))
            ->withName('users.show');
        $routes->add($route);

        try {
            RouteFacade::bindUrlServices($routes, 'test-sign-key', 60);

            expect(route('users.show', ['id' => 7]))->toBe('/users/7')
                ->and(route('users.show', ['id' => 7], ['tab' => 'profile']))->toBe('/users/7?tab=profile');
        } finally {
            RouteFacade::resetUrlServices();
        }
    });

    it('renders views through the explicit view responder boundary', function () {
        $factory = new class implements ViewFactoryInterface {
            /** @throws \UnexpectedValueException */
            #[\Override]
            public function render(string $view, array $data = []): string
            {
                $name = $data['name'] ?? 'n/a';
                if (!is_string($name)) {
                    throw new UnexpectedValueException('View name must be a string.');
                }

                return '<h1>' . $view . ': ' . $name . '</h1>';
            }
        };
        $response = (new ViewResponder($factory))->render('home', ['name' => 'Ada']);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toBe('text/html; charset=utf-8')
            ->and((string) $response->getBody())->toBe('<h1>home: Ada</h1>');
    });
});
