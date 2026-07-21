<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

dataset('cached matcher modes', [
    'sharded' => ['sharded', static fn(): MatcherInterface => ShardedMatcher::make()],
    'fused' => ['fused', static fn(): MatcherInterface => FusedMatcher::make()],
    'generated' => ['generated', static fn(): MatcherInterface => GeneratedMatcher::make()],
]);

test('GeneratedMatcher matches static and dynamic routes', function (): void {
    $matcher = GeneratedMatcher::make();

    $static = CompiledRoute::fromRoute(
        (new Route('GET', '/hello', static fn(): string => 'ok'))
            ->withDomain('example.com'),
    );
    $dynamic = CompiledRoute::fromRoute(
        (new Route('GET', '/hello/{name}', static fn(): string => 'ok'))
            ->withDomain('example.com'),
    );

    $matcher->add($static);
    $matcher->add($dynamic);
    $matcher->finalize();

    [$r1, $p1] = $matcher->match('GET', 'example.com', '/hello');
    expect($r1->getPath())->toBe('/hello')
        ->and($p1)->toBe([]);

    [$r2, $p2] = $matcher->match('GET', 'example.com', '/hello/alice');
    expect($r2->getPath())->toBe('/hello/{name}')
        ->and($p2)->toBe(['name' => 'alice']);

    expect(fn() => $matcher->match('POST', 'example.com', '/hello'))
        ->toThrow(MethodNotAllowedException::class);

    expect(fn() => $matcher->match('GET', 'example.com', '/missing'))
        ->toThrow(RouteNotFoundException::class);
});

test('GeneratedMatcher boots from generated cache file', function (): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-generated-' . uniqid('', true) . '.php';

    try {
        RouteCache::build([
            'matcher' => 'generated',
            'cache' => $file,
            'register' => static function (Registrar $r): void {
                $r->group(
                    domain: 'example.com',
                    callback: static function (Registrar $g): void {
                        $g->get('/hello', static fn(): string => 'ok', 'hello.route');
                    },
                );
            },
        ]);

        expect(is_file($file))->toBeTrue();

        $reader = GeneratedMatcher::make()->enableCache($file);
        expect($reader->canBootFromCache())->toBeTrue();
        $reader->finalize();

        [$hit, $params] = $reader->match('GET', 'example.com', '/hello');
        expect($hit->getPath())->toBe('/hello')
            ->and($params)->toBe([])
            ->and($reader->resolveAlias('hello.route'))->toBe(['/hello', 'example.com']);

        $registrations = 0;
        $aliases = [];
        RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: GeneratedMatcher::make(),
            register: static function () use (&$registrations): void {
                $registrations++;
            },
            routeCache: $file,
            bindUrlServices: static function (Collection $routes) use (&$aliases): void {
                $aliases = $routes->aliasIndex();
            },
            fallbackAliasesFromRegistrar: false,
        );

        expect($registrations)->toBe(0)
            ->and($aliases['hello.route'])->toBe(['/hello', 'example.com']);
    } finally {
        unlink($file);
    }
});

test('RouteCache build replaces an existing generated cache', function (): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-generated-refresh-' . uniqid('', true) . '.php';

    try {
        RouteCache::build([
            'matcher' => 'generated',
            'cache' => $file,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/old', static fn(): string => 'old');
            },
        ]);

        RouteCache::build([
            'matcher' => 'generated',
            'cache' => $file,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/new', static fn(): string => 'new');
            },
        ]);

        $reader = GeneratedMatcher::make()->enableCache($file);
        expect($reader->canBootFromCache())->toBeTrue();
        $reader->finalize();

        [$hit] = $reader->match('GET', 'localhost', '/new');
        expect($hit->getPath())->toBe('/new')
            ->and(fn() => $reader->match('GET', 'localhost', '/old'))
            ->toThrow(RouteNotFoundException::class);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

