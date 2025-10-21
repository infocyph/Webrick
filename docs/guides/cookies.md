# Cookies & Encryption

Read and set cookies safely. Optionally enable transparent encryption so values at rest (client-side) are unreadable without your key.

---

## Reading cookies (request)

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

Route::get('/cookie/read', function (Request $r) {
    return Response::json([
        'all'    => $r->getCookieParams(),
        'demo'   => $r->cookie('demo'),   // null if missing
    ]);
});
```php

If **CookieEncryptionMiddleware** is enabled, `cookie('name')` returns the **decrypted** value.

---

## Setting cookies (response)

Set cookies explicitly via `Set-Cookie` header (clear & PSR friendly):

```php
use Infocyph\Webrick\Response\Response;

Route::get('/cookie/set', function () {
    $cookie = rawurlencode('demo') . '=' . rawurlencode('secret-value')
            . '; Path=/; HttpOnly; SameSite=Lax; Secure';
    return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $cookie);
});
```php

**Attributes you’ll commonly use**

* `Path=/` – cookie visible to entire site
* `Domain=example.com` – cross-subdomain sharing if needed
* `Expires=Wed, 31 Dec 2030 23:59:59 GMT` / `Max-Age=3600` – persistence
* `HttpOnly` – hide from JS (mitigates XSS cookie theft)
* `Secure` – HTTPS only
* `SameSite=Lax|Strict|None` – CSRF boundary (set `None` only with `Secure`)

---

## Encrypted cookies (optional)

Enable the middleware once (pre-global) and pass a secret key:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\CookieEncryptionMiddleware(
    $_ENV['WEBRICK_COOKIE_KEY'] ?? 'change-me'
);
```php

* **Write** cookies as usual (plain).
* **Read** via `$r->cookie('name')` → decrypted value if that cookie was written encrypted.
* Rotation: deploy a new key and keep the previous key for a grace period if the middleware supports multi-key decryption; otherwise rotate during a maintenance window.

> Use **encrypted cookies** for any value you don’t want end users to read or tamper with (session info, feature flags with risks). Pair with server-side verification when integrity matters.

---

## Deleting cookies

Overwrite with an expired cookie (and same scope):

```php
Route::get('/cookie/clear', function () {
    $expired = 'demo=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax';
    return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $expired);
});
```php

---

## Scoped cookies (subdomains & paths)

* **Path scoping**: `Path=/admin` limits visibility to `/admin/*`.
* **Domain scoping**: `Domain=.example.com` shares across subdomains (use carefully).
* For **domain-scoped routes** (e.g., `api.example.com`), set the cookie domain explicitly to match where the browser should send it.

---

## Security notes

* Treat cookies as **untrusted input** on the server; validate and sanitize even when encrypted.
* Don’t store PII or secrets client-side unless absolutely necessary and encrypted.
* Use `HttpOnly` + `Secure` + `SameSite` appropriately (often `Lax` for app cookies; `Strict` for sensitive flows).
* For login/CSRF-sensitive POST routes, combine SameSite with CSRF tokens.

---

## Examples

### Flash message via cookie

```php
// set
Route::post('/login', function () {
    $c = 'flash=' . rawurlencode('Welcome back!')
       . '; Path=/; Max-Age=10; HttpOnly; SameSite=Lax';
    return Response::redirect('/', 303)->withAddedHeader('Set-Cookie', $c);
});

// read & clear
Route::get('/', function (Request $r) {
    $msg = $r->cookie('flash');
    $clear = 'flash=; Path=/; Max-Age=0';
    return Response::json(['flash'=>$msg])->withAddedHeader('Set-Cookie', $clear);
});
```php

### Remember-me (hint)

Use a **signed, encrypted** token (not just a user ID). Validate server-side and rotate on use.


---

## Advanced Cookie Patterns

