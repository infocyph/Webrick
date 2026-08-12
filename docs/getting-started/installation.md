# Installation

This page gets you from zero to a working Webrick install. The router targets **PHP 8.4+** and ships as a Composer library.

:::{admonition} Requirements
:class: tip

* **PHP:** 8.4+
* **Extensions:** `mbstring`, `json`, `zlib` (optionally `brotli`/`zstd` if you want those codecs)
* **Composer:** latest stable
* **Web server (prod):** Nginx → PHP-FPM (Apache optional in front)
:::

---

## 1) Install via Composer

```bash
composer require infocyph/webrick
```

This will install the core package and its dependencies (`infocyph/arraykit`, `infocyph/intermix`).

---

## 2) Minimal project layout

You can choose any layout; just keep the **route cache** path writable by your deploy user.

```
your-app/
├─ public/
│  └─ index.php              # front controller
├─ routes/
│  └─ web.php                # your routes (or use attribute routes)
├─ src/                      # controllers, services
├─ var/
│  └─ cache/routes/          # route cache (sharded dir) or a single fused file
└─ vendor/
```

---

## 3) Environment keys

Set secrets via environment variables or your config layer:

- **`WEBRICK_SIGN_KEY`**: secret used for signed/temporary URLs. Any reasonably long random string is fine; rotate if leaked.
- **`WEBRICK_SIGN_TTL`**: default TTL (seconds) for temporary URLs, e.g., `900`.
- **`WEBRICK_COOKIE_KEYS`** (optional): a comma‑separated list of **32‑byte raw keys** for cookie encryption (AES‑256‑GCM). The **index** in the list is the active Key ID (KID) you pass to the middleware.

Example `.env`:

```text
WEBRICK_SIGN_KEY="base64:KJ9r...replace_me...kQ="
WEBRICK_SIGN_TTL=900
WEBRICK_COOKIE_KEYS="hex:001122... (32 bytes),hex:8899aa... (32 bytes)"
```

> Cookie encryption is optional. If you enable it, supply at least one 32‑byte key; add more for rotation.

---

## 4) Web server setup (production)

Use your preferred stack. A typical Nginx → PHP‑FPM setup serves static assets directly and forwards everything else to the front controller.

**Key points**

- Ensure your front controller (e.g., `public/index.php`) receives all non‑existing paths.
- Do **not** enable double compression at the edge if you use Webrick’s **CompressionMiddleware**.
- Make the **route cache** path (`.route-cache` or fused file) writable during deploys.

See **Deployments → Nginx/Apache/PHP‑FPM** for copy‑paste configs and tuning.


---

## 5) Troubleshooting Installation

### Common Issues

#### Extension Not Found

**Error**: `PHP Fatal error: Uncaught Error: Call to undefined function zstd_compress()`

**Solution**:
```bash
# Install zstd extension
pecl install zstd

# Enable in php.ini
echo "extension=zstd.so" >> /etc/php/8.4/cli/php.ini
echo "extension=zstd.so" >> /etc/php/8.4/fpm/php.ini

# Restart PHP-FPM
systemctl restart php8.4-fpm
```

#### Composer Memory Limit

**Error**: `Fatal error: Allowed memory size exhausted`

**Solution**:
```bash
# Increase memory limit for this command
php -d memory_limit=-1 /usr/local/bin/composer install
```

#### Permission Denied on Cache Directory

**Error**: `Unable to create directory .route-cache`

**Solution**:
```bash
# Create directory with correct permissions
mkdir -p .route-cache
chown -R www-data:www-data .route-cache
chmod -R 775 .route-cache

# Or for development
chmod -R 777 .route-cache  # ⚠️ Dev only!
```

#### Autoload Not Working

**Error**: `Class 'Infocyph\Webrick\Router\Kernel\RouterKernel' not found`

**Solution**:
```bash
# Regenerate autoload
composer dump-autoload

# Or reinstall
rm -rf vendor/
composer install
```

#### Route Cache Build Fails

**Error**: `Route cache build failed - check for conflicts`

**Causes**:
- Duplicate route names
- Invalid parameter constraints
- Syntax errors in route files

**Debug**:
```bash
# Run with verbose output
php ./webrick route:cache --cache=.route-cache --routes=routes.php

# Check for duplicate names
grep -r "->withName(" routes/ | sort | uniq -d

# Validate route syntax
php -l routes.php
```

---

## 6) Verify Installation

Create a simple test script:
```php
<?php
// test-install.php

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

echo "Testing Webrick installation...\n\n";

// Test 1: Kernel boots
try {
    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger(),
        matcher: ShardedMatcher::make(),
        register: static function (Registrar $r): void {
            unset($r);

            \Infocyph\Webrick\Router\Facade\Router::get(
                '/test',
                fn() => Response::plaintext('OK', 200),
            );
        },
        routeCache: __DIR__ . '/.route-cache',
    );
    echo "✅ Kernel boots successfully\n";
} catch (Throwable $e) {
    echo "❌ Kernel boot failed: {$e->getMessage()}\n";
    exit(1);
}

echo "✅ Route registration works\n";

// Test 3: Request handling
try {
    $request = Request::fake(method: 'GET', uri: '/test');
    $response = $kernel->handle($request);

    if ($response->getStatusCode() === 200 && (string)$response->getBody() === 'OK') {
        echo "✅ Request handling works\n";
    } else {
        echo "❌ Request handling failed: unexpected response\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "❌ Request handling failed: {$e->getMessage()}\n";
    exit(1);
}

// Test 4: Response helpers
try {
    $json = Response::json(['test' => true]);
    if ($json->getHeaderLine('Content-Type') === 'application/json; charset=UTF-8') {
        echo "✅ Response helpers work\n";
    } else {
        echo "❌ Response helpers failed: wrong content-type\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "❌ Response helpers failed: {$e->getMessage()}\n";
    exit(1);
}

// Test 5: Compression available
$compressionAvailable = [];
if (function_exists('zstd_compress')) {
    $compressionAvailable[] = 'zstd';
}
if (function_exists('brotli_compress')) {
    $compressionAvailable[] = 'brotli';
}
if (function_exists('gzencode')) {
    $compressionAvailable[] = 'gzip';
}

if (!empty($compressionAvailable)) {
    echo "✅ Compression available: " . implode(', ', $compressionAvailable) . "\n";
} else {
    echo "⚠️  No compression extensions found (optional)\n";
}

echo "\n✨ All tests passed! Webrick is ready.\n";
```

Run the test:
```bash
php test-install.php
```

Expected output:
```
Testing Webrick installation...

✅ Kernel boots successfully
✅ Route registration works
✅ Request handling works
✅ Response helpers work
✅ Compression available: zstd, brotli, gzip

✨ All tests passed! Webrick is ready.
```

---

## 7) Next Steps

- 📖 Read the [Quick Start Guide](./quickstart.md) for a full example
- 🚀 Check [Deployments](../deployments/index.md) for production setup
- 🔧 Review [Middleware](../middleware/index.md) for available components
- 📚 Explore [Guides](../guides/index.md) for common patterns
