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

final class CachedClassHandlerFixture
{
    public static function handle(): array
    {
        return ['ok' => true];
    }
}

final class CachedLateStaticHandlerFixture extends CachedStaticHandlerBaseFixture {}

class CachedStaticHandlerBaseFixture
{
    public static function identify(): array
    {
        return ['class' => static::class];
    }
}

final class CachedInvokableHandlerFixture
{
    public function __invoke(): array
    {
        return ['handler' => 'invokable'];
    }
}

final class CachedInstanceMethodHandlerFixture
{
    public function handle(): array
    {
        return ['handler' => 'instance'];
    }
}

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

test('sharded readers pin their immutable generation across cache refreshes', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-sharded-refresh-' . uniqid('', true);

    try {
        RouteCache::build([
            'matcher' => 'sharded',
            'cache' => $directory,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/old', [CachedClassHandlerFixture::class, 'handle']);
            },
        ]);
        $existingReader = ShardedMatcher::make()->enableCache($directory);
        expect($existingReader->canBootFromCache())->toBeTrue();
        $existingReader->finalize();

        RouteCache::build([
            'matcher' => 'sharded',
            'cache' => $directory,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/new', [CachedClassHandlerFixture::class, 'handle']);
            },
        ]);
        $newReader = ShardedMatcher::make()->enableCache($directory);
        expect($newReader->canBootFromCache())->toBeTrue();
        $newReader->finalize();

        expect($existingReader->match('GET', 'localhost', '/old')[0]->getPath())->toBe('/old')
            ->and($newReader->match('GET', 'localhost', '/new')[0]->getPath())->toBe('/new')
            ->and(fn() => $existingReader->match('GET', 'localhost', '/new'))
            ->toThrow(RouteNotFoundException::class);
    } finally {
        RouteCache::clear([
            'matcher' => 'sharded',
            'cache' => $directory,
            'aggressive' => true,
        ]);
    }
});

test('all matcher cache builds coexist across refreshes', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-colocated-' . uniqid('', true);
    $fused = $directory . DIRECTORY_SEPARATOR . '__routes.php';
    $generated = $directory . DIRECTORY_SEPARATOR . '__generated.php';
    $sharded = $directory . DIRECTORY_SEPARATOR . '__manifest.php';
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

test('class-handler caches use scalar route payloads in every matcher mode', function (
    string $mode,
    Closure $makeMatcher,
): void {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-scalar-' . $mode . '-' . uniqid('', true);
    if ($mode !== 'sharded') {
        $cache .= '.php';
    }

    try {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $cache,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/class-handler', [CachedClassHandlerFixture::class, 'handle']);
            },
        ]);

        $matcher = $makeMatcher()->enableCache($cache);
        $matcher->finalize();
        [$route] = $matcher->match('GET', 'localhost', '/class-handler');
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $makeMatcher(),
            register: static function (): void {},
            routeCache: $cache,
            fallbackAliasesFromRegistrar: false,
        );
        $response = $kernel->handle(Request::fake(uri: 'http://localhost/class-handler'));

        expect($route->getHandler())->toBe([CachedClassHandlerFixture::class, 'handle'])
            ->and((string) $response->getBody())->toBe('{"ok":true}');
    } finally {
        RouteCache::clear([
            'matcher' => $mode,
            'cache' => $cache,
            'aggressive' => true,
        ]);
    }
})->with('cached matcher modes');

test('safe first-class static handlers become native cache descriptors in every matcher mode', function (
    string $mode,
    Closure $makeMatcher,
): void {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-first-class-' . $mode . '-' . uniqid('', true);
    if ($mode !== 'sharded') {
        $cache .= '.php';
    }

    try {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $cache,
            'register' => static function (Registrar $registrar): void {
                $captured = 'kept';
                $registrar->get('/static-callable', CachedLateStaticHandlerFixture::identify(...));
                $registrar->get('/captured-closure', static fn(): array => ['value' => $captured]);
            },
        ]);

        $matcher = $makeMatcher()->enableCache($cache);
        $matcher->finalize();
        [$staticRoute] = $matcher->match('GET', 'localhost', '/static-callable');
        [$capturedRoute] = $matcher->match('GET', 'localhost', '/captured-closure');

        expect($staticRoute->getHandler())
            ->toBe([CachedLateStaticHandlerFixture::class, 'identify'])
            ->and($capturedRoute->getHandler())->toBeInstanceOf(Closure::class);

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $makeMatcher(),
            register: static function (): void {},
            routeCache: $cache,
            fallbackAliasesFromRegistrar: false,
        );

        expect((string) $kernel->handle(Request::fake(uri: 'http://localhost/static-callable'))->getBody())
            ->toBe('{"class":"' . CachedLateStaticHandlerFixture::class . '"}')
            ->and((string) $kernel->handle(Request::fake(uri: 'http://localhost/captured-closure'))->getBody())
            ->toBe('{"value":"kept"}');
    } finally {
        RouteCache::clear([
            'matcher' => $mode,
            'cache' => $cache,
            'aggressive' => true,
        ]);
    }
})->with('cached matcher modes');

