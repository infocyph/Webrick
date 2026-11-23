Testing Guide
=============

Comprehensive testing strategies for Webrick applications.

Repository quality gates
------------------------

Run ``composer ic:ci`` for the shared PHPForge gates and
``composer ic:tests:details`` for their detailed local output. Security & Standards
owns the hosted diagnostics; Webrick does not maintain a second diagnostics job
or a local Psalm XML configuration.

``composer.json`` defines a default-deny ``extra.phpforge.skipper_policy``.
Its reviewed contracts cover header validation, shard filename components and
operator-selected certification filesystem paths. Certification path authority
comes exclusively from CLI arguments or the test server's host environment;
these helpers must never authorize HTTP request paths or serve as URL encoders.
File contents retain their data taints. Production uploads and file APIs do not
receive a filesystem-taint exemption.

Each sanitizer permission names exact taint categories, its source SHA-256 and
regression tests. Changes to that source deliberately fail the directive gate;
review the implementation, dependencies and tests before updating the digest.
Do not refresh evidence hashes automatically.

Two statement-level Psalm 7.10 exceptions preserve required Fiber behavior:
``UnusedVariable`` misses the escaped drain callback's reference to the cleared
continuation, and ``UnusedMethodCall`` misclassifies ``Fiber::suspend()``.
The lifecycle tests verify reference release, pressure and cancellation. Remove
the exceptions when upstream analysis recognizes those effects. The upload
getter uses an explicit declaring scope to avoid inherited magic-property taint
being confused with private upload storage; actual input taints remain tracked.

The constant-local ``MissingClassConstType`` compatibility policy covers
``BaseEmitter``'s two protected constants and ``Request``'s five public proxy
flags. PHP rejects previously valid untyped subclass overrides if the parent gains
native constant types. The legacy emitter and request-extension regressions check
this contract. Remove the exceptions when the analyzer supports it, or reconsider
native types only in an explicitly breaking release.

Runtime release certification
-----------------------------

For Webrick 6.1, normal PR QA runs Security & Standards, Sphinx and
functional integration. Code-relevant feature-branch pushes run the
independent 6.0.4-to-candidate A/B acceptance workflow; source-identical
A/A measurement diagnostics remain separately manual.
The PHPForge benchmark matrix is also deferred on intermediate pull requests
without disabling PHPForge's QA, PHPStan, Psalm, taint analysis, security, or
package checks. On the final candidate SHA, manually invoke
``Security & Standards`` with ``workflow_dispatch`` to run the full
PHPForge benchmark matrix, and require its success. Scheduled and main-branch
runs retain normal benchmark coverage.

After all non-performance work passes at the final SHA, invoke
``Runtime Release Certification`` via GitHub Actions
``workflow_dispatch`` on that exact branch and require every SAPI/Runwire
performance aggregation and protocol/soak job to pass. Do not treat
the deferral as a waived release gate or lower the budgets.

The ``.github/certification/load-benchmark.sh`` harness measures nine
unprotected routing workloads, one optional admission workload and six
contention/worker-stress workloads: deterministic admission, Redis
counters, atomic clearance consumption, valid and invalid bcrypt-cost-10 logins,
and bounded synthetic provider latency. Baseline uses ordinary host middleware;
candidate uses ``RiskAdmissionMiddleware`` with the same host policy and account
lookup. Wrong-password responses are measured separately from successful logins.
Persistent Runwire workers reuse an immutable middleware binding per protected
workload. PHP-FPM constructs the binding within each request's PHP lifecycle.
Both runtimes evaluate each request afresh. Risk middleware is registered
only on synthetic protected routes; ordinary routes do not call the evaluator.

Supply an isolated, unauthenticated loopback Redis service through
``IC_REDIS_HOST=127.0.0.1`` and ``IC_REDIS_PORT``. The hosted jobs provision their
own service. Performance collectors connect directly over loopback, so
short-lived connections do not carry over through a Docker published port.
The fixture uses synthetic credentials and must never use production Redis.
Its routes and forced decisions are certification tools, excluded from
the distributed package. Its seeded clearance and delayed provider are workload
fixtures, not production issuance or live CAPTCHA verification.

