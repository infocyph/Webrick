Utilities Reference
===================

Utilities that are part of the active Webrick runtime shape.

RouteCache
----------

.. code:: php

   use Infocyph\Webrick\Support\RouteCache;

   RouteCache::build([
       'matcher' => 'sharded',
       'cache' => __DIR__ . '/.route-cache',
       'routes' => __DIR__ . '/routes.php',
       'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev-key',
       'signedDefaultTtl' => 900,
       'registrarOptions' => [
           'exposeUrlServices' => true,
           'urlBaseUri' => $_ENV['WEBRICK_URL_BASE_URI'] ?? 'http://localhost',
       ],
   ]);

   RouteCache::clear([
       'matcher' => 'sharded',
       'cache' => __DIR__ . '/.route-cache',
   ]);

CLI equivalents:

.. code:: bash

   php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
   php ./webrick route:clear --matcher=sharded --cache=.route-cache

Route facade URL helpers
------------------------

.. code:: php

   use Infocyph\Webrick\Router\Facade\Router as Route;
   use Infocyph\Webrick\Router\Url\SignedUrlConfig;

   $url = Route::urlFor('users.show', ['id' => 42]);
   $signed = Route::signedUrlFor('users.show', ['id' => 42]);
   $temp = Route::temporaryUrlFor('users.show', ['id' => 42], ttl: 900);
   $until = Route::temporaryUrlUntil('users.show', new DateTimeImmutable('+15 minutes'), ['id' => 42]);
   $absolutePayload = Route::signedUrlFor('users.show', ['id' => 42], absolute: true, payloadMode: SignedUrlConfig::MODE_ABSOLUTE);

If you need manual binding outside ``registrarOptions``:

.. code:: php

   Route::bindUrlServices($routes, $signKey, 900, $signedUrlConfig, $baseUri);

The kernel's default cached binding is lazy: route aliases and the URL generator are materialized only when a URL helper is first used. A custom ``bindUrlServices`` callback opts into integration-owned behavior and may make that work eager.

MiddlewareAliases
-----------------

.. code:: php

   use Infocyph\Webrick\Middleware\ThrottleMiddleware;
   use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

   MiddlewareAliases::register(
       'throttle',
       static fn(...$params) => new ThrottleMiddleware(
           max: (int) ($params[0] ?? 60),
           window: (int) ($params[1] ?? 60),
       ),
   );

Enums
-----

- ``HttpMethodEnum``
- ``MatcherModeEnum``
- ``MediaTypeEnum``
- ``StatusEnum``
