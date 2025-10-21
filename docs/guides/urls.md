# Signed & Temporary URLs

This guide shows how to generate and verify signed URLs in Webrick. It matches the actual code surface:

- URL services are bound at boot time.
- Helpers live on `Infocyph\Webrick\Response\Response` as `urlFor()`, `signedUrlFor()`, and `temporaryUrlFor()`.
- Verification is done via the middleware alias `verifySignedUrl`.

## Prerequisites

At boot, enable URL services and configure signing:

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Response\Response as R;

$kernel = RouterKernel::bootWithRegistrar(
    ShardedMatcher::make(__DIR__.'/var/route-cache'),
    require __DIR__.'/routes.php',
    registrarOptions: [
        'autoSlashRedirect' => true,
        'exposeUrlServices' => true,                  // <- exposes urlFor/signedUrlFor on Response
        'signKey'          => getenv('WEBRICK_SIGN_KEY') ?: 'dev-key-change-me',
        'signedDefaultTtl' => 300,                    // seconds; used by temporaryUrlFor when TTL omitted
        'fallbackAliasesFromRegistrar' => true,
    ]
);

// Optional explicit bind (if not using registrar options)
R::bindUrlServices(
    signKey: getenv('WEBRICK_SIGN_KEY') ?: 'dev-key-change-me',
    defaultTtl: 300
);
```

## Defining a named route

```php
use Infocyph\Webrick\Router\Route;
use Infocyph\Webrick\Response\Response as R;

Route::get('/download/{file}', function (string $file) {
    // Only return if signature verified (see middleware below)
    return R::attachment(__DIR__.'/files/'.$file);
})->name('file.download')->middleware(['verifySignedUrl']);
```

## Generating URLs

```php
// Plain URL for a named route (substitute params)
$url = R::urlFor('file.download', ['file' => 'report.pdf']);

// Signed URL (no expiry)
$signed = R::signedUrlFor('file.download', ['file' => 'report.pdf']);

// Temporary URL (expires in 15 minutes)
$temp = R::temporaryUrlFor('file.download', ['file' => 'report.pdf'], ttl: 900);
```

The `verifySignedUrl` middleware checks the signature and (for temporary URLs) expiry timestamp.
On failure, it returns `403 Forbidden` with a clear reason (bad signature / expired).

### cURL testing

```bash
curl -i "$temp"
# Expect 200 before expiry, 403 after TTL
```


---

## Troubleshooting Signed URLs

### Error: "Missing signature"

**Cause**: Query string was stripped or not passed through by proxy/CDN.

**Fix**: Ensure your web server preserves query parameters:

**Nginx**:
```nginx
location / {
    try_files $uri /index.php?$query_string;  # ← Critical: $query_string
}
```

**Apache**:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]  # ← QSA = Query String Append
```

**Test**:
```bash
# Generate signed URL
php -r "require 'vendor/autoload.php'; echo Response::signedUrlFor('test');"

# Output: /test?_sig=abc123...

# Test it preserves query
curl -v "http://localhost:8000/test?_sig=abc123&foo=bar" 2>&1 | grep "GET /test"
# Should show: GET /test?_sig=abc123&foo=bar
```

---

### Error: "Invalid signature"

**Common Causes**:

#### 1. Key Mismatch
```php
// Generator uses one key
Response::bindUrlServices($routes, 'key-A', 900);
$url = Response::signedUrlFor('test');

// Verifier uses different key
new VerifySignedUrlMiddleware('key-B', leeway: 5);  // ❌ Won't match
```

**Fix**: Ensure both use the same key:
```php
$signKey = $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev-key';

// Generator
Response::bindUrlServices($routes, $signKey, 900);

// Verifier
new VerifySignedUrlMiddleware($signKey, leeway: 5);
```

#### 2. Query Parameters Modified

**Proxy/CDN changed the URL**:
```
Generated:  /download?file=report.pdf&_sig=abc123
Received:   /download?_sig=abc123&file=report.pdf  # Order changed
```

**Fix**: Signature is order-sensitive. Ensure proxies don't reorder parameters:
```nginx
# Nginx: Don't normalize query string
# (Default behavior preserves order)
```

#### 3. URL Encoding Issues

**Generator uses `rawurlencode()`, proxy decodes then re-encodes differently**:
```php
// Generated (correct)
/file?name=my%20file.pdf&_sig=...

// After proxy "normalization"
/file?name=my+file.pdf&_sig=...  # Space encoding changed
```

**Fix**: Use consistent encoding or configure proxy to not touch query strings.

#### 4. Additional Parameters Added

**Someone added parameters after generation**:
```php
$url = Response::signedUrlFor('download', ['id' => 42]);
// /download?id=42&_sig=abc123

// Later, tracking param added manually
$trackedUrl = $url . '&utm_source=email';  // ❌ Breaks signature
```

