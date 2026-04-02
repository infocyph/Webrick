# Caching Strategies Recipe

Implement smart caching for optimal performance.

---

## Problem

You need to cache expensive operations, API responses, and database queries efficiently.

---

## Solution

### 1. Cache Service

```php
<?php
// src/Service/CacheService.php

namespace App\Service;

use Psr\SimpleCache\CacheInterface;

final class CacheService
{
    public function __construct(
        private CacheInterface $cache
    ) {}

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->cache->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->cache->set($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, 86400 * 365, $callback);  // 1 year
    }

    public function forget(string $key): void
    {
        $this->cache->delete($key);
    }

    public function flush(string $pattern = '*'): void
    {
        // Pattern-based deletion (Redis-specific)
        if ($this->cache instanceof \Symfony\Component\Cache\Adapter\RedisAdapter) {
            $keys = $this->cache->getAdapter()->getConnection()->keys($pattern);
            foreach ($keys as $key) {
                $this->cache->delete($key);
            }
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this->cache, $tags);
    }
}

final class TaggedCache
{
    public function __construct(
        private CacheInterface $cache,
        private array $tags
    ) {}

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $taggedKey = $this->getTaggedKey($key);

        $value = $this->cache->get($taggedKey);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->cache->set($taggedKey, $value, $ttl);

        // Track key for this tag
        foreach ($this->tags as $tag) {
            $tagKeys = $this->cache->get("tag:{$tag}") ?? [];
            $tagKeys[] = $taggedKey;
            $this->cache->set("tag:{$tag}", array_unique($tagKeys));
        }

        return $value;
    }

    public function flush(): void
    {
        foreach ($this->tags as $tag) {
            $keys = $this->cache->get("tag:{$tag}") ?? [];
            foreach ($keys as $key) {
                $this->cache->delete($key);
            }
            $this->cache->delete("tag:{$tag}");
        }
    }

    private function getTaggedKey(string $key): string
    {
        return 'tagged:' . md5(implode('|', $this->tags) . '|' . $key);
    }
}
```

### 2. HTTP Response Cache Middleware

```php
<?php
// src/Middleware/HttpCacheMiddleware.php

namespace App\Middleware;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\SimpleCache\CacheInterface;
use Closure;

final class HttpCacheMiddleware
{
    public function __construct(
        private CacheInterface $cache,
        private int $defaultTtl = 60
    ) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        // Only cache GET/HEAD
        if (!in_array($r->getMethod(), ['GET', 'HEAD'])) {
            return $next($r);
        }

        // Generate cache key
        $cacheKey = $this->getCacheKey($r);

        // Check cache
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $this->unserializeResponse($cached)
                ->withHeader('X-Cache', 'HIT');
        }

        // Get fresh response
        $response = $next($r);

        // Cache if cacheable
        if ($this->isCacheable($response)) {
            $ttl = $this->getTtl($response);
            $this->cache->set($cacheKey, $this->serializeResponse($response), $ttl);
        }

        return $response->withHeader('X-Cache', 'MISS');
    }

    private function getCacheKey(Request $r): string
    {
        $parts = [
            $r->getMethod(),
            $r->getHost(),
            $r->getPath(),
            $r->getQueryString(),
            $r->getHeaderLine('Accept'),
            $r->getHeaderLine('Accept-Language')
        ];

        return 'http:' . md5(implode('|', $parts));
    }

    private function isCacheable(Response $response): bool
    {
        $statusCode = $response->getStatusCode();

        // Only cache successful responses
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $cacheControl = $response->getHeaderLine('Cache-Control');

        // Don't cache if explicitly told not to
        if (str_contains($cacheControl, 'no-store') ||
            str_contains($cacheControl, 'private')) {
            return false;
        }

        // Don't cache responses with Set-Cookie
        if ($response->hasHeader('Set-Cookie')) {
            return false;
        }

        return true;
    }

    private function getTtl(Response $response): int
    {
        $cacheControl = $response->getHeaderLine('Cache-Control');

        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
            return (int) $matches[1];
        }

        return $this->defaultTtl;
    }

    private function serializeResponse(Response $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getBody()
        ];
    }

    private function unserializeResponse(array $data): Response
    {
        $response = Response::create($data['body'], $data['status']);

        foreach ($data['headers'] as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response;
    }
}
```

### 3. Query Result Caching

```php
<?php
// src/Repository/UserRepository.php

namespace App\Repository;

use App\Service\CacheService;

final class UserRepository
{
    public function __construct(
        private CacheService $cache
    ) {}

    public function find(int $id): ?array
    {
        return $this->cache->remember(
            key: "user:{$id}",
            ttl: 3600,
            callback: fn() => DB::queryOne('SELECT * FROM users WHERE id = ?', [$id])
        );
    }

    public function all(): array
    {
        return $this->cache->remember(
            key: 'users:all',
            ttl: 600,
            callback: fn() => DB::query('SELECT * FROM users ORDER BY name')
        );
    }

    public function create(array $data): array
    {
        $id = DB::insert('users', $data);

        // Invalidate list cache
        $this->cache->forget('users:all');

        return $this->find($id);
    }

    public function update(int $id, array $data): array
    {
        DB::update('users', $data, ['id' => $id]);

        // Invalidate caches
        $this->cache->forget("user:{$id}");
        $this->cache->forget('users:all');

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        DB::delete('users', ['id' => $id]);

        // Invalidate caches
        $this->cache->forget("user:{$id}");
        $this->cache->forget('users:all');
    }
}
```

