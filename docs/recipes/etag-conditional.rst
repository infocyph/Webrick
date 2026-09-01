ETag & conditional requests
===========================

Use ``CacheValidatorsMiddleware`` early in the pipeline. It can short-circuit ``If-None-Match`` and ``If-Modified-Since`` before your handler does more work.

.. code:: php

   use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Facade\Router as Route;

   $preGlobal[] = new CacheValidatorsMiddleware(
       static function (Request $request): array {
           unset($request);

           $payload = json_encode(['version' => 1, 'shape' => 'stable'], JSON_UNESCAPED_SLASHES);
           $etag = '"' . hash('sha256', $payload) . '"';
           $lastModified = gmdate('D, d M Y H:i:s') . ' GMT';

           return [$etag, $lastModified];
       },
   );

   Route::get('/profile', fn() => Response::json(['user' => 'alice'])
       ->withHeader('Cache-Control', 'public, max-age=60'), 'profile');
