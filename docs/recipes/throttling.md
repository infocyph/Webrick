# Throttling patterns

Basic token-bucket style rate limit using any cache (APCu, Redis, etc.). Example below shows APCu for simplicity.

```php
<?php

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

$router = new RouterKernel();

// 100 requests / 60s per IP for /api/*
$router->use(function (Request $req, callable $next) {
    $path = $req->uri()->getPath();
    if (!str_starts_with($path, '/api/')) {
        return $next($req);
    }

    $ip = $req->ip();
    $key = 'ratelimit:' . $ip;
    $limit = 100;
    $ttl = 60;

    $now = time();
    $window = (int) floor($now / $ttl);
    $bucketKey = $key . ':' . $window;

    $count = apcu_exists($bucketKey) ? (int) apcu_fetch($bucketKey) : 0;
    if ($count >= $limit) {
        $retry = ($window + 1) * $ttl - $now;
        return Response::json([
            'error' => 'rate_limited',
            'retry_after' => $retry
        ], 429)->withHeader('Retry-After', (string) $retry);
    }

    apcu_store($bucketKey, $count + 1, $ttl + 1);
    return $next($req);
});

$router->get('/api/ping', fn() => Response::json(['ok' => true]));
```
**Tip:** Swap APCu with Redis for distributed environments; keep keys short and add a small jitter to avoid thundering herds.