The default hosted gate runs four crossed, warmed trials at concurrency 1, 5,
20 and 50, with 512 required rows and 64 comparisons per runtime mode.
Samples normally run for 10 seconds. Runwire tests of bcrypt at concurrency 20 and 50 use at least
30 seconds to collect more completed requests for the p99 comparison. The
nine ordinary routes retain hard budgets of 2% successful-throughput regression,
15% p99 regression and 32 MiB RSS growth. Opt-in admission and contention
stress report relative comparisons diagnostically, but retain strict response,
transport, worker integrity, the same RSS limit and per-trial absolute p99
safety ceilings (1,600 ms for cheap admission/other stress; 8,000 ms for
bcrypt/provider simulation).
The SAPI lane sends HTTP through Nginx to a two-worker PHP-FPM pool with OPcache.
The Runwire lane uses its optional native HTTP worker. Each concurrency has its
own runner and Redis fixture, and each workload is measured as an adjacent
baseline and candidate pair. The hosted collector assigns distinct CPUs to
the server, load generator, and observer with Redis, and records their affinity.
All four concurrency artifacts
must have matching commit, runtime and dependency provenance before aggregation.
Dependency preparation captures the PHP CLI and FPM executables, extension modules and INI
configuration in the current-run artifact. Each load shard restores and verifies
that runtime, including reruns; the installer's moving minor-version selection
cannot change only one shard's PHP patch. Mixed patch-version evidence remains
a hard failure.
The full-matrix analyzer rejects missing or duplicate rows and enforces
the default-route performance budgets plus independent protected-route
correctness, memory and safety limits. This preserves the 64 minutes of total load per mode while reducing
the load critical path to 16 minutes plus setup. The separate 30-minute soak runs
in parallel and remains the floor for complete certification; runner queues and
setup add elapsed time.
Load threads divide the requested connection count exactly: 1, 1, 4 and 2 threads
respectively. Bcrypt/login and delayed-provider workloads use one client event
loop with the full connection count. This avoids low-rate client-thread latency
cohorts without reducing application pressure, sample duration or trial count.
A live socket regression counts actual HTTP connections; wrk's banner alone
cannot detect rounded-down concurrency.
Bcrypt workloads use a 10-second client deadline so queued CPU work can be
measured; ordinary workloads retain their existing deadline. Every complete
response must validate. Missing evidence, transport errors and unexpected worker
exits, unhealthy events or restarts fail the gate; worker events are retained on
early collector failure too.
Post-load telemetry must show drained queues and requests within the workload's
existing client deadline: two seconds for ordinary workloads, ten seconds for
bcrypt workloads. The control probe queues behind legitimate in-flight work on
busy PHP-FPM or Runwire workers; it must share that deadline. All settling samples are
retained. This wait does not alter measured latency, throughput, transport
errors or in-load RSS peaks. Invalid telemetry, persistent leaked state and
worker restarts still fail certification.

The 30-minute persistent-worker soak also exercises shared counters, clearance
consumption and real password verification alongside the ordinary routes,
deliberate cancellation and planned recycling. Short collector/soak smoke runs
validate the harness and cannot replace release qualification.
Final drain checks prime and refresh diagnostics on both live workers before
checking their queues within the ordinary two-second control deadline. Runwire's
queue gauge is sampled after request completion; idle time alone does not refresh
it. All settling observations and lifecycle events are retained on failure too.

The performance reference is immutable Webrick ``6.0.4`` (the PR base source
SHA ``981d095``), with the reviewed cancellation-only backport in
``baseline-cancellation.diff``. The old adapter lets asynchronous
transport cancellation escape and kills workers; it cannot provide a valid
performance control. ``prepare-baseline.sh`` verifies pinned source and patch
hashes, rejects unrelated tracked edits and unknown source, and preserves Git
HEAD. The reference runs the candidate's cancellation regression tests before
load. Both original and measured source, hashes and normalization state are
published with the results and identified in the workflow summary. This measures
against corrected main; it does not certify unpatched main. Once main contains a
recognized correction, no patch is applied. Any other adapter revision requires
review and renewed pins. Dependency equivalence and every existing correctness,
worker-lifecycle and performance gate remain mandatory.

