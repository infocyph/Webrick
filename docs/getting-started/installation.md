# Installation

This page gets you from zero to a working Webrick install, with guidance for both development and production (PHP-FPM). If you prefer containers, a ready-to-copy Docker setup is included at the end.

:::{admonition} Requirements
:class: tip

* **PHP:** 8.4+
* **Extensions:** `mbstring`, `json`, `zlib` (plus `brotli`/`zstd` if you want those compression codecs)
* **Composer:** latest stable
* **Web server (prod):** Nginx → Apache (optional) → PHP-FPM, or Nginx → PHP-FPM directly
  :::

---

## 1) Install via Composer

```bash
composer require infocyph/webrick
```

Ensure your project has PSR-4 autoloading configured (Composer’s default is fine). Your front controller (e.g., `public/index.php`) will bootstrap the router.

---

## 2) Project layout (suggested)

```
your-app/
├─ public/
│  └─ index.php          # front controller
├─ routes/
│  └─ web.php            # your routes
├─ src/                  # controllers, services
├─ var/
│  └─ cache/routes/      # route cache (sharded) or a single fused file
└─ vendor/
```

You can choose any layout; just keep the **route cache** in a writable path for your deploy user (e.g., `var/cache/routes`).

---

## 3) Environment keys (recommended)

Some features are opt-in and require keys:

* **URL signing** (temporary & signed URLs): `WEBRICK_SIGN_KEY`
* **Cookie encryption** (if enabling CookieEncryptionMiddleware): `WEBRICK_COOKIE_KEY`

Add them to your environment or `.env` (don’t commit secrets):

```bash
export WEBRICK_SIGN_KEY="change-this-signing-key"
export WEBRICK_COOKIE_KEY="change-this-cookie-key"
export WEBRICK_SIGN_TTL="900"     # default TTL for temporary URLs (seconds)
```

---

## 4) Minimal front controller

Create `public/index.php`:

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Response;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

$routesFile  = __DIR__ . '/../routes/web.php';
$routeCache  = __DIR__ . '/../var/cache/routes';   // directory (sharded mode)
$signKey     = $_ENV['WEBRICK_SIGN_KEY']  ?? 'dev-sign-key';
$defaultTtl  = (int)($_ENV['WEBRICK_SIGN_TTL'] ?? 900);

$kernel = RouterKernel::bootWithRegistrar(
  log: new NullLogger(),
  matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
  register: static function ($registrar) use ($routesFile) {
    require $routesFile; // define routes here
  },
  routeCache: $routeCache,
  registrarOptions: [
    'exposeUrlServices'   => true,
    'autoSlashRedirect'   => false,
    'signKey'             => $signKey,
    'signedDefaultTtl'    => $defaultTtl,
  ],
  preGlobal: [
    // Add hardening, limits, throttle, cookies, negotiation, response cache, validators...
  ],
  postGlobal: [
    // Add compression, CORS & policies, vary accumulator, response linter (in dev)...
  ],
  bindUrlServices: static function ($routes) use ($signKey, $defaultTtl) {
    Response::bindUrlServices($routes, $signKey, $defaultTtl);
  }
);

(new AutoEmitter())->emit($kernel->handle());
```

And a tiny `routes/web.php`:

```php
<?php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Response\Response;

Route::get('/', fn() => Response::json(['ok' => true, 'router' => 'webrick']), 'home');
```

---

## 5) Development server

You can use PHP’s built-in server for a quick local run:

```bash
php -S 127.0.0.1:8000 -t public
```

Then open [http://127.0.0.1:8000/](http://127.0.0.1:8000/) and you should see `{ "ok": true, "router": "webrick" }`.

---

## 6) Production (Nginx → PHP-FPM)

Below is a minimal Nginx config proxying directly to PHP-FPM. Adjust paths, users, and TLS as needed.

```nginx
server {
  listen 80;
  server_name example.com;

  root /var/www/your-app/public;
  index index.php;

  # Serve static assets directly
  location ~* \.(?:css|js|png|jpg|jpeg|gif|svg|webp|woff2?)$ {
    access_log off;
    expires 7d;
    add_header Cache-Control "public, no-transform";
    try_files $uri =404;
  }

  # Front controller for everything else
  location / {
    try_files $uri /index.php?$query_string;
  }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # or 127.0.0.1:9000
    fastcgi_read_timeout 120s;
  }

  # Security hardening (basic)
  add_header X-Content-Type-Options nosniff;
  add_header X-Frame-Options DENY;
  add_header Referrer-Policy strict-origin-when-cross-origin;
}
```

**File permissions:** ensure the deploy user and PHP-FPM worker can write to `var/cache/routes/`.

---

## 7) Route cache (highly recommended)

Warm up cache during CI/CD (Ahead-Of-Time), then deploy artifacts:

```php
<?php
// scripts/build-route-cache.php
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

RouteCache::build([
  'cache'   => __DIR__ . '/../var/cache/routes',  // sharded dir
  'routes'  => __DIR__ . '/../routes/web.php',
  'logger'  => new NullLogger(),
  'registrarOptions' => [
    'exposeUrlServices' => true,
  ],
]);
echo "Route cache built.\n";
```

Run in CI:

```bash
php scripts/build-route-cache.php
```

> For very small apps you can also use **fused** (single-file) caching; we’ll cover detailed trade-offs in **Deployments → Route Cache & Warmup**.

---

## 8) Optional: enable compression

Add `CompressionMiddleware` to **post-global**. It negotiates `zstd`, `br`, `gzip` automatically (when codecs are available) and coordinates ETags correctly.

```php
$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  // ...
];
```

> Ensure your PHP runtime has the relevant compression libraries; otherwise it will gracefully fall back.

---

## 9) Optional: cookie encryption

If you want encrypted cookies, wire the middleware and set `WEBRICK_COOKIE_KEY`.

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\CookieEncryptionMiddleware(
  $_ENV['WEBRICK_COOKIE_KEY'] ?? 'dev-cookie-key'
);
```

---

## 10) Docker (handy starter)

`Dockerfile`:

```dockerfile
FROM php:8.4-fpm-alpine

# system deps
RUN apk add --no-cache nginx supervisor bash zlib-dev

# php extensions (zlib; add brotli/zstd if using relevant pecl packages)
RUN docker-php-ext-install opcache

# workdir
WORKDIR /app

# nginx config
COPY ./.docker/nginx.conf /etc/nginx/http.d/default.conf

# app
COPY . /app

# perms
RUN mkdir -p /app/var/cache/routes && chown -R www-data:www-data /app/var

# supervisor to run both php-fpm & nginx
COPY ./.docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
```

`./.docker/nginx.conf` (serve `/app/public`):

```nginx
server {
  listen 80;
  server_name _;

  root /app/public;
  index index.php;

  location / {
    try_files $uri /index.php?$query_string;
  }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass 127.0.0.1:9000;
  }
}
```

`./.docker/supervisord.conf`:

```ini
[supervisord]
nodaemon=true

[program:php-fpm]
command=php-fpm
autorestart=true

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
```

---

## Troubleshooting

* **404 on all routes:** check `try_files` and that `public/index.php` exists; ensure `SCRIPT_FILENAME` is correct.
* **“Permission denied” on route cache:** fix ownership/permissions for `var/cache/routes/`.
* **Compression not applied:** verify codecs/extensions; check `Accept-Encoding` header from the client.
* **Signed/temporary URL invalid:** confirm `WEBRICK_SIGN_KEY`, TTL, and the route name/params used for signing.


