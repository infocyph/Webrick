# Testing Guide

Comprehensive testing strategies for Webrick applications.

---

## Test Pyramid
```
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
```

---

## Unit Testing Middleware

### Example: ThrottleMiddleware
```php
<?php

use PHPUnit\Framework\TestCase;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

class ThrottleMiddlewareTest extends TestCase
{
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
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
        return Request::create('GET', $path, server: [
            'REMOTE_ADDR' => $ip
        ]);
    }
}
```

---

## Integration Testing Routes

### Example: Signed URLs
```php
<?php

use PHPUnit\Framework\TestCase;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

class SignedUrlTest extends TestCase
{
    private RouterKernel $kernel;
    private string $signKey = 'test-key-do-not-use-in-production';

    protected function setUp(): void
    {
        // Boot kernel with test configuration
        $this->kernel = RouterKernel::bootWithRegistrar(
            matcher: ShardedMatcher::make('/tmp/test-route-cache'),
            register: function($r) {
                $r->get('/secure/resource', fn() => Response::json(['secret' => 'data']), 'secure.resource')
                    ->middleware(['verifySignedUrl']);
            },
            registrarOptions: [
                'exposeUrlServices' => true,
                'signKey' => $this->signKey,
                'signedDefaultTtl' => 900
            ]
        );

        Response::bindUrlServices($this->kernel->routes(), $this->signKey, 900);
    }

    public function testUnsignedUrlRejected(): void
    {
        $request = Request::create('GET', '/secure/resource');
        $response = $this->kernel->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('Missing signature', $data['error'] ?? '');
    }

    public function testValidSignedUrlAllowed(): void
    {
        // Generate signed URL
        $url = Response::signedUrlFor('secure.resource');

        $request = Request::create('GET', $url);
        $response = $this->kernel->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('data', $data['secret']);
    }

    public function testTamperedSignatureRejected(): void
    {
        $url = Response::signedUrlFor('secure.resource');

        // Tamper with signature
        $tampered = preg_replace('/_sig=[^&]+/', '_sig=invalid', $url);

        $request = Request::create('GET', $tampered);
        $response = $this->kernel->handle($request);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testExpiredTemporaryUrl(): void
    {
        // Create URL that expires immediately
        $url = Response::temporaryUrlFor('secure.resource', ttl: -10);

        $request = Request::create('GET', $url);
        $response = $this->kernel->handle($request);

        $this->assertEquals(410, $response->getStatusCode());  // Gone
    }

    public function testLeewayAllowsClockSkew(): void
    {
        // Create URL that expired 3 seconds ago
        $url = Response::temporaryUrlFor('secure.resource', ttl: -3);

        // But middleware has 5-second leeway
        $request = Request::create('GET', $url);
        $response = $this->kernel->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
    }
}
```

---

## Testing Middleware Stack

### Test Middleware Ordering
```php
<?php

class MiddlewareStackTest extends TestCase
{
    public function testCompressionAddsVaryHeader(): void
    {
        $kernel = $this->bootKernelWithMiddleware([
            CompressionMiddleware::class,
            VaryAccumulatorMiddleware::class
        ]);

        $request = Request::create('GET', '/api/test', headers: [
            'Accept-Encoding' => 'gzip, br'
        ]);

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
        $request1 = Request::create('GET', '/api/users/1');
        $response1 = $kernel->handle($request1);
        $etag = $response1->getHeaderLine('ETag');

        $this->assertNotEmpty($etag);

        // Second request with If-None-Match
        $request2 = Request::create('GET', '/api/users/1', headers: [
            'If-None-Match' => $etag
        ]);
        $response2 = $kernel->handle($request2);

        $this->assertEquals(304, $response2->getStatusCode());
        $this->assertEmpty((string)$response2->getBody());
    }

    public function testThrottleBeforeHandler(): void
    {
        $handlerCalled = 0;

        $kernel = $this->bootKernelWithMiddleware([
            new ThrottleMiddleware(max: 1, window: 60, pool: new InMemoryCache())
        ]);

        $next = function($r) use (&$handlerCalled) {
            $handlerCalled++;
            return Response::json(['ok' => true]);
        };

        $req = Request::create('GET', '/test');

        // First request - handler called
        $kernel->handle($req);
        $this->assertEquals(1, $handlerCalled);

        // Second request - handler NOT called (throttled before)
        $response = $kernel->handle($req);
        $this->assertEquals(1, $handlerCalled);  // Still 1
        $this->assertEquals(429, $response->getStatusCode());
    }
}
```

---

## Mocking External Dependencies

### HTTP Client Mock
```php
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
```

### Database Mock
```php
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
```

---

## Performance Testing

### Benchmark Route Matching
```php
<?php

class RoutingBenchmark extends TestCase
{
    public function testRouteMatchingPerformance(): void
    {
        $kernel = $this->bootKernel();

        $iterations = 10000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $request = Request::create('GET', "/users/{$i}");
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
        $request = Request::create('GET', '/ping');

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $kernel->handle($request);
        }
        $duration = microtime(true) - $start;

        return ($duration / $iterations) * 1000;  // ms per request
    }
}
```

---

## End-to-End Testing

### Browser Tests (Selenium/Playwright)
```php
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
```

---

## Test Data Factories
```php
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
```

---

## Testing Best Practices

### ✅ **Do**

- Test one thing per test method
- Use descriptive test names (`testThrottlesAfterMaxRequests`)
- Test both success and failure paths
- Mock external services (APIs, databases)
- Use factories for test data
- Clean up after tests (transactions, temp files)
- Run tests in isolation (no shared state)
- Measure code coverage (aim for 80%+)

### ❌ **Don't**

- Test framework internals
- Hit real APIs/databases in unit tests
- Skip error case testing
- Use production credentials
- Commit sensitive test data
- Make tests depend on execution order
- Test implementation details (test behavior)

---

## CI Pipeline Example
```yaml
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
```

---

## Code Coverage
```bash
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
```

---

## Mutation Testing
```bash
# Install Infection
composer require --dev infection/infection

# Run mutation tests
vendor/bin/infection

# Example output:
# Mutation Score Indicator (MSI): 85%
# Mutation Code Coverage: 90%
# Covered Code MSI: 94%
```

**Goal**: MSI >80% (means your tests catch 80%+ of bugs)

---

## Load Testing
```bash
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
```

---

## Test Organization
```
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
```

---

## Quick Reference
```bash
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
```