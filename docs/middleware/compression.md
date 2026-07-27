# Compression Middleware

`CompressionMiddleware` negotiates `zstd`, `br`, `gzip`, or `deflate` from `Accept-Encoding` and adds `Vary: Accept-Encoding` correctly.

## Recommended placement

Register it in `postGlobal` so your handler and earlier middleware finish setting status, headers, validators and content type first.

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        unset($registrar);
        require __DIR__ . '/routes.php';
    },
    preGlobal: [
        // validators, throttle, sanitize, negotiation...
    ],
    postGlobal: [
        \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
    ],
);
```

## Configuration

```php
use Infocyph\Webrick\Middleware\CompressionMiddleware;

$postGlobal[] = new CompressionMiddleware(
    minBytes: 1400,
    prefOrder: ['zstd', 'br', 'gzip'],
    etagMode: CompressionMiddleware::ETAG_WEAK_ON_ENCODE,
    gzipLevel: 6,
    brotliQuality: 4,
    zstdLevel: 3,
    etagDeriveSalt: 'enc-v1',
    maxBufferBytes: 8_388_608,
    excludeTypes: [],
    onlyTypes: [],
    forceAddVary: true,
);
```

## Notes

- Prefer one compression layer: either Webrick or your proxy, not both.
- Pair this with `VaryAccumulatorMiddleware` when other middleware also contributes to `Vary`.
- Keep validators before compression so ETag behavior stays coherent.