**Fix**: Add all parameters during generation:
```php
$url = Response::signedUrlFor('download',
    ['id' => 42],
    query: ['utm_source' => 'email']  // ✅ Included in signature
);
```

---

### Error: "URL expired" (410 Gone)

**Cause**: TTL passed or clock skew between generator and verifier.

**Debug**:
```php
Route::get('/debug-signed', function (Request $r) {
    $sig = $r->query('_sig');
    $exp = $r->query('_exp');

    return Response::json([
        'has_signature' => !empty($sig),
        'expires_at' => $exp,
        'expires_human' => $exp ? date('Y-m-d H:i:s', (int)$exp) : null,
        'now' => time(),
        'now_human' => date('Y-m-d H:i:s'),
        'expired' => $exp && time() > (int)$exp,
        'ttl_remaining' => $exp ? max(0, (int)$exp - time()) : null,
        'server_timezone' => date_default_timezone_get()
    ]);
});
```

**Clock Skew Fix**:
```php
// Add leeway (tolerance) for clock differences
new VerifySignedUrlMiddleware(
    signKey: $signKey,
    leeway: 30  // 30 seconds tolerance
);

// If generator is 10s ahead and verifier has 30s leeway:
// URL valid for: TTL + 30s
```

**NTP Sync** (production):
```bash
# Ensure clocks are synchronized
timedatectl status

# Install NTP
apt-get install ntp
systemctl enable ntp
systemctl start ntp
```

---

### Error: Signature works locally but fails in production

**Causes**:

#### 1. Different Environment Keys
```bash
# Local .env
WEBRICK_SIGN_KEY="dev-key-123"

# Production .env (different!)
WEBRICK_SIGN_KEY="prod-key-456"
```

**URLs generated locally won't work in production.**

**Fix**: Use the same key across environments OR generate URLs dynamically in the target environment.

#### 2. Load Balancer Modifies Headers/Query

**Some load balancers normalize URLs.**

**Test**:
```bash
# Direct to app server
curl -v http://app-server-1:8000/test?_sig=abc123

# Through load balancer
curl -v http://lb.example.com/test?_sig=abc123

# Compare query strings in logs
```

**Fix**: Configure load balancer to preserve exact query strings.

#### 3. HTTPS/HTTP Scheme Mismatch
```php
// Generated with absolute URL (HTTPS)
$url = Response::signedUrlFor('download', absolute: true);
// https://example.com/download?_sig=...

// User accesses via HTTP (redirected or downgraded)
// http://example.com/download?_sig=...
```

If signature includes the scheme, mismatch will fail.

**Fix**: Generate relative URLs or ensure consistent HTTPS enforcement:
```php
// Relative URLs (scheme-agnostic)
$url = Response::signedUrlFor('download', absolute: false);
```

---

### Security Considerations

#### 1. Signature Lifetime

**Too long**: Increases exposure if leaked.
**Too short**: Poor UX if users are slow.

**Recommendations**:

| Use Case                 | TTL       |
| ------------------------ | --------- |
| Email verification links | 24 hours  |
| Password reset           | 1 hour    |
| Download links (paid)    | 15-30 min |
| One-time admin actions   | 5 min     |
| Share links              | 7 days    |

#### 2. Rate Limiting Signed Routes

Even with valid signatures, protect against abuse:
```php
Route::get('/download/{id}', function(int $id) {
    // ...
}, [
    'middleware' => [
        'verifySignedUrl',
        'throttle:10,60'  // Max 10 downloads per minute
    ]
]);
```

#### 3. Signature Reuse Prevention

For **one-time actions** (delete, transfer funds), use additional nonce:
```php
// Generate with nonce
$nonce = bin2hex(random_bytes(16));
$url = Response::temporaryUrlFor('delete-account',
    ['id' => $userId],
    query: ['nonce' => $nonce],
    ttl: 300
);

// Store nonce in database/cache with expiry
Cache::put("nonce:{$nonce}", true, 300);

// Verify in middleware
Route::post('/delete-account/{id}', function(Request $r, int $id) {
    $nonce = $r->query('nonce');

    if (!Cache::has("nonce:{$nonce}")) {
        return Response::json(['error' => 'Invalid or used link'], 410);
    }

    // Mark nonce as used (delete from cache)
    Cache::forget("nonce:{$nonce}");

    // Proceed with action...
}, ['middleware' => ['verifySignedUrl']]);
```

#### 4. IP Binding (Optional High Security)