test('all matcher cache builds coexist across refreshes', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-colocated-' . uniqid('', true);
    $fused = $directory . DIRECTORY_SEPARATOR . '__routes.php';
    $generated = $directory . DIRECTORY_SEPARATOR . '__generated.php';
    $sharded = $directory . DIRECTORY_SEPARATOR . '__root.php';
    mkdir($directory);

    $register = static function (Registrar $registrar): void {
        $registrar->get('/cached', static fn(): string => 'ok');
    };

    try {
        RouteCache::build([
            'matcher' => 'fused',
            'cache' => $fused,
            'register' => $register,
        ]);
        RouteCache::build([
            'matcher' => 'generated',
            'cache' => $generated,
            'register' => $register,
        ]);
        RouteCache::build([
            'matcher' => 'sharded',
            'cache' => $directory,
            'register' => $register,
        ]);

        expect(is_file($sharded))->toBeTrue()
            ->and(is_file($fused))->toBeTrue()
            ->and(is_file($generated))->toBeTrue();

        foreach ([
            ['fused', $fused],
            ['generated', $generated],
            ['sharded', $directory],
        ] as [$matcher, $cache]) {
            RouteCache::build([
                'matcher' => $matcher,
                'cache' => $cache,
                'register' => $register,
            ]);

            expect(is_file($sharded))->toBeTrue()
                ->and(is_file($fused))->toBeTrue()
                ->and(is_file($generated))->toBeTrue();
        }
    } finally {
        RouteCache::clear([
            'matcher' => 'sharded',
            'cache' => $directory,
            'aggressive' => true,
        ]);
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

test('default hot-cache URL services defer alias fallback until first use', function (): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-lazy-alias-' . uniqid('', true) . '.php';

    try {
        RouteCache::build([
            'matcher' => 'generated',
            'cache' => $file,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/cached', static fn(): Response => Response::json(['ok' => true]));
            },
        ]);

        $registrations = 0;
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: GeneratedMatcher::make(),
            register: static function (Registrar $registrar) use (&$registrations): void {
                $registrations++;
                $registrar->get('/fallback', static fn(): string => 'ok', 'fallback.route');
            },
            routeCache: $file,
        );

        expect($registrations)->toBe(0)
            ->and($kernel->handle(Request::fake(uri: 'http://localhost/cached'))->getStatusCode())->toBe(200)
            ->and($registrations)->toBe(0)
            ->and(\Infocyph\Webrick\Router\Facade\Router::urlFor('fallback.route'))->toBe('/fallback')
            ->and($registrations)->toBe(1);
    } finally {
        \Infocyph\Webrick\Router\Facade\Router::reset();
        if (is_file($file)) {
            unlink($file);
        }
    }
});

test('fresh route caches boot and lazily dispatch alias middleware', function (
    string $mode,
    Closure $makeMatcher,
): void {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-fresh-' . $mode . '-' . uniqid('', true);
    if ($mode !== 'sharded') {
        $cache .= '.php';
    }

    $resolved = 0;
    MiddlewareAliases::reset();
    MiddlewareAliases::register('lazy', static function () use (&$resolved): Closure {
        $resolved++;

        return static fn(Request $request, Closure $next): Response => $next($request);
    });

    try {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $cache,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/cached', static fn(): Response => Response::json(['ok' => true]), [
                    'name' => 'cached.route',
                    'middleware' => ['lazy'],
                ]);
            },
        ]);

        expect($resolved)->toBe(0);

        $registrations = 0;
        $aliases = [];
        $matcher = $makeMatcher();
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $matcher,
            register: static function () use (&$registrations): void {
                $registrations++;
            },
            routeCache: $cache,
            bindUrlServices: static function (Collection $routes) use (&$aliases): void {
                $aliases = $routes->aliasIndex();
            },
            fallbackAliasesFromRegistrar: false,
        );

        expect($registrations)->toBe(0)
            ->and($aliases['cached.route'])->toBe(['/cached', null])
            ->and($resolved)->toBe(0);

        $response = $kernel->handle(Request::fake(uri: 'http://localhost/cached'));

        expect($response->getStatusCode())->toBe(200)
            ->and($resolved)->toBe(1);
    } finally {
        MiddlewareAliases::reset();
        RouteCache::clear([
            'matcher' => $mode,
            'cache' => $cache,
            'aggressive' => true,
        ]);
    }
})->with('cached matcher modes');
