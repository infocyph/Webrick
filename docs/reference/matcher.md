
# Matcher

Webrick provides two production-grade matchers. Choose one per deployment.

## ShardedMatcher (directory cache)

**Best for:** many routes, frequent deploys, good OPcache locality, easy partial clears.

```php
use Infocyph\Webrick\Router\Matcher\ShardedMatcher;
use Infocyph\Webrick\Router\RouterKernel;

$matcher = new ShardedMatcher(__DIR__.'/var/route-cache'); // directory
$kernel  = RouterKernel::bootWithRegistrar(
    matcher: $matcher,
    registrar: require __DIR__.'/routes.php',
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
use Infocyph\Webrick\Router\Matcher\FusedMatcher;
use Infocyph\Webrick\Router\RouterKernel;

$matcher = new FusedMatcher(__DIR__.'/var/route-cache.php'); // single file
$kernel  = RouterKernel::bootWithRegistrar(
    matcher: $matcher,
    registrar: require __DIR__.'/routes.php',
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

## Choosing

- **Large apps** → start with **ShardedMatcher** for graceful hot/warm behavior.
- **Simple services / serverless** → **FusedMatcher** reduces filesystem chatter.
- Both support the same registrar options and route semantics.
