# ETag & conditional requests

Short-circuit `If-None-Match` / `If-Modified-Since` with a cache validators middleware. Example shows a simple strong ETag from payload.

```php
<?php

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;

$router = new RouterKernel();

// Provider that returns [etag, lastModified] for the current request/resource
$provider = function (Request $r): array {
    // Example: calculate ETag from a known resource snapshot
    $payload = json_encode(['version' => 1, 'time' => 'fixed'], JSON_UNESCAPED_SLASHES);
    $etag = '"' . hash('sha256', $payload) . '"'; // strong ETag with quotes
    $lastModified = gmdate('D, d M Y H:i:s').' GMT';
    return [$etag, $lastModified];
};

$router->use(new CacheValidatorsMiddleware($provider));

$router->get('/profile', function () {
    $data = ['user' => 'alice', 'features' => ['a', 'b']];
    // Include validators if controller builds/changes payload dynamically
    return Response::json($data)
        ->withHeader('ETag', 'W/"fallback"') // optional fallback if provider absent
        ->withHeader('Cache-Control', 'public, max-age=60');
});
```
**Tip:** Let the middleware add `304 Not Modified` or `412 Precondition Failed` automatically when validators match.