### Session Cookies with Rotation
```php
// Create session with automatic rotation
Route::post('/login', function (Request $r) {
    $email = $r->input('email');
    $password = $r->input('password');

    // Validate credentials...
    $user = AuthService::attempt($email, $password);

    if (!$user) {
        return Response::json(['error' => 'Invalid credentials'], 401);
    }

    // Generate session ID
    $sessionId = bin2hex(random_bytes(32));

    // Store session server-side
    Cache::put("session:{$sessionId}", [
        'user_id' => $user->id,
        'created_at' => time(),
        'ip' => $r->getAttribute('client_ip'),
        'user_agent' => $r->getHeaderLine('User-Agent')
    ], 3600);  // 1 hour

    // Set cookie
    $cookie = 'sess=' . $sessionId
            . '; Path=/; HttpOnly; Secure; SameSite=### Remember Me (Long-Lived Token)
```php
Route::post('/login', function (Request $r) {
    $user = AuthService::attempt($r->input('email'), $r->input('password'));

    $cookies = [];

    // Short-lived session
    $sessionId = bin2hex(random_bytes(32));
    Cache::put("session:{$sessionId}", ['user_id' => $user->id], 3600);
    $cookies[] = 'sess=' . $sessionId . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600';

    // Long-lived remember token (if requested)
    if ($r->input('remember')) {
        $rememberToken = bin2hex(random_bytes(32));

        // Store hashed token in database
        DB::insert('remember_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rememberToken),
            'expires_at' => date('Y-m-d H:i:s', time() + 2592000)  // 30 days
        ]);

        $cookies[] = 'remember=' . $rememberToken
                   . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000';
    }

    $response = Response::json(['user' => $user]);
    foreach ($cookies as $cookie) {
        $response = $response->withAddedHeader('Set-Cookie', $cookie);
    }

    return $response;
});

// Auto-login from remember token
final class RememberMeMiddleware
{
    public function __invoke(Request $r, Closure $next): Response
    {
        // Skip if already authenticated
        if ($r->getAttribute('auth.user_id')) {
            return $next($r);
        }

        $rememberToken = $r->cookie('remember');
        if (!$rememberToken) {
            return $next($r);
        }

        // Find token in database
        $tokenHash = hash('sha256', $rememberToken);
        $record = DB::queryOne(
            'SELECT * FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW()',
            [$tokenHash]
        );

        if (!$record) {
            // Invalid/expired - clear cookie
            return $next($r)->withAddedHeader('Set-Cookie',
                'remember=; Path=/; Max-Age=0'
            );
        }

        // Create new session
        $sessionId = bin2hex(random_bytes(32));
        Cache::put("session:{$sessionId}", ['user_id' => $record['user_id']], 3600);

        $r = $r->withAttribute('auth.user_id', $record['user_id']);

        return $next($r)->withAddedHeader('Set-Cookie',
            'sess=' . $sessionId . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600'
        );
    }
}
```php

### Cookie Prefixes for Security

Use `__Host-` and `__Secure-` prefixes for enhanced security:
```php
// __Host- prefix (most secure)
// - Must have Secure flag
// - Must have Path=/
// - Must NOT have Domain
$cookie = '__Host-sess=' . $sessionId
        . '; Path=/; Secure; HttpOnly; SameSite=Strict';

// __Secure- prefix (secure only)
// - Must have Secure flag
// - Can have Domain and Path
$cookie = '__Secure-sess=' . $sessionId
        . '; Path=/; Secure; HttpOnly; SameSite=Lax; Domain=.example.com';

// Browser enforces these rules and won't accept cookies that violate them
```php

**Benefits**:
- Prevents subdomain attacks
- Forces HTTPS-only cookies
- Clear security intent

### Cookie Chunking (Large Values)

When cookie value exceeds browser limits (~4KB):
```php
final class CookieChunker
{
    private const CHUNK_SIZE = 3800;  // Leave room for name + attributes

    public static function set(Response $resp, string $name, string $value, string $attributes): Response
    {
        $chunks = str_split($value, self::CHUNK_SIZE);

        foreach ($chunks as $index => $chunk) {
            $chunkName = "{$name}_{$index}";
            $cookie = $chunkName . '=' . rawurlencode($chunk) . '; ' . $attributes;
            $resp = $resp->withAddedHeader('Set-Cookie', $cookie);
        }

        // Store chunk count
        $cookie = "{$name}_count=" . count($chunks) . '; ' . $attributes;
        return $resp->withAddedHeader('Set-Cookie', $cookie);
    }

    public static function get(Request $r, string $name): ?string
    {
        $count = (int)$r->cookie("{$name}_count");
        if ($count === 0) {
            return null;
        }

        $chunks = [];
        for ($i = 0; $i < $count; $i++) {
            $chunk = $r->cookie("{$name}_{$i}");
            if ($chunk === null) {
                return null;  // Incomplete
            }
            $chunks[] = $chunk;
        }

        return implode('', $chunks);
    }

    public static function clear(Response $resp, string $name, int $maxChunks = 10): Response
    {
        for ($i = 0; $i < $maxChunks; $i++) {
            $cookie = "{$name}_{$i}=; Path=/; Max-Age=0";
            $resp = $resp->withAddedHeader('Set-Cookie', $cookie);
        }

        $cookie = "{$name}_count=; Path=/; Max-Age=0";
        return $resp->withAddedHeader('Set-Cookie', $cookie);
    }
}

