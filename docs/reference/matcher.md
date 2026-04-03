# Matcher

Webrick provides three matcher modes. Choose one per deployment/runtime profile.

## ShardedMatcher (directory cache)

**Best for:** many routes, frequent deploys, good OPcache locality, easy partial clears.

```php
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Log\NullLogger;

$matcher = ShardedMatcher::make(__DIR__.'/.route-cache'); // directory
$kernel  = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: $matcher,
    register: require __DIR__.'/routes.php',
    registrarOptions: [
        'autoSlashRedirect' => true,
        'exposeUrlServices' => true,
        'signKey'           => getenv('WEBRICK_SIGN_KEY') ?: 'dev-key',
        'signedDefaultTtl'  => 300,
        'fallbackAliasesFromRegistrar' => true,
    ]
);
```

**Traits**
- Writes many small PHP files (per-shard) under the cache directory.
- Great with OPcache: shards stay hot and invalidate independently.
- Easy to clear incrementally by removing specific shard files.

## FusedMatcher (single-file cache)

**Best for:** small/medium route sets, simpler deployment artifact, ultra-fast startup.

```php
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Log\NullLogger;

$matcher = FusedMatcher::make(__DIR__.'/.route-cache/__routes.php'); // single file
$kernel  = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: $matcher,
    register: require __DIR__.'/routes.php',
    registrarOptions: [
        'autoSlashRedirect' => true,
        'exposeUrlServices' => true,
        'signKey'           => getenv('WEBRICK_SIGN_KEY') ?: 'dev-key',
        'signedDefaultTtl'  => 300,
        'fallbackAliasesFromRegistrar' => true,
    ]
);
```

**Traits**
- Emits one consolidated cache file.
- Maximum OPcache affinity; trivial to ship in image artifacts.
- Easy to wipe by deleting a single file.

## GeneratedMatcher (in-memory generated table)

**Best for:** benchmarking or runtime scenarios where you do not want filesystem cache artifacts.

```php
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Log\NullLogger;

$matcher = GeneratedMatcher::make();
$kernel  = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: $matcher,
    register: require __DIR__.'/routes.php',
);
```

**Traits**
- No route-cache file/directory output.
- Useful for controlled environments and matcher comparison.

## Choosing

- **Large apps** → start with **ShardedMatcher** for graceful hot/warm behavior.
- **Simple services / serverless** → **FusedMatcher** reduces filesystem chatter.
- **No filesystem cache requirement** → **GeneratedMatcher**.

## Use Cases By Matcher

### ShardedMatcher
- Use when route count is high and deploys are frequent.
- Use when you want better OPcache locality and shard-level invalidation.
- Good fit for long-running PHP-FPM workers on VM/container hosts.
- Avoid when you need a single cache artifact only.

### FusedMatcher
- Use when you want one cache file that is easy to build, ship and swap.
- Good fit for read-only runtime images where cache is prebuilt in CI.
- Good fit for small/medium route sets and simple deployment pipelines.
- Avoid when route cache churn is high and you need partial invalidation.

### GeneratedMatcher
- Use for benchmarking, ephemeral CI or environments where filesystem cache writes are undesirable.
- Use when you want deterministic in-process matcher generation without cache artifacts.
- Good fit for local experiments and controlled runtime comparisons.
- Avoid as default for production workloads that benefit from persistent warmed route cache.