### 4. Tag-Based Cache Invalidation

```php
<?php
// src/Repository/PostRepository.php

namespace App\Repository;

use App\Service\CacheService;

final class PostRepository
{
    public function __construct(
        private CacheService $cache
    ) {}

    public function findByUser(int $userId): array
    {
        return $this->cache
            ->tags(['posts', "user:{$userId}"])
            ->remember(
                key: "user:{$userId}:posts",
                ttl: 600,
                callback: fn() => DB::query(
                    'SELECT * FROM posts WHERE user_id = ? ORDER BY created_at DESC',
                    [$userId]
                )
            );
    }

    public function findByCategory(int $categoryId): array
    {
        return $this->cache
            ->tags(['posts', "category:{$categoryId}"])
            ->remember(
                key: "category:{$categoryId}:posts",
                ttl: 600,
                callback: fn() => DB::query(
                    'SELECT * FROM posts WHERE category_id = ?',
                    [$categoryId]
                )
            );
    }

    public function create(array $data): array
    {
        $id = DB::insert('posts', $data);

        // Flush all posts caches
        $this->cache->tags(['posts'])->flush();

        // Flush user-specific cache
        $this->cache->tags(["user:{$data['user_id']}"])->flush();

        return $this->find($id);
    }
}
```

### 5. Fragment Caching (View Caching)

```php
<?php
// src/View/CachedView.php

namespace App\View;

use App\Service\CacheService;

final class CachedView
{
    public function __construct(
        private CacheService $cache
    ) {}

    public function render(string $template, array $data = [], ?int $ttl = null): string
    {
        if ($ttl === null) {
            return $this->renderTemplate($template, $data);
        }

        $cacheKey = 'view:' . md5($template . serialize($data));

        return $this->cache->remember(
            key: $cacheKey,
            ttl: $ttl,
            callback: fn() => $this->renderTemplate($template, $data)
        );
    }

    private function renderTemplate(string $template, array $data): string
    {
        extract($data);
        ob_start();
        include __DIR__ . "/../../views/{$template}.php";
        return ob_get_clean();
    }
}

// Usage
Route::get('/dashboard', function() use ($view) {
    $stats = $view->render('partials/stats', ['user' => $user], ttl: 300);
    $posts = PostRepository::latest();

    return Response::create(
        $view->render('dashboard', ['stats' => $stats, 'posts' => $posts]),
        200,
        ['Content-Type' => 'text/html; charset=UTF-8']
    );
});
```

---

## Cache Patterns

### 1. Cache-Aside (Lazy Loading)

```php
public function getUser(int $id): array
{
    $cacheKey = "user:{$id}";

    // Try cache first
    $user = $this->cache->get($cacheKey);

    if ($user === null) {
        // Cache miss - load from database
        $user = DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]);

        // Store in cache
        $this->cache->set($cacheKey, $user, 3600);
    }

    return $user;
}
```

### 2. Write-Through Cache

```php
public function updateUser(int $id, array $data): array
{
    // Update database
    DB::update('users', $data, ['id' => $id]);

    // Update cache immediately
    $user = DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
    $this->cache->set("user:{$id}", $user, 3600);

    return $user;
}
```

### 3. Cache Warming

```php
// scripts/warm-cache.php

use App\Repository\UserRepository;
use App\Repository\PostRepository;

// Warm user cache
$popularUsers = DB::query('SELECT id FROM users ORDER BY followers DESC LIMIT 100');
foreach ($popularUsers as $user) {
    UserRepository::find($user['id']);  // Loads into cache
}

// Warm post cache
$latestPosts = DB::query('SELECT id FROM posts ORDER BY created_at DESC LIMIT 100');
foreach ($latestPosts as $post) {
    PostRepository::find($post['id']);
}

echo "Cache warmed\n";
```

### 4. Cache Stampede Prevention

```php
public function getExpensiveData(): array
{
    $cacheKey = 'expensive:data';
    $lockKey = 'lock:expensive:data';

    $data = $this->cache->get($cacheKey);

    if ($data === null) {
        // Try to acquire lock
        if ($this->cache->add($lockKey, true, 10)) {  // 10 second lock
            try {
                // We got the lock - compute the data
                $data = $this->computeExpensiveData();
                $this->cache->set($cacheKey, $data, 600);
            } finally {
                $this->cache->delete($lockKey);
            }
        } else {
            // Someone else is computing - wait and retry
            sleep(1);
            return $this->getExpensiveData();
        }
    }

    return $data;
}
```