// Usage
$response = CookieChunker::set(
    $response,
    'large_data',
    $largeString,
    'Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600'
);

$value = CookieChunker::get($request, 'large_data');
```php

⚠️ **Better Alternative**: Store large data server-side and use a small cookie as a pointer.

### Cross-Domain Cookies (Subdomains)

Share cookies across subdomains:
```php
// Set on example.com, accessible to app.example.com and api.example.com
$cookie = 'shared=' . $value
        . '; Path=/; Domain=.example.com; Secure; HttpOnly; SameSite=Lax';
return $response->withAddedHeader('Set-Cookie', $cookie);
```php

**Security Considerations**:
- All subdomains can read/write the cookie
- Compromise of any subdomain = compromise of cookie
- Use only for non-sensitive data or with additional verification

### Cookie Priorities (Chrome)

Control eviction order when browser runs out of space:
```php
// High priority (kept longest)
$cookie = 'important=' . $value . '; Path=/; Priority=High';

// Medium priority (default)
$cookie = 'normal=' . $value . '; Path=/; Priority=Medium';

// Low priority (evicted first)
$cookie = 'cache=' . $value . '; Path=/; Priority=Low';
```php

**Use Cases**:
- High: Authentication, CSRF tokens
- Medium: User preferences
- Low: Analytics, A/B test variants

---

## Cookie Security Best Practices

### 1. Always Use `HttpOnly` for Session Cookies

Prevents JavaScript access (mitigates XSS):
```php
// ✅ Good
$cookie = 'sess=' . $id . '; HttpOnly; Secure; SameSite=Strict';

// ❌ Bad (JS can read via document.cookie)
$cookie = 'sess=' . $id . '; Secure; SameSite=Strict';
```php

### 2. Always Use `Secure` in Production

Forces HTTPS-only transmission:
```php
// Conditional based on environment
$secure = $_ENV['APP_ENV'] === 'production' ? '; Secure' : '';
$cookie = 'sess=' . $id . '; HttpOnly; SameSite=Lax' . $secure;

// Or use CookieEncryptionMiddleware with forceSecure: true
```php

### 3. Use `SameSite` Appropriately

| Value    | Behavior                                   | Use Case                   |
| -------- | ------------------------------------------ | -------------------------- |
| `Strict` | Never sent on cross-site requests          | High-security (banking)    |
| `Lax`    | Sent on top-level navigations (GET only)   | Most web apps (default)    |
| `None`   | Always sent (requires `Secure`)            | Embedded widgets, OAuth    |
```php
// Strict (most secure)
Route::post('/transfer-funds', /* ... */, [
    'middleware' => [function($r, $next) {
        $cookie = 'csrf=' . bin2hex(random_bytes(16))
                . '; HttpOnly; Secure; SameSite=Strict';
        return $next($r)->withAddedHeader('Set-Cookie', $cookie);
    }]
]);

// Lax (standard)
$cookie = 'sess=' . $id . '; HttpOnly; Secure; SameSite=Lax';

// None (cross-origin embedding)
$cookie = 'widget_state=' . $state . '; Secure; SameSite=None';
```php

### 4. Limit Cookie Scope

Use specific `Path` and avoid broad `Domain`:
```php
// ✅ Good: Scoped to /admin
$cookie = 'admin_sess=' . $id . '; Path=/admin; HttpOnly; Secure; SameSite=Strict';

// ❌ Bad: Accessible everywhere
$cookie = 'admin_sess=' . $id . '; Path=/; HttpOnly; Secure; SameSite=Strict';

// ✅ Good: No Domain (current origin only)
$cookie = 'sess=' . $id . '; Path=/; HttpOnly; Secure; SameSite=Lax';

// ⚠️ Risky: Shared across all subdomains
$cookie = 'sess=' . $id . '; Path=/; Domain=.example.com; HttpOnly; Secure; SameSite=Lax';
```php

### 5. Set Appropriate Expiration
```php
// Session cookie (expires when browser closes)
$cookie = 'sess=' . $id . '; Path=/; HttpOnly; Secure; SameSite=Lax';

// Persistent cookie (30 days)
$cookie = 'remember=' . $token . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000';

// Short-lived (5 minutes)
$cookie = 'otp_challenge=' . $challenge . '; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=300';
```php

### 6. Validate Cookie Integrity
```php
// Don't trust cookie values blindly
$userId = $r->cookie('user_id');  // ❌ Dangerous

// Verify against server-side session
$sessionId = $r->cookie('sess');
$session = Cache::get("session:{$sessionId}");

