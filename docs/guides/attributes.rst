Attribute Routes
================

Declare routes using PHP attributes directly on controller methods. This guide covers everything from basic usage to advanced patterns and best practices.

--------------

Table of Contents
-----------------

- `Why Use Attributes? <#why-use-attributes>`__
- `Basic Setup <#basic-setup>`__
- `Route Registration <#route-registration>`__
- `Supported Attributes <#supported-attributes>`__
- `Parameter Binding <#parameter-binding>`__
- `Middleware on Attributes <#middleware-on-attributes>`__
- `Grouping Attribute Routes <#grouping-attribute-routes>`__
- `Per-Route Options <#per-route-options>`__
- `Advanced Patterns <#advanced-patterns>`__
- `Route Cache with Attributes <#route-cache-with-attributes>`__
- `Testing <#testing>`__
- `Troubleshooting <#troubleshooting>`__
- `Best Practices <#best-practices>`__

--------------

Why Use Attributes?
-------------------

**Advantages**:

- ✅ Routes live next to their handlers (better discoverability)
- ✅ Refactoring-friendly (rename class/method → route stays attached)
- ✅ Self-documenting (route metadata visible in code)
- ✅ Modular (each feature/module defines its own routes)

**When to Use**:

- Feature-based or domain-driven project structure
- Libraries/packages that ship their own routes
- Teams that prefer colocation over central route files

**When NOT to Use**:

- Small projects with < 20 routes (central ``routes.php`` is simpler)
- Quick prototypes
- When team prefers explicit central routing

--------------

Basic Setup
-----------

1. Directory Structure
~~~~~~~~~~~~~~~~~~~~~~

Organize attribute route classes in a dedicated namespace:

.. code:: php

   src/
   ├── Http/
   │   ├── Routes/              # Attribute route classes
   │   │   ├── UserRoutes.php
   │   │   ├── ProductRoutes.php
   │   │   └── AdminRoutes.php
   │   └── Controller/          # Actual controllers (optional separation)
   │       ├── UserController.php
   │       └── ProductController.php

**Option A: Combined** (route + handler in one class)

.. code:: php

   namespace App\Http\Routes;

   final class UserRoutes
   {
       #[Get('/users', name: 'users.index')]
       public function index() { /* handler code */ }
   }

**Option B: Separated** (route class delegates to controller)

.. code:: php

   namespace App\Http\Routes;

   final class UserRoutes
   {
       public function __construct(private UserController $controller) {}

       #[Get('/users', name: 'users.index')]
       public function index(Request $r) {
           return $this->controller->index($r);
       }
   }

**Recommendation**: Option A for small-medium apps; Option B for large apps with shared controller logic.

--------------

Route Registration
------------------

Enable Attribute Discovery
~~~~~~~~~~~~~~~~~~~~~~~~~~

In your front controller or route builder:

.. code:: php

   <?php
   // public/index.php or routes/web.php

   use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
   use Infocyph\Webrick\Router\Definition\Registrar;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\ShardedMatcher;
   use Psr\Log\NullLogger;

   $register = static function (Registrar $registrar): void {
       // Option 1: Explicit directory scanning
       AttributeRouteLoader::registerFromDirs($registrar, [
           'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
       ]);

       // Option 2: Multiple namespaces
       AttributeRouteLoader::registerFromDirs($registrar, [
           'App\\Http\\Routes\\'   => __DIR__ . '/../src/Http/Routes',
           'App\\Admin\\Routes\\'  => __DIR__ . '/../src/Admin/Routes',
           'Vendor\\Package\\Routes\\' => __DIR__ . '/../vendor/vendor/package/src/Routes',
       ]);

       // Still include classic routes if needed
       require __DIR__ . '/../routes/api.php';
   };

   $kernel = RouterKernel::bootWithRegistrar(
       log: new NullLogger(),
       matcher: ShardedMatcher::make(),
       register: $register,
       routeCache: __DIR__ . '/../.route-cache',
   );

How It Works
~~~~~~~~~~~~

1. ``AttributeRouteLoader`` scans the directory recursively
2. Finds all classes in the given namespace
3. Reflects on each class looking for route attributes
4. Registers routes with the router

**Performance**: Live registration scans during kernel boot. Build the route cache during deployment so production requests load the compiled artifact and do not scan application directories.