test('object-backed handlers retain their binding in every matcher mode', function (
    string $mode,
    Closure $makeMatcher,
): void {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-object-handler-' . $mode . '-' . uniqid('', true);
    if ($mode !== 'sharded') {
        $cache .= '.php';
    }

    try {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $cache,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/invokable', new CachedInvokableHandlerFixture());
                $registrar->get('/instance', [new CachedInstanceMethodHandlerFixture(), 'handle']);
            },
        ]);

        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $makeMatcher(),
            register: static function (): void {},
            routeCache: $cache,
            fallbackAliasesFromRegistrar: false,
        );

        expect((string) $kernel->handle(Request::fake(uri: 'http://localhost/invokable'))->getBody())
            ->toBe('{"handler":"invokable"}')
            ->and((string) $kernel->handle(Request::fake(uri: 'http://localhost/instance'))->getBody())
            ->toBe('{"handler":"instance"}');
    } finally {
        RouteCache::clear([
            'matcher' => $mode,
            'cache' => $cache,
            'aggressive' => true,
        ]);
    }
})->with('cached matcher modes');

test('route caches expose only registered middleware alias requirements', function (
    string $mode,
    Closure $makeMatcher,
): void {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-middleware-meta-' . $mode . '-' . uniqid('', true);
    if ($mode !== 'sharded') {
        $cache .= '.php';
    }
    MiddlewareAliases::register('auth', static fn(): Closure => static fn(
        Request $request,
        Closure $next,
    ): Response => $next($request));

    try {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $cache,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/secured', [CachedClassHandlerFixture::class, 'handle'], [
                    'middleware' => ['auth:admin', CachedInvokableHandlerFixture::class],
                ]);
            },
        ]);

        $matcher = $makeMatcher()->enableCache($cache);
        $matcher->finalize();

        expect($matcher->middlewareRequirements())->toBe(['auth']);
    } finally {
        MiddlewareAliases::reset();
        RouteCache::clear([
            'matcher' => $mode,
            'cache' => $cache,
            'aggressive' => true,
        ]);
    }
})->with('cached matcher modes');

test('staged cache validation preserves the active artifact on failure', function (): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-atomic-' . uniqid('', true) . '.php';
    file_put_contents($file, "<?php return ['value' => 'active'];\n");

    try {
        expect(fn() => \Infocyph\Webrick\Router\Matching\matcher_write_validated_atomic_php_file(
            $file,
            "<?php return ['value' => 'invalid'];\n",
            static function (): void {
                throw new UnexpectedValueException('rejected');
            },
        ))->toThrow(RuntimeException::class, 'validation failed');

        expect(require $file)->toBe(['value' => 'active']);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

test('stale cache formats fail clearly in every matcher mode', function (
    string $mode,
    Closure $makeMatcher,
): void {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-stale-' . $mode . '-' . uniqid('', true);
    if ($mode !== 'sharded') {
        $cache .= '.php';
    }

    try {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $cache,
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/cached', [CachedClassHandlerFixture::class, 'handle']);
            },
        ]);

        if ($mode === 'sharded') {
            $pointer = $cache . DIRECTORY_SEPARATOR . '__current';
            if (is_link($pointer)) {
                unlink($pointer);
            }
        }
        $artifact = $mode === 'sharded' ? $cache . DIRECTORY_SEPARATOR . '__manifest.php' : $cache;
        $source = file_get_contents($artifact);
        expect($source)->toBeString();
        $staleSource = preg_replace("/'_version' => \\d+/", "'_version' => 0", $source, 1);
        expect($staleSource)->toBeString()->not->toBe($source);
        file_put_contents($artifact, $staleSource);

        $reader = $makeMatcher()->enableCache($cache);
        expect(static function () use ($reader): void {
            if (!$reader->canBootFromCache()) {
                throw new RuntimeException('Cache unexpectedly unavailable.');
            }
            $reader->finalize();
            $reader->match('GET', 'localhost', '/cached');
        })->toThrow(RuntimeException::class, 'Stale');
    } finally {
        RouteCache::clear([
            'matcher' => $mode,
            'cache' => $cache,
            'aggressive' => true,
        ]);
    }
})->with('cached matcher modes');

test('named middleware resolver families replace stale application bindings', function (): void {
    MiddlewareAliases::reset();

    try {
        MiddlewareAliases::registerResolver(
            static fn(string $alias): bool => $alias === 'family',
            static fn(): string => 'first',
            'application.family',
        );
        MiddlewareAliases::registerResolver(
            static fn(string $alias): bool => $alias === 'family',
            static fn(): string => 'second',
            'application.family',
        );

        expect(MiddlewareAliases::resolveString('family'))->toBe('second');
    } finally {
        MiddlewareAliases::reset();
    }
});
