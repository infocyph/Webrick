CORS per-route override
=======================

Apply a global CORS policy, then override specific routes with a route-level middleware instance.

.. code:: php

   use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Facade\Router as Route;

   $preGlobal = [
       new CorsAndPoliciesMiddleware([
           'allow_origin' => ['https://app.example.com'],
           'allow_methods' => ['GET', 'POST', 'OPTIONS'],
           'allow_headers' => ['Content-Type', 'Authorization'],
           'max_age' => 600,
       ]),
   ];

   Route::get('/public/metrics', fn() => Response::json(['uptime' => 12345]), [
       'middleware' => [new CorsAndPoliciesMiddleware([
           'allow_origin' => ['*'],
           'allow_methods' => ['GET', 'OPTIONS'],
           'allow_headers' => ['*'],
           'max_age' => 60,
       ])],
   ]);
