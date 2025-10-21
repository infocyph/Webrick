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


---

## Configuration
```php
use Infocyph\Webrick\Middleware\CompressionMiddleware;

$postGlobal[] = new CompressionMiddleware(
    minBytes: 1400,                     // Min response size to compress (MTU-friendly)
    prefOrder: ['zstd', 'br', 'gzip'],  // Preference order (best to worst)
    etagMode: CompressionMiddleware::ETAG_WEAK_ON_ENCODE,  // ETag strategy
    gzipLevel: 6,                       // gzip compression level (1-9)
    brotliQuality: 4,                   // Brotli quality (0-11)
    zstdLevel: 3,                       // Zstd level (1-22)
    etagDeriveSalt: 'enc-v1',          // Salt for ETAG_STRONG_DERIVE mode
    maxBufferBytes: 8_388_608,         // Max body size to buffer (8MB ceiling)
    excludeTypes: [],                   // Additional MIME types to skip
    onlyTypes: [],                      // Whitelist MIME types (empty = allow all)
    forceAddVary: true                  // Always add Vary: Accept-Encoding
);
```

### Constructor Parameters

| Parameter        | Type            | Default           | Description                                                   |
| ---------------- | --------------- | ----------------- | ------------------------------------------------------------- |
| `minBytes`       | `int`           | `1400`            | Minimum response size to compress (bytes)                     |
| `prefOrder`      | `array<string>` | `['zstd','br','gzip']` | Preference order for encodings                                |
| `etagMode`       | `int`           | `ETAG_WEAK_ON_ENCODE` | ETag handling strategy (see below)                            |
| `gzipLevel`      | `int`           | `6`               | gzip compression level (1=fast, 9=best compression)           |
| `brotliQuality`  | `int`           | `4`               | Brotli quality (0=fast, 11=best compression)                  |
| `zstdLevel`      | `int`           | `3`               | Zstd compression level (1=fast, 22=best)                      |
| `etagDeriveSalt` | `string`        | `'enc-v1'`        | Salt for deriving ETags in STRONG_DERIVE mode                 |
| `maxBufferBytes` | `int`           | `8388608`         | Safety ceiling for buffering responses (8MB)                  |
| `excludeTypes`   | `array<string>` | `[]`              | MIME types to never compress (in addition to defaults)        |
| `onlyTypes`      | `array<string>` | `[]`              | If set, ONLY compress these types                             |
| `forceAddVary`   | `bool`          | `true`            | Always add `Vary: Accept-Encoding` even if not compressed     |

---

## ETag Strategies

The middleware supports three ETag handling modes:

### 1. `ETAG_WEAK_ON_ENCODE` (Default - Safest)

**Behavior**:
- Converts strong ETags to weak: `"abc123"` → `W/"abc123"`
- Synthesizes weak ETag if none exists
- Safe for all scenarios

**Use when**: You want simple, safe ETag handling without extra CPU cost.
```php
new CompressionMiddleware(
    etagMode: CompressionMiddleware::ETAG_WEAK_ON_ENCODE
);
```

**Example**:
```
Original:  ETag: "abc123"
Encoded:   ETag: W/"abc123"
           Content-Encoding: br
```

### 2. `ETAG_STRONG_RECOMP` (Accurate but Slower)

**Behavior**:
- Recomputes strong ETag from **compressed bytes**
- Uses SHA-256 hash of encoded body
- Provides strong validator for encoded response

**Use when**: You need strong validators for compressed responses and can afford CPU cost.
```php
new CompressionMiddleware(
    etagMode: CompressionMiddleware::ETAG_STRONG_RECOMP
);
```

**Example**:
```
Original:  ETag: "abc123"
Encoded:   ETag: "def456"  (hash of compressed bytes)
           Content-Encoding: br
```

**Trade-off**: Adds ~1-2ms per response (hashing cost).

### 3. `ETAG_STRONG_DERIVE` (Deterministic)

**Behavior**:
- Derives strong ETag deterministically: `hash(baseETag + algo + level + salt)`
- Only works for deterministic encodings (brotli, zstd)
- **Skips gzip** (MTIME in header makes it non-deterministic)

**Use when**: You want strong ETags without re-hashing, and you use brotli/zstd only.
```php
new CompressionMiddleware(
    etagMode: CompressionMiddleware::ETAG_STRONG_DERIVE,
    etagDeriveSalt: 'myapp-v2'  // Change when algorithm/level changes
);
```