--------------

Supported Attributes
--------------------

HTTP Verb Attributes
~~~~~~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Router\Definition\Attribute\{Get, Post, Put, Patch, Delete, Head, Options, Any};

   #[Get('/path', name: 'route.name', middleware: ['auth'])]
   #[Post('/path', ...)]
   #[Put('/path', ...)]
   #[Patch('/path', ...)]
   #[Delete('/path', ...)]
   #[Head('/path', ...)]
   #[Options('/path', ...)]
   #[Any('/path', ...)]  // Matches all methods

Attribute Parameters
~~~~~~~~~~~~~~~~~~~~

All verb attributes accept:

+----------------+------------------+----------+--------------------------------------------+
| Parameter      | Type             | Required | Description                                |
+================+==================+==========+============================================+
| ``path``       | ``string``       | ✅       | Route path (with constraints)              |
+----------------+------------------+----------+--------------------------------------------+
| ``name``       | ``string``       | ❌       | Route name (for URL generation)            |
+----------------+------------------+----------+--------------------------------------------+
| ``middleware`` | ``array|string`` | ❌       | Middleware to apply                        |
+----------------+------------------+----------+--------------------------------------------+
| ``domain``     | ``string``       | ❌       | Domain scoping (e.g., ``api.example.com``) |
+----------------+------------------+----------+--------------------------------------------+

Example: Full Options
~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   #[Get(
       path: '/admin/users/{id:int}',
       name: 'admin.users.show',
       middleware: ['auth', 'admin', 'throttle:60,60'],
       domain: 'admin.example.com'
   )]
   public function showUser(int $id): Response
   {
       // ...
   }

--------------

Parameter Binding
-----------------

Parameters from the path are injected into handler by name:

.. code:: php

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   final class UserRoutes
   {
       // Type-hinted parameters
       #[Get('/users/{id:int}', name: 'users.show')]
       public function show(int $id): Response
       {
           return Response::json(['id' => $id]);
       }

       // Mix Request + params
       #[Get('/users/{id:int}/posts/{postId:int}')]
       public function userPost(Request $r, int $id, int $postId): Response
       {
           return Response::json([
               'user_id' => $id,
               'post_id' => $postId,
               'query' => $r->query()
           ]);
       }

       // Optional parameters with defaults
       #[Get('/search/{query?}', name: 'search')]
       public function search(?string $query = null): Response
       {
           return Response::json(['query' => $query ?? 'all']);
       }

       // Constraints in path
       #[Get('/products/{slug:slug}')]  // slug = [A-Za-z0-9-._]+
       public function product(string $slug): Response
       {
           return Response::json(['slug' => $slug]);
       }

       #[Get('/color/{hex:hex}')]      // hex = [A-Fa-f0-9]+
       public function color(string $hex): Response
       {
           return Response::json(['color' => "#{$hex}"]);
       }
   }

--------------

Middleware on Attributes
------------------------

Per-Route Middleware
~~~~~~~~~~~~~~~~~~~~

.. code:: php

   #[Get('/protected', name: 'protected', middleware: ['auth', 'verified'])]
   public function protected(): Response
   {
       return Response::json(['secret' => 'data']);
   }

   // With parameters
   #[Get('/download/{file}', middleware: ['verifySignedUrl', 'throttle:10,60'])]
   public function download(string $file): Response
   {
       return Response::attachment(__DIR__ . "/files/{$file}", $file);
   }

Class-Level Middleware (Applies to All Routes)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Router\Definition\Attribute\Middleware;

   #[Middleware(['auth', 'throttle:120,60'])]  // Applied to all routes in class
   final class UserRoutes
   {
       #[Get('/users', name: 'users.index')]
       public function index() { /* auth + throttle applied */ }

       #[Get('/users/{id:int}', name: 'users.show')]
       public function show(int $id) { /* auth + throttle applied */ }

       #[Post('/users', name: 'users.store', middleware: ['admin'])]
       public function store() { /* auth + throttle + admin applied */ }
   }

--------------

Grouping Attribute Routes
-------------------------

Apply common options (prefix, middleware) to all attribute routes in a directory:

.. code:: php

   use Infocyph\Webrick\Router\Facade\Router as Route;

   Route::group(
       prefix: '/api',
       namePrefix: 'api.',
       middleware: ['throttle:120,60'],
       callback: function ($registrar) {
           // All discovered routes inherit /api prefix, api.* name prefix and throttle
           AttributeRouteLoader::registerFromDirs($registrar, [
               'App\\Api\\Routes\\' => __DIR__ . '/../src/Api/Routes',
           ]);
       }
   );

**Result**:

.. code:: php

   // In App\Api\Routes\UserRoutes.php
   #[Get('/users', name: 'users.index')]  // Becomes: GET /api/users, name: api.users.index

Domain Scoping
~~~~~~~~~~~~~~

.. code:: php

   Route::group(
       domain: 'api.example.com',
       prefix: '/v1',
       namePrefix: 'v1.',
       callback: function ($registrar) {
           AttributeRouteLoader::registerFromDirs($registrar, [
               'App\\Api\\V1\\Routes\\' => __DIR__ . '/../src/Api/V1/Routes',
           ]);
       }
   );

--------------

Per-Route Options
-----------------

Content Negotiation
~~~~~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Router\Definition\Attribute\Produces;

   #[Get('/data.xml', name: 'data.xml')]
   #[Produces(types: ['application/xml', 'text/xml'])]
   public function getXml(): Response
   {
       return Response::create('<data>...</data>', 200, [
           'Content-Type' => 'application/xml'
       ]);
   }

   #[Get('/data', name: 'data')]
   #[Produces(types: ['application/json', 'application/xml'])]
   public function getData(Request $r): Response
   {
       $data = ['items' => [1, 2, 3]];
       return Response::auto($r, $data);  // Negotiates based on Accept header
   }

CORS Per Route
~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Router\Definition\Attribute\Cors;

   #[Get('/public-api', name: 'public.api')]
   #[Cors(
       origins: ['*'],
       methods: ['GET', 'OPTIONS'],
       headers: ['Content-Type'],
       maxAge: 3600
   )]
   public function publicApi(): Response
   {
       return Response::json(['public' => true]);
   }

--------------

Advanced Patterns
-----------------

Multiple Routes on One Handler
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // Respond to multiple paths
   #[Get('/home')]
   #[Get('/')]
   public function home(): Response
   {
       return Response::plaintext('Home', 200);
   }

   // Versioned endpoints
   #[Get('/api/v1/users')]
   #[Get('/api/v2/users')]
   public function users(Request $r): Response
   {
       $version = str_contains($r->getPath(), 'v2') ? 'v2' : 'v1';
       return Response::json(['version' => $version]);
   }

Resource-Style Routes (RESTful)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   #[Get('/users', name: 'users.index')]
   public function index() { /* list */ }

   #[Get('/users/create', name: 'users.create')]
   public function create() { /* form */ }

   #[Post('/users', name: 'users.store')]
   public function store() { /* create */ }

   #[Get('/users/{id:int}', name: 'users.show')]
   public function show(int $id) { /* show */ }

   #[Get('/users/{id:int}/edit', name: 'users.edit')]
   public function edit(int $id) { /* form */ }

   #[Put('/users/{id:int}', name: 'users.update')]
   public function update(int $id) { /* update */ }

   #[Delete('/users/{id:int}', name: 'users.destroy')]
   public function destroy(int $id) { /* delete */ }

Dependency Injection in Constructors
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   final class UserRoutes
   {
       public function __construct(
           private UserRepository $repo,
           private Logger $log
       ) {}

       #[Get('/users/{id:int}', name: 'users.show')]
       public function show(int $id): Response
       {
           $user = $this->repo->find($id);
           $this->log->info("User {$id} accessed");
           return Response::json($user);
       }
   }

**Note**: Ensure your DI container instantiates these classes, or use static methods/closures.

--------------

Route Cache with Attributes
---------------------------

Build Cache (CI/Production)
~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // scripts/webrick-route-cache.php

   use Infocyph\Webrick\Support\RouteCache;

   RouteCache::build([
       'cache' => __DIR__ . '/../.route-cache',
       'register' => static function ($registrar): void {
           // Include attribute directories in cache build
           AttributeRouteLoader::registerFromDirs($registrar, [
               'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
           ]);

           // And classic routes
           require __DIR__ . '/../routes/web.php';
       },
       'registrarOptions' => [
           'exposeUrlServices' => true,
           'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev',
       ],
   ]);

   echo "Route cache built\n";