if ($session && $session['user_id'] === $userId) {
    // ✅ Safe
}
```php

### 7. Rotate Session IDs

After privilege escalation or sensitive operations:
```php
Route::post('/change-password', function(Request $r) {
    $oldSessionId = $r->cookie('sess');

    // Change password...

    // Invalidate old session
    Cache::forget("session:{$oldSessionId}");

    // Create new session
    $newSessionId = bin2hex(random_bytes(32));
    Cache::put("session:{$newSessionId}", ['user_id' => $userId], 3600);

    return Response::json(['success' => true])
        ->withAddedHeader('Set-Cookie',
            'sess=' . $newSessionId . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600'
        );
});
```php

---

## Testing Cookies

### Unit Test
```php
use PHPUnit\Framework\TestCase;

class CookieTest extends TestCase
{
    public function testSetsCookieWithCorrectAttributes(): void
    {
        $response = Response::json(['ok' => true])
            ->withAddedHeader('Set-Cookie',
                'test=value; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600'
            );

        $setCookie = $response->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('test=value', $setCookie);
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('Secure', $setCookie);
        $this->assertStringContainsString('SameSite=Lax', $setCookie);
    }

    public function testReadsCookieFromRequest(): void
    {
        $request = Request::create('GET', '/', server: [
            'HTTP_COOKIE' => 'sess=abc123; theme=dark'
        ]);

        $this->assertEquals('abc123', $request->cookie('sess'));
        $this->assertEquals('dark', $request->cookie('theme'));
        $this->assertNull($request->cookie('nonexistent'));
    }
}
```php

### Integration Test
```bash
#!/bin/bash
# test-cookies.sh

set -e

BASE_URL="http://localhost:8000"

echo "Testing cookie functionality..."

# Test 1: Set cookie
RESPONSE=$(curl -s -c cookies.txt -b cookies.txt "$BASE_URL/login" \
  -d "email=test@example.com&password=secret" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
if [ "$HTTP_CODE" = "200" ]; then
    echo "✅ Cookie set on login"
else
    echo "❌ Login failed (HTTP $HTTP_CODE)"
    exit 1
fi

# Test 2: Cookie persists
RESPONSE=$(curl -s -b cookies.txt "$BASE_URL/profile" -w "\n%{http_code}")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)

if [ "$HTTP_CODE" = "200" ]; then
    echo "✅ Cookie persists across requests"
else
    echo "❌ Cookie not sent or invalid (HTTP $HTTP_CODE)"
    exit 1
fi

# Test 3: Cookie cleared on logout
curl -s -c cookies.txt -b cookies.txt "$BASE_URL/logout" > /dev/null

RESPONSE=$(curl -s -b cookies.txt "$BASE_URL/profile" -w "\n%{http_code}")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)

if [ "$HTTP_CODE" = "401" ]; then
    echo "✅ Cookie cleared on logout"
else
    echo "❌ Cookie still valid after logout (HTTP $HTTP_CODE)"
    exit 1
fi

rm -f cookies.txt
echo "✅ All cookie tests passed"
```php

---

## Browser DevTools Inspection

### Chrome/Edge DevTools

1. Open DevTools (F12)
2. Go to **Application** tab
3. Expand **Cookies** in left sidebar
4. Select your domain

**What to Check**:
- ✅ `HttpOnly` column is checked for session cookies
- ✅ `Secure` column is checked (in production)
- ✅ `SameSite` is set appropriately
- ✅ `Expires / Max-Age` matches expectation
- ✅ `Path` is scoped correctly
- ✅ `Domain` is minimal (or blank for same-origin)

### Firefox DevTools

1. Open DevTools (F12)
2. Go to **Storage** tab
3. Expand **Cookies**
4. Select your domain

### Cookie Debugging Headers

Add debug headers in development:
```php
if ($_ENV['APP_ENV'] === 'development') {
    $response = $response->withHeader('X-Debug-Cookies', json_encode([
        'sent' => $request->getCookieParams(),
        'set' => $response->getHeader('Set-Cookie')
    ]));
}
```php


---

## Checklist

* [ ] Prefer `HttpOnly; Secure; SameSite` on all cookies
* [ ] Encrypt sensitive values with CookieEncryptionMiddleware
* [ ] Keep cookie **scope** minimal (Path/Domain)
* [ ] Clear cookies by setting `Max-Age=0` / past `Expires`
* [ ] Never trust cookie contents without validation


---

## Related Resources

- [Cookie Encryption Middleware](../middleware/cookie-encryption.md) - Transparent encryption
- [Security Guide](../advanced/security.md) - OWASP Top 10 and cookie security
- [Requests](./requests.md) - Reading cookies from requests
- [Responses](./responses.md) - Setting cookies on responses