### 5. Probabilistic Early Expiration

Prevents cache stampede by refreshing before expiration:

```php
public function get(string $key, int $ttl, callable $callback): mixed
{
    $value = $this->cache->get($key);

    if ($value !== null) {
        // Check if we should refresh early
        $beta = 1.0;
        $now = time();
        $created = $this->cache->get("{$key}:created") ?? $now;
        $delta = $created + $ttl - $now;

        // Probabilistic early refresh
        if ($delta - $beta * log(random_int(1, PHP_INT_MAX) / PHP_INT_MAX) <= 0) {
            $value = $callback();
            $this->cache->set($key, $value, $ttl);
            $this->cache->set("{$key}:created", $now, $ttl);
        }

        return $value;
    }

    // Cache miss
    $value = $callback();
    $this->cache->set($key, $value, $ttl);
    $this->cache->set("{$key}:created", time(), $ttl);

    return $value;
}
```

---

## Testing

### Unit Test

```php
<?php

use PHPUnit\Framework\TestCase;
use App\Service\CacheService;

class CacheServiceTest extends TestCase
{
    public function testRememberCachesValue(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with('test:key')
            ->willReturn(null);

        $cache->expects($this->once())
            ->method('set')
            ->with('test:key', 'computed', 60);

        $service = new CacheService($cache);

        $callCount = 0;
        $callback = function() use (&$callCount) {
            $callCount++;
            return 'computed';
        };

        $result = $service->remember('test:key', 60, $callback);

        $this->assertEquals('computed', $result);
        $this->assertEquals(1, $callCount);
    }

    public function testRememberReturnsCachedValue(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with('test:key')
            ->willReturn('cached');

        $cache->expects($this->never())
            ->method('set');

        $service = new CacheService($cache);

        $callCount = 0;
        $callback = function() use (&$callCount) {
            $callCount++;
            return 'computed';
        };

        $result = $service->remember('test:key', 60, $callback);

        $this->assertEquals('cached', $result);
        $this->assertEquals(0, $callCount);  // Callback not called
    }
}
```

---

## Best Practices

### 1. Use Meaningful Keys

```php
// ❌ Bad: Unclear keys
$cache->get('u1');
$cache->get('data');

// ✅ Good: Clear, namespaced keys
$cache->get('user:1');
$cache->get('posts:user:1:latest');
```

### 2. Set Appropriate TTLs

```php
// Frequently changing data
$cache->set('stats:online_users', $count, 10);  // 10 seconds

// Moderate change rate
$cache->set('posts:latest', $posts, 300);  // 5 minutes

// Rarely changing
$cache->set('config:settings', $settings, 3600);  // 1 hour

// Quasi-static
$cache->set('countries:list', $countries, 86400);  // 24 hours
```

### 3. Always Invalidate on Write

```php
public function updatePost(int $id, array $data): void
{
    DB::update('posts', $data, ['id' => $id]);

    // Invalidate specific post
    $this->cache->forget("post:{$id}");

    // Invalidate lists
    $this->cache->forget('posts:latest');
    $this->cache->forget("posts:user:{$data['user_id']}");
}
```

### 4. Handle Cache Failures Gracefully

```php
public function getUser(int $id): array
{
    try {
        $user = $this->cache->remember(
            "user:{$id}",
            3600,
            fn() => DB::queryOne('SELECT * FROM users WHERE id = ?', [$id])
        );
    } catch (\Exception $e) {
        // Cache failed - log and continue without cache
        $this->logger->error('Cache error', ['exception' => $e]);
        $user = DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    return $user;
}
```

### 5. Monitor Cache Hit Rates

```php
final class CacheMetrics
{
    private static int $hits = 0;
    private static int $misses = 0;

    public static function recordHit(): void
    {
        self::$hits++;
    }

    public static function recordMiss(): void
    {
        self::$misses++;
    }

    public static function getHitRate(): float
    {
        $total = self::$hits + self::$misses;
        return $total > 0 ? self::$hits / $total : 0;
    }
}

// In cache service
public function get(string $key): mixed
{
    $value = $this->cache->get($key);

    if ($value !== null) {
        CacheMetrics::recordHit();
    } else {
        CacheMetrics::recordMiss();
    }

    return $value;
}
```

---

## Summary

**This recipe provides**:
- ✅ Flexible caching service with remember pattern
- ✅ HTTP response caching middleware
- ✅ Query result caching
- ✅ Tag-based cache invalidation
- ✅ Fragment/view caching
- ✅ Cache stampede prevention
- ✅ Multiple caching patterns

**Production checklist**:
- [ ] Use Redis or Memcached for production
- [ ] Set appropriate TTLs per data type
- [ ] Implement cache invalidation on writes
- [ ] Handle cache failures gracefully
- [ ] Monitor cache hit rates
- [ ] Use cache tags for complex invalidation
- [ ] Implement cache warming for critical data
- [ ] Add cache versioning for schema changes