The local provider HTTP tests exercise configurable endpoints, strict reply
binding, bounded bodies, 4xx/5xx responses, redirects and timeouts without a retry.
Deployment acceptance still requires the selected live CAPTCHA provider, trusted
edge policy and authoritative store durability/failover configuration.

--------------

Test Pyramid
------------

::

           /\
          /  \      E2E Tests (5%)
         /____\     - Full browser tests
        /      \    - Critical user flows
       /________\
      /          \  Integration Tests (25%)
     /____________\ - API tests
    /              \- Middleware stack
   /________________\
                     Unit Tests (70%)
                     - Individual functions
                     - Middleware units

--------------

Unit Testing Middleware
-----------------------

Example: ThrottleMiddleware
~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   <?php

   use PHPUnit\Framework\TestCase;
   use Infocyph\Webrick\Middleware\ThrottleMiddleware;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   class ThrottleMiddlewareTest extends TestCase
   {
       private \Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter $cache;

       protected function setUp(): void
       {
           $this->cache = new \Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter();
       }

       public function testAllowsRequestsWithinLimit(): void
       {
           $throttle = new ThrottleMiddleware(
               max: 5,
               window: 60,
               pool: $this->cache
           );

           $req = $this->createRequest('/api/test');
           $next = fn($r) => Response::json(['ok' => true]);

           // First 5 should pass
           for ($i = 0; $i < 5; $i++) {
               $resp = $throttle($req, $next);
               $this->assertEquals(200, $resp->getStatusCode());
               $this->assertEquals(5, (int)$resp->getHeaderLine('X-RateLimit-Limit'));
               $this->assertEquals(5 - $i - 1, (int)$resp->getHeaderLine('X-RateLimit-Remaining'));
           }

           // 6th should be throttled
           $resp = $throttle($req, $next);
           $this->assertEquals(429, $resp->getStatusCode());
           $this->assertNotEmpty($resp->getHeaderLine('Retry-After'));
       }

       public function testResetsAfterWindow(): void
       {
           $throttle = new ThrottleMiddleware(
               max: 2,
               window: 1,  // 1 second window
               pool: $this->cache
           );

           $req = $this->createRequest('/api/test');
           $next = fn($r) => Response::json(['ok' => true]);

           // Exhaust limit
           $throttle($req, $next);
           $throttle($req, $next);

           // Should be throttled
           $resp = $throttle($req, $next);
           $this->assertEquals(429, $resp->getStatusCode());

           // Wait for window to pass
           sleep(2);

           // Should work again
           $resp = $throttle($req, $next);
           $this->assertEquals(200, $resp->getStatusCode());
       }

       public function testDifferentIpsGetSeparateLimits(): void
       {
           $throttle = new ThrottleMiddleware(
               max: 2,
               window: 60,
               pool: $this->cache
           );

           $next = fn($r) => Response::json(['ok' => true]);

           // IP 1
           $req1 = $this->createRequest('/api/test', '203.0.113.1');
           $throttle($req1, $next);
           $throttle($req1, $next);
           $resp1 = $throttle($req1, $next);
           $this->assertEquals(429, $resp1->getStatusCode());

           // IP 2 should still work
           $req2 = $this->createRequest('/api/test', '203.0.113.2');
           $resp2 = $throttle($req2, $next);
           $this->assertEquals(200, $resp2->getStatusCode());
       }

       private function createRequest(string $path, string $ip = '203.0.113.10'): Request
       {
           return Request::fake(
               headers: ['X-Forwarded-For' => $ip],
               method: 'GET',
               uri: $path,
           );
       }
   }

--------------

Integration Testing Routes
--------------------------

Example: Signed URLs
~~~~~~~~~~~~~~~~~~~~