**Example**:
```
Original:  ETag: "abc123"
Derived:   ETag: "xyz789"  (deterministic: sha256("abc123:br:4:myapp-v2"))
           Content-Encoding: br
```

**Important**: Change `etagDeriveSalt` when you:
- Upgrade compression library versions
- Change `brotliQuality` or `zstdLevel`
- Otherwise clients' cached ETags won't match

---

## Compression Algorithm Selection

### zstd (Recommended)

**Pros**:
- ✅ 2-3x faster than gzip
- ✅ Better compression ratio than gzip
- ✅ Lower CPU usage
- ✅ Deterministic (works with STRONG_DERIVE)

**Cons**:
- ⚠️ Requires PHP extension: `pecl install zstd`
- ⚠️ Older clients may not support

**Use for**: Modern applications (2020+), API responses
```php
new CompressionMiddleware(
    prefOrder: ['zstd', 'br'],  // Prefer zstd, fallback to brotli
    zstdLevel: 3                // Fast and efficient
);
```

### Brotli (br)

**Pros**:
- ✅ Best compression ratio
- ✅ Widely supported (all modern browsers)
- ✅ Deterministic

**Cons**:
- ⚠️ Slower than zstd/gzip at high quality
- ⚠️ Requires extension: `pecl install brotli`

**Use for**: Static assets, CDN-backed responses
```php
new CompressionMiddleware(
    prefOrder: ['br', 'gzip'],
    brotliQuality: 4  // Balance speed/ratio (0-11 scale)
);
```

### gzip (Fallback)

**Pros**:
- ✅ Universal support (all clients)
- ✅ Built into PHP (zlib)
- ✅ Battle-tested

**Cons**:
- ❌ Slower than zstd
- ❌ Worse compression than brotli
- ❌ Non-deterministic (MTIME in header)

**Use for**: Maximum compatibility, legacy clients
```php
new CompressionMiddleware(
    prefOrder: ['gzip'],
    gzipLevel: 6  // Good balance (1-9 scale)
);
```

---

## Performance Tuning

### Fast & Efficient (Default)
```php
new CompressionMiddleware(
    minBytes: 1400,           // 1 MTU packet
    prefOrder: ['zstd', 'br', 'gzip'],
    zstdLevel: 3,             // Fast
    brotliQuality: 4,         // Medium
    gzipLevel: 6,
    etagMode: CompressionMiddleware::ETAG_WEAK_ON_ENCODE
);
```

**Result**: ~95% size reduction, ~2ms overhead

### Maximum Compression (CDN/Static)
```php
new CompressionMiddleware(
    minBytes: 512,            // Compress small responses
    prefOrder: ['br', 'zstd', 'gzip'],
    brotliQuality: 11,        // Best compression
    zstdLevel: 19,            // High compression
    gzipLevel: 9,
    etagMode: CompressionMiddleware::ETAG_STRONG_DERIVE,
    etagDeriveSalt: 'cdn-v1'
);
```

**Result**: ~98% size reduction, ~10-20ms overhead (acceptable for CDN caching)

### Speed Priority (High Traffic)
```php
new CompressionMiddleware(
    minBytes: 2048,           // Only large responses
    prefOrder: ['zstd'],      // Fastest only
    zstdLevel: 1,             // Fastest
    etagMode: CompressionMiddleware::ETAG_WEAK_ON_ENCODE
);
```

**Result**: ~85% size reduction, <1ms overhead

---

## What Gets Compressed

### Always Compressed (Defaults)

- `text/plain`
- `text/html`
- `text/css`
- `text/javascript`, `application/javascript`
- `application/json`
- `application/xml`, `text/xml`
- `application/xhtml+xml`
- `image/svg+xml`

### Never Compressed (Defaults)

- Already compressed: `image/jpeg`, `image/png`, `image/gif`, `image/webp`
- Video: `video/*`
- Audio: `audio/*`
- Archives: `application/zip`, `application/gzip`, `application/x-bzip2`
- Fonts (already compressed): `font/woff2`

### Custom Exclusions
```php
new CompressionMiddleware(
    excludeTypes: [
        'application/pdf',           // Already compressed
        'application/octet-stream'   // Unknown binary
    ]
);
```

### Whitelist Mode
```php
new CompressionMiddleware(
    onlyTypes: [
        'application/json',
        'text/html'
    ]  // ONLY compress these two types
);
```


### Tips
- Avoid double compression at the reverse proxy. Disable Nginx `gzip`/Apache `mod_deflate` when the app handles it.
- If you must compress at the proxy, disable this middleware to prevent double-encoding.
