# HEAD handling

Serve `HEAD` by mirroring `GET` headers without a body. You can add a tiny middleware to convert `HEAD` into `GET`, capture the response, then strip the body.

```php
<?php

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

$router = new RouterKernel();

$router->use(function (Request $req, callable $next) {
    $isHead = strtoupper($req->method()) === 'HEAD';
    if ($isHead) {
        // Temporarily treat as GET
        $req = $req->withMethod('GET');
    }

    $res = $next($req);

    if ($isHead) {
        // Strip body for HEAD, keep headers & status
        return $res->withBody('')->withHeader('Content-Length', (string)0);
    }
    return $res;
});

$router->get('/info', fn() => Response::json(['name' => 'Webrick', 'ok' => true]));
```