.. code:: php

   <?php

   use PHPUnit\Framework\TestCase;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\ShardedMatcher;
   use Infocyph\Webrick\Router\Facade\Router as Route;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;
   use Psr\Log\NullLogger;

   class SignedUrlTest extends TestCase
   {
       private RouterKernel $kernel;
       private string $signKey = 'test-key-do-not-use-in-production';

       protected function setUp(): void
       {
           // Boot kernel with test configuration
           $this->kernel = RouterKernel::bootWithRegistrar(
               log: new NullLogger(),
               matcher: ShardedMatcher::make(),
               register: function($r) {
                   $r->get('/secure/resource', fn() => Response::json(['secret' => 'data']), 'secure.resource')
                       ->withMiddleware(['verifySignedUrl']);
               },
               registrarOptions: [
                   'exposeUrlServices' => true,
                   'signKey' => $this->signKey,
                   'signedDefaultTtl' => 900
               ]
               invoker: $invoker,
           );
       }

       public function testUnsignedUrlRejected(): void
       {
           $request = Request::fake(method: 'GET', uri: '/secure/resource');
           $response = $this->kernel->handle($request);

           $this->assertEquals(400, $response->getStatusCode());
           $data = json_decode((string)$response->getBody(), true);
           $this->assertEquals('Missing signature', $data['error'] ?? '');
       }

       public function testValidSignedUrlAllowed(): void
       {
           // Generate signed URL
           $url = Route::signedUrlFor('secure.resource');

           $request = Request::fake(method: 'GET', uri: $url);
           $response = $this->kernel->handle($request);

           $this->assertEquals(200, $response->getStatusCode());
           $data = json_decode((string)$response->getBody(), true);
           $this->assertEquals('data', $data['secret']);
       }

       public function testTamperedSignatureRejected(): void
       {
           $url = Route::signedUrlFor('secure.resource');

           // Tamper with signature
           $tampered = preg_replace('/_sig=[^&]+/', '_sig=invalid', $url);

           $request = Request::fake(method: 'GET', uri: $tampered);
           $response = $this->kernel->handle($request);

           $this->assertEquals(403, $response->getStatusCode());
       }

       public function testExpiredTemporaryUrl(): void
       {
           // Create URL that expires almost immediately
           $url = Route::temporaryUrlFor('secure.resource', ttl: 1);
           sleep(2);

           $request = Request::fake(method: 'GET', uri: $url);
           $response = $this->kernel->handle($request);

           $this->assertEquals(410, $response->getStatusCode());  // Gone
       }

       public function testLeewayAllowsClockSkew(): void
       {
           // Create URL with short TTL
           $url = Route::temporaryUrlFor('secure.resource', ttl: 1);
           sleep(2);

           // But middleware has 5-second leeway
           $request = Request::fake(method: 'GET', uri: $url);
           $response = $this->kernel->handle($request);

           $this->assertEquals(200, $response->getStatusCode());
       }
   }

--------------

Testing Middleware Stack
------------------------

Test Middleware Ordering
~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   <?php

   class MiddlewareStackTest extends TestCase
   {
       public function testCompressionAddsVaryHeader(): void
       {
           $kernel = $this->bootKernelWithMiddleware([
               CompressionMiddleware::class,
               VaryAccumulatorMiddleware::class
           ]);

           $request = Request::fake(
               headers: ['Accept-Encoding' => 'gzip, br'],
               method: 'GET',
               uri: '/api/test',
           );

           $response = $kernel->handle($request);

           $vary = $response->getHeaderLine('Vary');
           $this->assertStringContainsString('Accept-Encoding', $vary);
       }

       public function testCacheValidatorsReturn304(): void
       {
           $kernel = $this->bootKernelWithMiddleware([
               CacheValidatorsMiddleware::class
           ]);

           // First request
           $request1 = Request::fake(method: 'GET', uri: '/api/users/1');
           $response1 = $kernel->handle($request1);
           $etag = $response1->getHeaderLine('ETag');

           $this->assertNotEmpty($etag);

           // Second request with If-None-Match
           $request2 = Request::fake(
               headers: ['If-None-Match' => $etag],
               method: 'GET',
               uri: '/api/users/1',
           );
           $response2 = $kernel->handle($request2);

           $this->assertEquals(304, $response2->getStatusCode());
           $this->assertEmpty((string)$response2->getBody());
       }

       public function testThrottleBeforeHandler(): void
       {
           $handlerCalled = 0;

           $kernel = $this->bootKernelWithMiddleware([
               new ThrottleMiddleware(max: 1, window: 60, pool: new \Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter())
           ]);

           $next = function($r) use (&$handlerCalled) {
               $handlerCalled++;
               return Response::json(['ok' => true]);
           };

           $req = Request::fake(method: 'GET', uri: '/test');

           // First request - handler called
           $kernel->handle($req);
           $this->assertEquals(1, $handlerCalled);

           // Second request - handler NOT called (throttled before)
           $response = $kernel->handle($req);
           $this->assertEquals(1, $handlerCalled);  // Still 1
           $this->assertEquals(429, $response->getStatusCode());
       }
   }

