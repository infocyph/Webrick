# Streaming downloads

Send large files as a stream and **disable proxy buffering** end-to-end. Also prefer a single compression source (proxy *or* app).

```php
<?php

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

$router = new RouterKernel();

$router->get('/download/:name', function (Request $req, array $args) {
    $name = basename($args['name']);
    $path = __DIR__ . '/files/' . $name;

    if (!is_file($path)) {
        return Response::plaintext('Not found', 404);
    }

    $fh = fopen($path, 'rb');

    return Response::stream($fh, 200, [
        'Content-Type' => 'application/octet-stream',
        'Content-Disposition' => 'attachment; filename="' . $name . '"',
        // Proxy hints
        'X-Accel-Buffering' => 'no',    // nginx: disable proxy buffering
        'Cache-Control' => 'private, no-transform',
    ]);
});
```
**Nginx tip:** `proxy_buffering off;` for the location that proxies PHP-FPM when streaming endpoints are hit.
