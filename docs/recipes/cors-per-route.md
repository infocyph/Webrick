# CORS per-route override

Use a default CORS middleware, then override **per route** for special cases.

```php
<?php

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware as CORS;

$router = new RouterKernel();

// Global default
$router->use(new CORS([
    'allow_origin' => ['https://app.example.com'],
    'allow_methods' => ['GET', 'POST', 'OPTIONS'],
    'allow_headers' => ['Content-Type', 'Authorization'],
    'max_age' => 600,
]));

// Route-level override
$router->get('/public/metrics', function () {
    return Response::json(['uptime' => 12345]);
})->with(new CORS([
    'allow_origin' => ['*'],
    'allow_methods' => ['GET', 'OPTIONS'],
    'allow_headers' => ['*'],
    'max_age' => 60,
]));
```
