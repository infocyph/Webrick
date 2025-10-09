# Compression Middleware

Negotiates and encodes responses with `zstd`, `br`, `gzip`, or `deflate` based on the request's `Accept-Encoding`.
Adds `Vary: Accept-Encoding` and coordinates with cache validators for correct ETag behavior.

## What it does
- Picks the best supported encoding for the client.
- Skips encoding for already-compressed or too-small bodies.
- Uses **weak ETags** for encoded variants or recomputes strong ETags when required.
- Streams large bodies without buffering when possible.

## Usage

Register it in **post-global** middleware so headers are set by your handler first:

```php
$kernel = RouterKernel::bootWithRegistrar(
    matcher: ShardedMatcher::make(),
    registrar: require __DIR__.'/routes.php',
    preGlobal: [
        // ...validators, throttle, sanitize...
    ],
    postGlobal: [
        \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
        // CORS/Policies, VaryAccumulator, DevLinter ...
    ]
);
```

### Tips
- Avoid double compression at the reverse proxy. Disable Nginx `gzip`/Apache `mod_deflate` when the app handles it.
- If you must compress at the proxy, disable this middleware to prevent double-encoding.