**Run in CI**:

.. code:: bash

   php ./webrick route:cache --cache=.route-cache --routes=routes.php

**Benefits**:

- ✅ Attribute scanning happens once (build time)
- ✅ Production requests skip reflection entirely
- ✅ Cold-boot reflection cost is removed; measure the impact for your route set

--------------

Testing
-------

Unit Test Attribute Routes
~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   use PHPUnit\Framework\TestCase;

   class UserRoutesTest extends TestCase
   {
       public function testShowUserReturnsJson(): void
       {
           $routes = new UserRoutes(new MockUserRepository());
           $response = $routes->show(42);

           $this->assertEquals(200, $response->getStatusCode());
           $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
       }
   }

Integration Test with Kernel
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   class AttributeRoutingTest extends TestCase
   {
       private RouterKernel $kernel;

       protected function setUp(): void
       {
           $logger = new NullLogger();
           $matcher = ShardedMatcher::make();

           $this->kernel = RouterKernel::bootWithRegistrar(
               log: $logger,
               matcher: $matcher,
               register: fn($r) => AttributeRouteLoader::registerFromDirs($r, [
                   'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
               ])
           );
       }

       public function testAttributeRouteResponds(): void
       {
           $request = Request::fake(method: 'GET', uri: '/users/42');
           $response = $this->kernel->handle($request);

           $this->assertEquals(200, $response->getStatusCode());
       }
   }

--------------

Troubleshooting
---------------

Route Not Found (404)
~~~~~~~~~~~~~~~~~~~~~

**Causes**:

1. Namespace mismatch
2. Directory path incorrect
3. Class not autoloadable
4. Attribute import wrong

**Debug**:

.. code:: php

   // Confirm the namespace/path pair with a focused registration test.
   $count = AttributeRouteLoader::registerFromDirs($registrar, [
       'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
   ]);

   assert($count > 0);

**Checklist**:

- ☐ Namespace in class matches registered namespace
- ☐ Directory path is absolute and correct
- ☐ Class is in Composer autoload (PSR-4)
- ☐ Attribute class imported: ``use Infocyph\Webrick\Router\Definition\Attribute\Get;``

Duplicate Route Name
~~~~~~~~~~~~~~~~~~~~

**Error**: ``Route name 'users.show' already registered``

**Cause**: Two attribute routes have the same ``name:`` parameter.

**Fix**: Make names unique or omit ``name:`` (auto-generated from class + method).

Middleware Not Applied
~~~~~~~~~~~~~~~~~~~~~~

**Symptoms**: Middleware doesn't run on attribute route.

**Causes**:

1. Middleware alias not registered
2. Typo in middleware name
3. Class-level middleware overridden

**Fix**:

.. code:: php

   // Register aliases
   MiddlewareAliases::register('auth', fn() => new AuthMiddleware());

   // Use exact alias in attribute
   #[Get('/protected', middleware: ['auth'])]  // Not 'Auth' or 'authenticate'

Performance: Slow First Request
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Attribute scanning on every request.

**Fix**: Prebuild route cache:

.. code:: bash

   php ./webrick route:cache --cache=.route-cache --routes=routes.php

Ship ``.route-cache/`` with your deployment.

--------------

Best Practices
--------------

✅ **Do**
~~~~~~~~~

1. **Keep one concern per attribute class**

.. code:: php

      // ✅ Good: Focused
      final class UserRoutes { /* user routes only */ }
      final class ProductRoutes { /* product routes only */ }

      // ❌ Bad: Mixed
      final class Routes { /* users + products + orders */ }

2. **Use explicit route names**

.. code:: php

      #[Get('/users/{id:int}', name: 'users.show')]  // ✅ Explicit
      #[Get('/users/{id:int}')]                       // ❌ Auto-generated (brittle)

3. **Constrain parameters**

.. code:: php

      #[Get('/users/{id:int}')]     // ✅ Type-safe
      #[Get('/users/{id}')]          // ❌ Accepts anything

4. **Group related routes**

.. code:: php

      Route::group(prefix: '/api', callback: fn($r) =>
          AttributeRouteLoader::registerFromDirs($r, [...])
      );

5. **Prebuild cache in production**