--------------

Mocking External Dependencies
-----------------------------

HTTP Client Mock
~~~~~~~~~~~~~~~~

.. code:: php

   <?php

   class ApiClientTest extends TestCase
   {
       public function testHandlesThrottling(): void
       {
           // Mock HTTP responses
           $mock = new MockHandler([
               new Response(429, ['Retry-After' => '5'], '{"error": "throttled"}'),
               new Response(200, [], '{"ok": true}')
           ]);

           $client = new ApiClient($mock);

           // Should retry after backoff
           $result = $client->fetchData();

           $this->assertTrue($result['ok']);
           $this->assertEquals(2, $mock->getRequestCount());
       }

       public function testHandlesTimeout(): void
       {
           $mock = new MockHandler([
               new RequestException('Connection timeout', new Request('GET', '/'))
           ]);

           $client = new ApiClient($mock);

           $this->expectException(ApiException::class);
           $this->expectExceptionMessage('timeout');

           $client->fetchData();
       }
   }

Database Mock
~~~~~~~~~~~~~

.. code:: php

   <?php

   class UserRepositoryTest extends TestCase
   {
       private PDO $db;

       protected function setUp(): void
       {
           // Use in-memory SQLite for tests
           $this->db = new PDO('sqlite::memory:');
           $this->db->exec('CREATE TABLE users (
               id INTEGER PRIMARY KEY,
               email TEXT NOT NULL UNIQUE,
               name TEXT
           )');
       }

       public function testFindById(): void
       {
           $repo = new UserRepository($this->db);

           // Insert test data
           $this->db->exec("INSERT INTO users (id, email, name) VALUES (1, 'test@example.com', 'Test User')");

           $user = $repo->findById(1);

           $this->assertNotNull($user);
           $this->assertEquals('test@example.com', $user->email);
       }

       public function testCreateDuplicateEmailFails(): void
       {
           $repo = new UserRepository($this->db);

           $repo->create(['email' => 'test@example.com', 'name' => 'User 1']);

           $this->expectException(DuplicateEmailException::class);
           $repo->create(['email' => 'test@example.com', 'name' => 'User 2']);
       }
   }

--------------

Performance Testing
-------------------

Benchmark Route Matching
~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   <?php

   class RoutingBenchmark extends TestCase
   {
       public function testRouteMatchingPerformance(): void
       {
           $kernel = $this->bootKernel();

           $iterations = 10000;
           $start = microtime(true);

           for ($i = 0; $i < $iterations; $i++) {
               $request = Request::fake(method: 'GET', uri: "/users/{$i}");
               $kernel->handle($request);
           }

           $duration = microtime(true) - $start;
           $rps = $iterations / $duration;

           // Should handle 10k routes in <1s with cache
           $this->assertLessThan(1.0, $duration,
               "Route matching too slow: {$duration}s for {$iterations} requests ({$rps} req/s)"
           );

           // Should achieve at least 10k req/s
           $this->assertGreaterThan(10000, $rps,
               "Route matching performance insufficient: {$rps} req/s (expected >10k)"
           );
       }

       public function testMiddlewareOverhead(): void
       {
           $withoutMiddleware = $this->benchmarkKernel([]);
           $withMiddleware = $this->benchmarkKernel([
               GatewayHardeningMiddleware::class,
               TelemetryMiddleware::class,
               CacheValidatorsMiddleware::class
           ]);

           $overhead = $withMiddleware - $withoutMiddleware;

           // Middleware should add <5ms overhead
           $this->assertLessThan(5, $overhead,
               "Middleware overhead too high: {$overhead}ms"
           );
       }

       private function benchmarkKernel(array $middleware, int $iterations = 1000): float
       {
           $kernel = $this->bootKernelWithMiddleware($middleware);
           $request = Request::fake(method: 'GET', uri: '/ping');

           $start = microtime(true);
           for ($i = 0; $i < $iterations; $i++) {
               $kernel->handle($request);
           }
           $duration = microtime(true) - $start;

           return ($duration / $iterations) * 1000;  // ms per request
       }
   }

