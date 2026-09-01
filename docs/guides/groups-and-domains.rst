Groups, Prefixes & Domains
==========================

Bundle related routes with shared options (path prefix, name prefix, middleware) and optionally scope them to a specific hostname. Groups keep large apps tidy, avoid repetition and make it easy to apply policies in one place.

--------------

Group anatomy
-------------

.. code:: php

   use Infocyph\Webrick\Router\Facade\Router as Route;

   Route::group(
     prefix: '/api',                 // prepends to all child paths
     namePrefix: 'api.',            // prepends to all child route names
     middleware: ['throttle:60,1'], // applied to all child routes
     callback: function ($api) {

       // Child routes share /api and api.* name
       $api->get('/ping', fn()=> 'pong', 'ping');            // /api/ping  name: api.ping
       $api->get('/users/{id:int}', fn($id)=>"user $id", [
         'as' => 'users.show',                               // name: api.users.show
         'middleware' => ['verifySignedUrl'],                // per-route extra middleware
       ]);

     }
   );

**Key ideas**

- ``prefix`` and ``namePrefix`` **append** with nesting (see below).
- ``middleware`` in a group applies to all children; a child can add more via its own options.
- Inside the callback, you can use either ``$api->get()`` *or* ``Route::get()``—both register into the current group scope.

--------------

Nesting groups
--------------

Groups can be nested to reflect modules and versions:

.. code:: php

   Route::group(prefix:'/api', namePrefix:'api.', callback:function ($api) {
     Route::group(prefix:'/v1', namePrefix:'v1.', middleware:['throttle:120,1'], callback:function () {
       Route::get('/health', fn()=>['ok'=>true], 'health');  // /api/v1/health, name: api.v1.health
     });
   });

Resulting child:

- Path: ``/api/v1/health``
- Name: ``api.v1.health``
- Middleware: ``throttle:60,1`` (from parent, if any) + ``throttle:120,1`` (from inner) + any per-route entries

..

   Avoid excessively deep nesting; keep prefixes meaningful and short.

--------------

Domain-scoped groups
--------------------

Scope a set of routes to a hostname. Useful for separating public site vs API, or multi-tenant subdomains.

.. code:: php

   // Bind to api.example.com only
   Route::group(domain:'api.example.com', namePrefix:'api.', prefix:'/v1', callback:function () {
     Route::get('/status', fn()=> ['ok'=>true], 'status');   // https://api.example.com/v1/status
   });

**Local development**

.. code:: php

   Route::group(domain:'api.localhost', prefix:'/v1', namePrefix:'v1.', callback:function () {
     Route::get('/ping', fn()=> 'pong', 'ping');
   });

..

   When generating absolute URLs for domain-scoped routes, set your app’s base URL/host correctly (via server variables or your config) so ``Route::urlFor(..., absolute:true)`` picks the right host.

--------------

Mixing domain + prefix + middleware
-----------------------------------

.. code:: php

   Route::group(
     domain: 'admin.example.com',
     prefix: '/dashboard',
     namePrefix: 'admin.',
     middleware: ['auth', 'throttle:30,1'],
     callback: function () {
       Route::get('/', fn()=> 'home', 'home');              // /dashboard (on admin.example.com)
       Route::get('/users', fn()=> 'list', 'users.index');  // name: admin.users.index
     }
   );

--------------

Per-route overrides & additions
-------------------------------

Within a group:

- **Adding** middleware at the route level extends the group list.
- **Name** can be set with ``'as' => '...'`` and will be prefixed automatically.
- **Domain** can be explicitly set on a route, but prefer group-level scoping for clarity.

.. code:: php

   Route::group(prefix:'/files', namePrefix:'files.', middleware:['throttle:20,1'], callback:function () {
     Route::get('/{id:int}', fn($id)=>"file $id", [
       'as' => 'show',
       'middleware' => ['verifySignedUrl'], // now throttle + verifySignedUrl
     ]);
   });

--------------

Ordering & conflicts
--------------------

- Registration order still matters **within** a group: place specific/static routes before dynamic ones (``/new`` before ``/{id:int}``).
- A domain-scoped group will never match requests to other hosts, so it doesn’t conflict with non-scoped routes.
- Catch-all patterns (``.*``) should be defined **last** inside their scope.

--------------

Common layouts
--------------

1) Public site + API split
~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // Public
   Route::group(prefix:'', namePrefix:'web.', callback:function () {
     Route::get('/', fn()=> 'home', 'home');
     Route::get('/about', fn()=> 'about', 'about');
   });

   // API
   Route::group(prefix:'/api', namePrefix:'api.', middleware:['throttle:120,1'], callback:function () {
     Route::get('/v1/status', fn()=> ['ok'=>true], 'v1.status');
   });

2) Versioned API by domain (dev-friendly)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // dev: api.localhost
   Route::group(domain:'api.localhost', namePrefix:'api.', callback:function () {
     Route::group(prefix:'/v1', namePrefix:'v1.', callback:function () {
       Route::get('/users/{id:int}', fn($id)=>['id'=>$id], 'users.show');
     });
   });

--------------

Testing groups
--------------

Use ``curl`` with the ``Host`` header to test domain-scoped routes locally:

.. code:: bash

   curl -i -H "Host: api.localhost" http://127.0.0.1:8000/v1/ping

(If you’re not using virtual hosts, add an entry to ``/etc/hosts`` or your dev proxy.)

--------------

Checklist
---------

- ☐ Use groups to avoid repeating ``prefix``, ``namePrefix`` and shared ``middleware``

- ☐ Keep nested depth reasonable; structure by module/version

- ☐ Domain-scope APIs or admin portals when hosts differ

- ☐ Order static before dynamic paths within the same prefix

- ☐ Put wide patterns last; avoid accidental shadowing
