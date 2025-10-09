# Trailing-slash normalization

Normalize URLs so `/about` and `/about/` resolve consistently. Choose one style (no trailing slash is common) and 301-redirect the other.

```php
<?php

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

$router = new RouterKernel();

// Redirect '/path/' → '/path' (unless it's root '/')
$router->use(function (Request $req, callable $next) {
    $uri = $req->uri()->getPath();
    if ($uri !== '/' && str_ends_with($uri, '/')) {
        $normalized = rtrim($uri, '/');
        $qs = $req->uri()->getQuery();
        if ($qs) {
            $normalized .= '?' . $qs;
        }
        return Response::redirect($normalized, 301);
    }
    return $next($req);
});

// Now define routes without trailing slashes
$router->get('/about', fn() => Response::plaintext('About'));
```