--------------

End-to-End Testing
------------------

Browser Tests (Selenium/Playwright)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   <?php

   use Facebook\WebDriver\Remote\RemoteWebDriver;
   use Facebook\WebDriver\WebDriverBy;

   class E2ETest extends TestCase
   {
       private RemoteWebDriver $driver;

       protected function setUp(): void
       {
           $this->driver = RemoteWebDriver::create(
               'http://localhost:4444',
               DesiredCapabilities::chrome()
           );
       }

       public function testUserCanLoginAndViewProfile(): void
       {
           $this->driver->get('http://localhost:8000/login');

           // Fill login form
           $this->driver->findElement(WebDriverBy::name('email'))
               ->sendKeys('test@example.com');
           $this->driver->findElement(WebDriverBy::name('password'))
               ->sendKeys('password123');
           $this->driver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))
               ->click();

           // Wait for redirect
           $this->driver->wait(10)->until(
               WebDriverExpectedCondition::urlContains('/dashboard')
           );

           // Verify logged in
           $this->assertStringContainsString('Welcome',
               $this->driver->findElement(WebDriverBy::tagName('h1'))->getText()
           );

           // Navigate to profile
           $this->driver->findElement(WebDriverBy::linkText('Profile'))->click();

           // Verify profile page
           $this->assertStringContainsString('test@example.com',
               $this->driver->getPageSource()
           );
       }

       protected function tearDown(): void
       {
           $this->driver->quit();
       }
   }

--------------

Test Data Factories
-------------------

