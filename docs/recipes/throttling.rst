Throttling patterns
===================

For most apps, register ``ThrottleMiddleware`` once and reuse it through aliases.

.. code:: php

   use Infocyph\Webrick\Middleware\ThrottleMiddleware;
   use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
   use Infocyph\Webrick\Router\Facade\Router as Route;

   MiddlewareAliases::register(
       'throttle',
       static fn(...$params) => new ThrottleMiddleware(
           max: (int) ($params[0] ?? 60),
           window: (int) ($params[1] ?? 60),
       ),
   );

   Route::get('/api/ping', fn() => Response::json(['ok' => true]), [
       'middleware' => ['throttle:100,60'],
   ]);
