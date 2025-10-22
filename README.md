# Webrick Router

A fast, modern PHP router with production-grade middleware, signed & temporary URLs, smart responses, and first-class route caching.

[![Security & Standards](https://github.com/infocyph/Webrick/actions/workflows/build.yml/badge.svg)](https://github.com/infocyph/Webrick/actions/workflows/build.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/Webrick?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FWebrick)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/Webrick)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/Webrick/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/Webrick)

---

## Highlights

- **🚀 Fast routing** – Named routes, groups, domains, resources, attribute discovery
- **🔒 Signed URLs** – Built-in URL signing with expiration and verification middleware
- **⚙️ Production middleware** – 15+ battle-tested middleware for security, caching, compression, CORS
- **📦 Smart responses** – Auto content negotiation, streaming, downloads, JSON helpers
- **💾 Route caching** – Sharded or fused cache for instant cold starts
- **🔌 Sudo PSR-compatible** – Works with PSR-7/15/17 ecosystems

> **Requirements:** PHP 8.4+ | Production-ready with OPcache + route caching

---

## Installation
```bash
composer require infocyph/webrick
```

---

## Quick Start

### Minimal Example
```php
<?php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Response\Response;

require __DIR__ . '/../vendor/autoload.php';

$router = new RouterKernel();

$router->get('/', fn() => Response::plaintext('Hello Webrick!'));
$router->get('/api/users/{id:int}', fn($r, int $id) => 
    Response::json(['id' => $id, 'name' => 'John Doe'])
);

$router->run();
```

### Production Setup
```php
<?php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Response\Response;

$kernel = RouterKernel::bootWithRegistrar(
    register: function (Registrar $r) {
        $route = $r->facade();
        
        $route::get('/users', [UserController::class, 'index'], 'users.index');
        $route::post('/users', [UserController::class, 'store'], 'users.store');
        
        $route::get('/protected', fn() => Response::json(['secret' => 'data']))
            ->middleware(['auth', 'verifySignedUrl']);
    },
    routeCache: __DIR__ . '/../var/cache/routes',
    preGlobal: [
        \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
        \Infocyph\Webrick\Middleware\ThrottleMiddleware::class,
        \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
    ],
    registrarOptions: [
        'exposeUrlServices' => true,
        'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'change-me',
    ]
);

(new \Infocyph\Webrick\Response\Emitter\AutoEmitter())->emit(
    $kernel->handle(\Infocyph\Webrick\Request\Request::capture())
);
```

**Run locally:**
```bash
php -S 127.0.0.1:8080 -t public
```

---

## Core Features

### Named Routes & URL Generation
```php
$route::get('/users/{id:int}', $handler, 'users.show');
$route::post('/users', $handler, 'users.store');

// Generate URLs
$url = Response::urlFor('users.show', ['id' => 42]); // /users/42
$absolute = Response::urlFor('users.show', ['id' => 42], absolute: true);
```

### Signed URLs (Tamper-Proof)
```php
// Generate signed URL
$signed = Response::signedUrlFor('download', ['file' => 'report.pdf']);
$temp = Response::temporaryUrlFor('download', ['file' => 'doc.pdf'], 3600); // 1 hour

// Protect route
$route::get('/download/{file}', $handler)
    ->middleware([\Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware::class]);
```

### Route Groups
```php
$route::group(prefix: '/api', middleware: ['auth', 'throttle:120,60'], callback: function() use ($route) {
    $route::get('/users', [UserController::class, 'index']);
    $route::post('/users', [UserController::class, 'store']);
});
```

### Attribute Routes
```php
use Infocyph\Webrick\Router\Definition\Attribute\Get;

final class UserController {
    #[Get('/users/{id:int}', name: 'users.show')]
    public function show(int $id): Response {
        return Response::json(['id' => $id]);
    }
}

// Register in routes
AttributeRouteLoader::registerFromDirs($registrar, [
    'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
]);
```

### Smart Responses
```php
// JSON
Response::json(['status' => 'ok']);

// Auto negotiation (based on Accept header)
Response::auto($request, ['data' => $items]);

// Downloads
Response::download('/path/to/file.pdf', 'report.pdf');

// Streaming
Response::stream(function() {
    while ($data = getNextChunk()) {
        echo json_encode($data) . "\n";
        flush();
    }
});

// Redirects
Response::redirect('/login');
Response::redirectToRoute('users.show', ['id' => 42]);
```

---

## Built-in Middleware

| Middleware | Purpose |
|------------|---------|
| **GatewayHardeningMiddleware** | Security headers, host validation, IP filtering |
| **ThrottleMiddleware** | Rate limiting per IP/user |
| **NegotiationMiddleware** | Content negotiation (Accept, Accept-Language) |
| **CorsAndPoliciesMiddleware** | CORS + security policies (CSP, HSTS) |
| **CompressionMiddleware** | Response compression (gzip, brotli, zstd) |
| **CookieEncryptionMiddleware** | Transparent cookie encryption |
| **ResponseCacheMiddleware** | HTTP response caching |
| **VerifySignedUrlMiddleware** | Signed URL verification |
| **TelemetryMiddleware** | Request logging and W3C trace context |

[View all middleware →](https://docs.infocyph.com/projects/webrick/en/latest/middleware/)

---

## Documentation

📚 **[Full Documentation](https://docs.infocyph.com/projects/webrick/en/latest/)**

### Getting Started
- [Installation & Setup](https://docs.infocyph.com/projects/webrick/en/latest/getting-started/installation.html)
- [Quick Start Guide](https://docs.infocyph.com/projects/webrick/en/latest/getting-started/quick-start.html)
- [Basic Routing](https://docs.infocyph.com/projects/webrick/en/latest/guides/routing.html)

### Guides
- [Routing](https://docs.infocyph.com/projects/webrick/en/latest/guides/routing.html) – Routes, groups, parameters, constraints
- [Requests](https://docs.infocyph.com/projects/webrick/en/latest/guides/requests.html) – Reading input, headers, files
- [Responses](https://docs.infocyph.com/projects/webrick/en/latest/guides/responses.html) – JSON, redirects, downloads, streaming
- [Middleware](https://docs.infocyph.com/projects/webrick/en/latest/middleware/) – Using and creating middleware
- [Signed URLs](https://docs.infocyph.com/projects/webrick/en/latest/guides/urls.html) – Secure, expiring URLs
- [Route Caching](https://docs.infocyph.com/projects/webrick/en/latest/advanced/route-caching.html) – Production optimization

### Recipes
- [JWT Authentication](https://docs.infocyph.com/projects/webrick/en/latest/recipes/authentication.html)
- [RESTful API](https://docs.infocyph.com/projects/webrick/en/latest/recipes/api.html)
- [File Uploads](https://docs.infocyph.com/projects/webrick/en/latest/recipes/file-upload.html)
- [Caching Strategies](https://docs.infocyph.com/projects/webrick/en/latest/recipes/caching.html)
- [Rate Limiting](https://docs.infocyph.com/projects/webrick/en/latest/recipes/rate-limiting.html)

### Deployment
- [Nginx Configuration](https://docs.infocyph.com/projects/webrick/en/latest/deployments/nginx.html)
- [Docker & Kubernetes](https://docs.infocyph.com/projects/webrick/en/latest/deployments/containers.html)
- [Performance Tuning](https://docs.infocyph.com/projects/webrick/en/latest/deployments/tuning.html)

---

## Performance

**Route Caching** dramatically improves cold-start performance:
```bash
# Build route cache (run in CI/deployment)
php bin/build-route-cache.php

# Result: ~100ms faster first request
```

**Production tips:**
- ✅ Enable OPcache with `opcache.validate_timestamps=0`
- ✅ Prebuild route cache in CI/CD
- ✅ Use persistent cache backend (Redis/APCu) for middleware
- ✅ Tune PHP-FPM pools (`pm.max_children`, `pm.start_servers`)

[Performance Guide →](https://docs.infocyph.com/projects/webrick/en/latest/deployments/overview.html)

---

## Testing
```bash
# Run tests
composer test

# Code formatting
composer format

# Static analysis
composer analyse
```

---

## Contributing

Contributions welcome! Please:

1. Fork and create a feature branch
2. Write tests for new features
3. Run `composer test` and `composer format`
4. Submit a PR with clear description

[Contributing Guide →](./CONTRIBUTING.md)

---

## Security

If you discover a security vulnerability, please email the maintainer privately:

**security@infocyph.com**

Do not open public issues for security concerns.

---

## Roadmap

- [x] Route caching (sharded + fused)
- [x] Signed & temporary URLs
- [x] 15+ production middleware
- [x] Attribute routing
- [ ] OpenTelemetry integration
- [ ] WebSocket adapter
- [ ] GraphQL routing helpers

---

## License

[MIT](./LICENSE) © [Infocyph](https://github.com/infocyph)

---

## Links

- **Documentation:** https://docs.infocyph.com/projects/webrick/en/latest/
- **Packagist:** https://packagist.org/packages/infocyph/webrick
- **Issues:** https://github.com/infocyph/webrick/issues
- **Changelog:** [CHANGELOG.md](./CHANGELOG.md)