.. code:: php

   <?php

   class UserFactory
   {
       public static function make(array $overrides = []): array
       {
           return array_merge([
               'email' => 'user' . uniqid() . '@example.com',
               'name' => 'Test User',
               'password' => password_hash('password123', PASSWORD_ARGON2ID),
               'created_at' => date('Y-m-d H:i:s'),
               'active' => true
           ], $overrides);
       }

       public static function create(PDO $db, array $overrides = []): int
       {
           $user = self::make($overrides);

           $stmt = $db->prepare('
               INSERT INTO users (email, name, password, created_at, active)
               VALUES (:email, :name, :password, :created_at, :active)
           ');
           $stmt->execute($user);

           return (int)$db->lastInsertId();
       }

       public static function createMany(PDO $db, int $count, array $overrides = []): array
       {
           $ids = [];
           for ($i = 0; $i < $count; $i++) {
               $ids[] = self::create($db, $overrides);
           }
           return $ids;
       }
   }

   // Usage
   $userId = UserFactory::create($db, ['email' => 'admin@example.com', 'admin' => true]);
   $userIds = UserFactory::createMany($db, 50);

--------------

Testing Best Practices
----------------------

✅ **Do**
~~~~~~~~~

- Test one thing per test method
- Use descriptive test names (``testThrottlesAfterMaxRequests``)
- Test both success and failure paths
- Mock external services (APIs, databases)
- Use factories for test data
- Clean up after tests (transactions, temp files)
- Run tests in isolation (no shared state)
- Measure code coverage (aim for 80%+)

❌ **Don't**
~~~~~~~~~~~~

- Test framework internals
- Hit real APIs/databases in unit tests
- Skip error case testing
- Use production credentials
- Commit sensitive test data
- Make tests depend on execution order
- Test implementation details (test behavior)

--------------

CI Pipeline Example
-------------------

.. code:: yaml

   # .github/workflows/test.yml
   name: Tests

   on:
     push:
       branches: [main, develop]
     pull_request:

   jobs:
     test:
       runs-on: ubuntu-latest

       services:
         postgres:
           image: postgres:16
           env:
             POSTGRES_PASSWORD: test
           options: >-
             --health-cmd pg_isready
             --health-interval 10s
             --health-timeout 5s
             --health-retries 5

       steps:
         - uses: actions/checkout@v4

         - uses: shivammathur/setup-php@v2
           with:
             php-version: '8.4'
             extensions: mbstring, json, pdo_pgsql, zstd, brotli
             coverage: xdebug

         - name: Validate composer.json
           run: composer validate --strict

         - name: Install dependencies
           run: composer install --prefer-dist --no-progress

         - name: Run linters
           run: |
             vendor/bin/phpcs
             vendor/bin/phpstan analyse

         - name: Run unit tests
           run: vendor/bin/phpunit --testsuite=Unit

         - name: Run integration tests
           run: vendor/bin/phpunit --testsuite=Integration
           env:
             DB_HOST: localhost
             DB_PORT: 5432
             DB_DATABASE: test
             DB_USERNAME: postgres
             DB_PASSWORD: test

         - name: Generate coverage report
           run: vendor/bin/phpunit --coverage-clover=coverage.xml

         - name: Upload coverage to Codecov
           uses: codecov/codecov-action@v3
           with:
             files: ./coverage.xml
             fail_ci_if_error: true

         - name: Security audit
           run: composer audit

--------------

Code Coverage
-------------

.. code:: bash

   # Generate HTML coverage report
   vendor/bin/phpunit --coverage-html=coverage/

   # Open in browser
   open coverage/index.html

   # Coverage requirements in phpunit.xml
   <coverage processUncoveredFiles="true">
       <include>
           <directory suffix=".php">src</directory>
       </include>
       <report>
           <clover outputFile="coverage.xml"/>
           <html outputDirectory="coverage/"/>
       </report>
   </coverage>

   <logging>
       <junit outputFile="junit.xml"/>
   </logging>

--------------

Mutation Testing
----------------

.. code:: bash

   # Install Infection
   composer require --dev infection/infection

   # Run mutation tests
   vendor/bin/infection

   # Example output:
   # Mutation Score Indicator (MSI): 85%
   # Mutation Code Coverage: 90%
   # Covered Code MSI: 94%

**Goal**: MSI >80% (means your tests catch 80%+ of bugs)

--------------

Load Testing
------------

.. code:: bash

   # Install Apache Bench
   apt-get install apache2-utils

   # Simple load test
   ab -n 10000 -c 100 http://localhost:8000/api/users

   # Results:
   # Requests per second:    2543.21 [#/sec]
   # Time per request:       39.321 [ms]
   # Transfer rate:          1234.56 [Kbytes/sec]

   # Install wrk for advanced scenarios
   apt-get install wrk

   # POST request with JSON
   wrk -t4 -c100 -d30s -s post.lua http://localhost:8000/api/users

   # post.lua:
   # wrk.method = "POST"
   # wrk.body = '{"name":"test","email":"test@example.com"}'
   # wrk.headers["Content-Type"] = "application/json"

--------------

Test Organization
-----------------

::

   tests/
   ├── Unit/
   │   ├── Middleware/
   │   │   ├── ThrottleMiddlewareTest.php
   │   │   ├── CompressionMiddlewareTest.php
   │   │   └── CacheValidatorsMiddlewareTest.php
   │   ├── Router/
   │   │   ├── MatcherTest.php
   │   │   └── RouteCollectionTest.php
   │   └── Response/
   │       ├── ResponseTest.php
   │       └── ResponseHelpersTest.php
   ├── Integration/
   │   ├── SignedUrlTest.php
   │   ├── MiddlewareStackTest.php
   │   └── RoutingTest.php
   ├── Feature/
   │   ├── AuthenticationTest.php
   │   ├── UserManagementTest.php
   │   └── ApiEndpointsTest.php
   └── E2E/
       ├── LoginFlowTest.php
       └── CheckoutFlowTest.php

--------------

Quick Reference
---------------

.. code:: bash

   # Run all tests
   vendor/bin/phpunit

   # Run specific suite
   vendor/bin/phpunit --testsuite=Unit

   # Run specific test
   vendor/bin/phpunit tests/Unit/Middleware/ThrottleMiddlewareTest.php

   # Run with coverage
   vendor/bin/phpunit --coverage-html=coverage/

   # Run in parallel (faster)
   vendor/bin/paratest --processes=4

   # Watch mode (re-run on file change)
   vendor/bin/phpunit-watcher watch