Bind signature to requester's IP:
```php
// Custom signed URL with IP
function signedUrlWithIp(string $routeName, array $params = []): string {
    $ip = request()->getAttribute('client_ip');
    $base = Response::signedUrlFor($routeName, $params);

    // Add IP to query (included in signature)
    return $base . '&ip=' . urlencode($ip);
}

// Verify in middleware
Route::get('/sensitive', function(Request $r) {
    $signedIp = $r->query('ip');
    $currentIp = $r->getAttribute('client_ip');

    if ($signedIp !== $currentIp) {
        return Response::json(['error' => 'IP mismatch'], 403);
    }

    // Proceed...
}, ['middleware' => ['verifySignedUrl']]);
```

⚠️ **Caveat**: Breaks for users behind proxies with rotating IPs (mobile networks, corporate).

---

### Testing Signed URLs

#### Unit Test
```php
use PHPUnit\Framework\TestCase;

class SignedUrlTest extends TestCase
{
    public function testValidSignatureAccepted(): void
    {
        $key = 'test-key';
        Response::bindUrlServices($routes, $key, 900);

        $url = Response::signedUrlFor('test');

        $middleware = new VerifySignedUrlMiddleware($key, leeway: 5);
        $request = Request::create('GET', $url);

        $response = $middleware($request, fn($r) => Response::json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTamperedSignatureRejected(): void
    {
        $url = Response::signedUrlFor('test');
        $tampered = preg_replace('/_sig=[^&]+/', '_sig=invalid', $url);

        $request = Request::create('GET', $tampered);
        $response = $middleware($request, fn($r) => Response::json(['ok' => true]));

        $this->assertEquals(403, $response->getStatusCode());
    }
}
```

#### Integration Test
```bash
#!/bin/bash
# test-signed-urls.sh

set -e

BASE_URL="http://localhost:8000"

echo "Testing signed URLs..."

# Generate signed URL (requires PHP script or API endpoint)
SIGNED_URL=$(php -r "
require 'vendor/autoload.php';
echo Response::signedUrlFor('test.route');
")

echo "Generated: $SIGNED_URL"

# Test valid signature
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}${SIGNED_URL}")
if [ "$HTTP_CODE" = "200" ]; then
    echo "✅ Valid signature accepted"
else
    echo "❌ Valid signature rejected (HTTP $HTTP_CODE)"
    exit 1
fi

# Test tampered signature
TAMPERED=$(echo "$SIGNED_URL" | sed 's/_sig=[^&]*/_sig=invalid/')
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}${TAMPERED}")
if [ "$HTTP_CODE" = "403" ]; then
    echo "✅ Tampered signature rejected"
else
    echo "❌ Tampered signature not rejected (HTTP $HTTP_CODE)"
    exit 1
fi

echo "✅ All tests passed"
```

---

### Performance Considerations

#### Signature Computation Cost

HMAC-SHA256 is fast (~0.01ms per signature), but avoid generating thousands in a loop:
```php
// ❌ Slow: Generate 1000 signed URLs
$urls = [];
for ($i = 0; $i < 1000; $i++) {
    $urls[] = Response::signedUrlFor('item', ['id' => $i]);
}

// ✅ Better: Generate base URL once, vary only params
$template = Response::urlFor('item', ['id' => '__ID__']);
$baseSignature = hash_hmac('sha256', $template, $signKey);

$urls = [];
for ($i = 0; $i < 1000; $i++) {
    $urls[] = str_replace('__ID__', $i, $template) . '&_sig=' . $baseSignature;
}
```

⚠️ **Note**: Above is pseudo-code. Actual implementation must sign the complete URL.

#### Caching Signed URLs

For **public, static resources** (CDN assets), you can cache signed URLs:
```php
$cacheKey = "signed_url:asset:{$assetId}";

$url = Cache::remember($cacheKey, 3600, function() use ($assetId) {
    return Response::temporaryUrlFor('cdn.asset', ['id' => $assetId], ttl: 3600);
});
```

---

### Monitoring & Alerts

Track signature failures to detect attacks or misconfigurations:
```php
// In VerifySignedUrlMiddleware (custom wrapper)
if ($signatureInvalid) {
    $logger->warning('Invalid signature attempt', [
        'ip' => $request->getAttribute('client_ip'),
        'path' => $request->getPath(),
        'query' => $request->getQueryParams(),
        'referer' => $request->getHeaderLine('Referer'),
        'user_agent' => $request->getHeaderLine('User-Agent')
    ]);

    // Alert if spike detected
    if ($this->getFailureRate() > 100) {  // 100/min
        $alerting->critical('Signature validation spike detected');
    }
}
```

**Metrics to track**:
- `signed_url_verifications_total{result="success|failure"}`
- `signed_url_generation_duration_seconds`
- `signed_url_expired_total`


## Tips

- Always **name** routes you intend to link from outside of the handler.
- Set `signedDefaultTtl` to a sane default (e.g. 5–15 minutes) at boot.
- If you reverse-proxy, ensure query strings are preserved exactly; signature is query-string sensitive.
- Prefer `temporaryUrlFor()` for privileged actions (downloads, one-time links) to reduce risk.
