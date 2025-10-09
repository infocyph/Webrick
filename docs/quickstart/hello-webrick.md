# Hello Webrick

A minimal, runnable example showing **RouterKernel**, two routes, and a JSON response.

> Works with PHP 8.3+ (target: 8.4). Assumes Composer autoload and your `Infocyph\Webrick` library installed.

## Directory layout

```
examples/hello-webrick/
├─ composer.json
├─ public/
│  └─ index.php
└─ README.md
```

## composer.json (example)

```json
{
  "name": "infocyph/hello-webrick",
  "type": "project",
  "require": {
    "php": ">=8.3",
    "infocyph/webrick": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

## public/index.php (example)

```php
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

require __DIR__ . '/../vendor/autoload.php';

$router = new RouterKernel();

// GET / → plaintext hello
$router->get('/', function (Request $req) {
    return Response::plaintext('Hello Webrick!');
});

// GET /api/ping → JSON
$router->get('/api/ping', function (Request $req) {
    return Response::json([
        'ok' => true,
        'message' => 'pong',
        'time' => gmdate('c'),
    ]);
});

// Dispatch
$router->run();
```

## Run locally

```
cd examples/hello-webrick
composer install
php -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080/ and http://127.0.0.1:8080/api/ping

## Notes

- The example uses `Response::plaintext()` and `Response::json()` helpers.
- Swap the `run()` method to your preferred emitter if your stack requires it.
```

