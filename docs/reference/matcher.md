# Matcher

Webrick provides three matcher modes. Pick one based on deployment shape and cache-artifact preference.

## ShardedMatcher

Best for medium and large apps with prebuilt route cache directories.

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(__DIR__ . '/.route-cache'),
    register: static function (Registrar $registrar): void {
        unset($registrar);
        require __DIR__ . '/routes.php';
    },
    routeCache: __DIR__ . '/.route-cache',
);
```

Traits:

- cache artifact is a directory
- good OPcache locality
- best default for production

## FusedMatcher

Best when you want one cache file to build and ship.

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: FusedMatcher::make(__DIR__ . '/.route-cache/__routes.php'),
    register: static function (Registrar $registrar): void {
        unset($registrar);
        require __DIR__ . '/routes.php';
    },
    routeCache: __DIR__ . '/.route-cache/__routes.php',
);
```

Traits:

- single cache file
- simple artifact handling
- good fit for smaller route sets and immutable images

## GeneratedMatcher

Best for experiments, benchmarking, or environments where you do not want cache artifacts.

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: GeneratedMatcher::make(),
    register: static function (Registrar $registrar): void {
        unset($registrar);
        require __DIR__ . '/routes.php';
    },
);
```

Traits:

- no route-cache artifacts
- useful for isolated runtime comparisons
- usually not the first production choice

## Recommendation

- default to `ShardedMatcher`
- use `FusedMatcher` when a single cache file helps your deployment model
- use `GeneratedMatcher` when filesystem artifacts are a liability or irrelevant
