# HEAD handling

If you want explicit `HEAD` normalization, place a small middleware in `preGlobal` that converts `HEAD` to `GET`, then strips the body from the response.

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router as Route;

$preGlobal[] = static function (Request $request, callable $next): Response {
    $isHead = strtoupper($request->getMethod()) === 'HEAD';

    if ($isHead) {
        $request = $request->withMethod('GET');
    }

    $response = $next($request);

    if (! $isHead) {
        return $response;
    }

    return $response
        ->withBody(new Infocyph\Webrick\Request\Core\Stream(''))
        ->withHeader('Content-Length', '0');
};

Route::get('/info', fn() => Response::json(['name' => 'Webrick', 'ok' => true]), 'info');
```