.. code:: bash

      php ./webrick route:cache --cache=.route-cache --routes=routes.php  # In CI/CD

❌ **Don't**
~~~~~~~~~~~~

1. **Don't scatter attribute routes everywhere**

   - Keep them in a dedicated ``Routes/`` namespace
   - Avoid mixing with controllers unless intentional

2. **Don't skip type hints**

.. code:: php

      public function show($id) { }  // ❌ Weak
      public function show(int $id) { }  // ✅ Strong

3. **Don't forget to scan in cache build**

   - If attributes aren't in cache, they won't work in production

4. **Don't use attributes for simple apps**

   - < 20 routes? Use ``routes.php`` (simpler)

5. **Don't rely on auto-generated names**

   - Refactoring breaks URL generation

--------------

Performance Comparison
----------------------

Attribute discovery performs reflection during uncached boot; route-cache boot loads the built artifact instead. The difference depends on route count, filesystem, OPcache state, PHP build, and hardware. Benchmark both paths with the application's real route set before publishing numbers.

--------------

Migration: Routes File → Attributes
-----------------------------------

Before (routes/web.php)
~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   Route::get('/users', [UserController::class, 'index'], 'users.index');
   Route::get('/users/{id:int}', [UserController::class, 'show'], 'users.show');
   Route::post('/users', [UserController::class, 'store'], 'users.store');

After (src/Http/Routes/UserRoutes.php)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   namespace App\Http\Routes;

   use Infocyph\Webrick\Router\Definition\Attribute\{Get, Post};

   final class UserRoutes
   {
       #[Get('/users', name: 'users.index')]
       public function index() { /* ... */ }

       #[Get('/users/{id:int}', name: 'users.show')]
       public function show(int $id) { /* ... */ }

       #[Post('/users', name: 'users.store')]
       public function store() { /* ... */ }
   }

Register
~~~~~~~~

.. code:: php

   // Remove old require
   // require __DIR__ . '/../routes/web.php';

   // Add attribute loader
   AttributeRouteLoader::registerFromDirs($registrar, [
       'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes',
   ]);

--------------

Example: Full Feature Module
----------------------------

.. code:: php

   <?php
   // src/Http/Routes/ProductRoutes.php

   namespace App\Http\Routes;

   use Infocyph\Webrick\Router\Definition\Attribute\{Get, Post, Put, Delete, Middleware, Produces};
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   #[Middleware(['throttle:120,60'])]  // All routes throttled
   final class ProductRoutes
   {
       public function __construct(private ProductRepository $repo) {}

       #[Get('/products', name: 'products.index')]
       #[Produces(types: ['application/json', 'text/html'])]
       public function index(Request $r): Response
       {
           $products = $this->repo->all();
           return Response::auto($r, $products);
       }

       #[Get('/products/{id:int}', name: 'products.show')]
       public function show(int $id): Response
       {
           $product = $this->repo->find($id);
           return $product
               ? Response::json($product)
               : Response::json(['error' => 'Not found'], 404);
       }

       #[Post('/products', name: 'products.store', middleware: ['auth', 'admin'])]
       public function store(Request $r): Response
       {
           $data = $r->json();
           $product = $this->repo->create($data);

           $url = Route::urlFor('products.show', ['id' => $product['id']], absolute: true);

           return Response::json($product, 201)
               ->withHeader('Location', $url);
       }

       #[Put('/products/{id:int}', name: 'products.update', middleware: ['auth', 'admin'])]
       public function update(Request $r, int $id): Response
       {
           $data = $r->json();
           $product = $this->repo->update($id, $data);
           return Response::json($product);
       }

       #[Delete('/products/{id:int}', name: 'products.destroy', middleware: ['auth', 'admin', 'verifySignedUrl'])]
       public function destroy(int $id): Response
       {
           $this->repo->delete($id);
           return Response::create('', 204);
       }
   }

--------------

Summary
-------

**Attribute routes are great when**:

- ✅ You have a large app with many features
- ✅ You prefer colocation of routes + handlers
- ✅ You build modular/plugin systems

**Stick with central routes when**:

- ✅ Small app (< 50 routes)
- ✅ Quick prototype
- ✅ Team prefers explicit, central routing

**Golden Rule**: Use what fits your team's workflow. Both approaches are equally valid and performant when cached.
