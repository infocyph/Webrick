Cookie Encryption Middleware
============================

Comprehensive guide to reading, setting and encrypting cookies in Webrick. Covers both basic cookie operations and transparent encryption for sensitive data.

--------------

Table of Contents
-----------------

- `Basic Cookie Operations <#basic-cookie-operations>`__
- `Cookie Encryption Middleware <#cookie-encryption-middleware-1>`__
- `Configuration <#configuration>`__
- `Reading Cookies <#reading-cookies>`__
- `Setting Cookies <#setting-cookies>`__
- `Cookie Attributes <#cookie-attributes>`__
- `Advanced Patterns <#advanced-patterns>`__
- `Security Best Practices <#security-best-practices>`__
- `Testing <#testing>`__
- `Troubleshooting <#troubleshooting>`__

--------------

Basic Cookie Operations
-----------------------

Reading Cookies (Request)
~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   Route::get('/cookie/read', function (Request $r) {
       return Response::json([
           'all'    => $r->getCookieParams(),  // All cookies
           'demo'   => $r->cookie('demo'),     // Single cookie (null if missing)
       ]);
   });

**If CookieEncryptionMiddleware is enabled**, ``cookie()`` returns the **decrypted** value automatically.

Setting Cookies (Response)
~~~~~~~~~~~~~~~~~~~~~~~~~~

Set cookies explicitly via ``Set-Cookie`` header:

.. code:: php

   Route::get('/cookie/set', function () {
       $cookie = rawurlencode('demo') . '=' . rawurlencode('secret-value')
               . '; Path=/; HttpOnly; SameSite=Lax; Secure';

       return Response::json(['ok'=>true])
           ->withAddedHeader('Set-Cookie', $cookie);
   });

**Cookie Attributes**:

- ``Path=/`` — Cookie visible to entire site
- ``Domain=example.com`` — Cross-subdomain sharing
- ``Expires=<date>`` / ``Max-Age=<seconds>`` — Persistence
- ``HttpOnly`` — Hide from JavaScript (XSS protection)
- ``Secure`` — HTTPS only
- ``SameSite=Lax|Strict|None`` — CSRF boundary

Deleting Cookies
~~~~~~~~~~~~~~~~

Overwrite with expired cookie:

.. code:: php

   Route::get('/cookie/clear', function () {
       $expired = 'demo=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax';
       return Response::json(['ok'=>true])
           ->withAddedHeader('Set-Cookie', $expired);
   });

--------------

Cookie Encryption Middleware
----------------------------

Keep sensitive cookie values private by encrypting them transparently. The middleware decrypts on **request** and allows you to set plain values on **response**.

Why Encrypt Cookies?
~~~~~~~~~~~~~~~~~~~~

**Without Encryption**:

- ❌ Values visible in browser DevTools
- ❌ Can be tampered with by users
- ❌ Risk of session hijacking if tokens leaked

**With Encryption**:

- ✅ Values unreadable to end users
- ✅ Tampering detected (AEAD encryption)
- ✅ Automatic decryption in handlers
- ✅ Optional server-side storage for large values

--------------

Configuration
-------------

Wiring
~~~~~~

Place it in **pre-global** (before anything reads cookies):

.. code:: php

   use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
   use Psr\Cache\CacheItemPoolInterface;

   $cookieKey = $_ENV['WEBRICK_COOKIE_KEY']
       ?? throw new RuntimeException('WEBRICK_COOKIE_KEY is required');

   $preGlobal[] = new CookieEncryptionMiddleware(
       keyOrKeys: $cookieKey,
       cookiePrefix: 'enc_',           // Only encrypt cookies with this prefix
       maxBytes: 3_800,                // Per-cookie size limit (4KB browser limit)
       store: $cachePool,              // PSR-6 cache for server-side storage fallback
       storeTtl: 86_400,               // Server-side storage TTL (24 hours)
       dropOnDecryptFailure: true,     // Fail-closed: drop invalid cookies
       forceSecure: true,              // Enforce Secure attribute
       forceHttpOnly: true,            // Enforce HttpOnly attribute
       defaultSameSite: 'Lax'          // Default SameSite policy
   );

Constructor Parameters
~~~~~~~~~~~~~~~~~~~~~~

+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| Parameter                | Type                            | Default    | Description                                                 |
+==========================+=================================+============+=============================================================+
| ``keyOrKeys``            | ``string|array<string>``        | *required* | 32+ byte key(s) for AES-256-GCM encryption                  |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``cookiePrefix``         | ``string``                      | ``'enc_'`` | Only cookies starting with this prefix are encrypted        |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``maxBytes``             | ``int``                         | ``3800``   | Max cookie size before using server-side storage            |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``store``                | ``CacheItemPoolInterface|null`` | ``null``   | PSR-6 cache for storing large cookie values server-side     |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``storeTtl``             | ``int``                         | ``86400``  | TTL for server-side stored values (seconds)                 |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``dropOnDecryptFailure`` | ``bool``                        | ``true``   | Drop invalid cookies (true) or leave raw value (false)      |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``forceSecure``          | ``bool``                        | ``true``   | Enforce ``Secure`` attribute on encrypted cookies           |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``forceHttpOnly``        | ``bool``                        | ``true``   | Enforce ``HttpOnly`` attribute on encrypted cookies         |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+
| ``defaultSameSite``      | ``string``                      | ``'Lax'``  | Default ``SameSite`` policy (``Strict``, ``Lax``, ``None``) |
+--------------------------+---------------------------------+------------+-------------------------------------------------------------+

Recommended Middleware Order
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   $preGlobal = [
       // Early guards
       \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
       \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
       \Infocyph\Webrick\Middleware\MaintenanceModeMiddleware::class,
       \Infocyph\Webrick\Middleware\RequestLimitsMiddleware::class,
       \Infocyph\Webrick\Middleware\ThrottleMiddleware::class,

       // Cookie decryption (before handlers read cookies)
       new CookieEncryptionMiddleware(
           keyOrKeys: $_ENV['WEBRICK_COOKIE_KEY'],
           cookiePrefix: 'enc_'
       ),

       // Request normalization
       \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class,
       \Infocyph\Webrick\Middleware\InputSanitizerMiddleware::class,
       // ...
   ];

--------------

Reading Cookies
---------------

Basic Reading
~~~~~~~~~~~~~

.. code:: php

   Route::get('/profile', function (Request $r) {
       // If cookie was encrypted, this is automatically decrypted
       $sessionId = $r->cookie('enc_session');

       // Regular (non-encrypted) cookie
       $theme = $r->cookie('theme');

       return Response::json([
           'session' => $sessionId,  // Decrypted value
           'theme' => $theme
       ]);
   });

Reading All Cookies
~~~~~~~~~~~~~~~~~~~

.. code:: php

   $allCookies = $r->getCookieParams();
   // Encrypted cookies are automatically decrypted

--------------

Setting Cookies
---------------

Encrypted Cookies (Set Plain, Stored Encrypted)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   Route::post('/login', function (Request $r) {
       $user = authenticate($r->input('email'), $r->input('password'));

       // Set cookie with encryption prefix
       // Middleware will encrypt automatically on next request
       $cookie = 'enc_session=' . rawurlencode($user->sessionToken)
               . '; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=3600';

       return Response::json(['user' => $user])
           ->withAddedHeader('Set-Cookie', $cookie);
   });

**On next request**: Browser sends ``enc_session=<encrypted>``, middleware decrypts to original value.

Regular (Non-Encrypted) Cookies
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // Without 'enc_' prefix, cookie is NOT encrypted
   $cookie = 'theme=dark; Path=/; Max-Age=31536000';
   return Response::json(['ok' => true])
       ->withAddedHeader('Set-Cookie', $cookie);

--------------

Cookie Attributes
-----------------

Path Scoping
~~~~~~~~~~~~

.. code:: php

   // Scoped to /admin only
   $cookie = 'enc_admin_token=' . $token . '; Path=/admin; HttpOnly; Secure; SameSite=Strict';

   // Available site-wide
   $cookie = 'enc_session=' . $token . '; Path=/; HttpOnly; Secure; SameSite=Lax';

Domain Scoping (Cross-Subdomain)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // Share across subdomains
   $cookie = 'enc_shared=' . $value
           . '; Path=/; Domain=.example.com; HttpOnly; Secure; SameSite=Lax';

⚠️ **Security Risk**: All subdomains can read/write this cookie.

SameSite Options
~~~~~~~~~~~~~~~~

+------------+------------------------------------------+----------------------------+
| Value      | Behavior                                 | Use Case                   |
+============+==========================================+============================+
| ``Strict`` | Never sent on cross-site requests        | High-security (banking)    |
+------------+------------------------------------------+----------------------------+
| ``Lax``    | Sent on top-level navigations (GET only) | Most web apps (default)    |
+------------+------------------------------------------+----------------------------+
| ``None``   | Always sent (requires ``Secure``)        | Embedded widgets, OAuth    |
+------------+------------------------------------------+----------------------------+

.. code:: php

   // Strict (most secure)
   $cookie = 'enc_csrf=' . $token . '; HttpOnly; Secure; SameSite=Strict';

   // Lax (standard)
   $cookie = 'enc_session=' . $session . '; HttpOnly; Secure; SameSite=Lax';

   // None (cross-origin embedding)
   $cookie = 'widget_state=' . $state . '; Secure; SameSite=None';  // Must have Secure

--------------

Advanced Patterns
-----------------

Session Cookies with Rotation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   Route::post('/login', function (Request $r) {
       $user = AuthService::attempt($r->input('email'), $r->input('password'));

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
       ], 3600);

       // Set encrypted cookie
       $cookie = 'enc_sess=' . $sessionId
               . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600';

       return Response::json(['user' => $user])
           ->withAddedHeader('Set-Cookie', $cookie);
   });

   // Validate in middleware
   final class SessionMiddleware
   {
       public function __invoke(Request $r, Closure $next): Response
       {
           $sessionId = $r->cookie('enc_sess');  // Auto-decrypted

           if (!$sessionId) {
               return Response::json(['error' => 'Not authenticated'], 401);
           }

           $session = Cache::get("session:{$sessionId}");

           if (!$session) {
               return Response::json(['error' => 'Session expired'], 401);
           }

           // Refresh TTL on activity
           Cache::put("session:{$sessionId}", $session, 3600);

           // Attach user to request
           $r = $r->withAttribute('auth.user_id', $session['user_id']);

           return $next($r);
       }
   }

Remember Me (Long-Lived Token)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   Route::post('/login', function (Request $r) {
       $user = AuthService::attempt($r->input('email'), $r->input('password'));

       $cookies = [];

       // Short-lived session
       $sessionId = bin2hex(random_bytes(32));
       Cache::put("session:{$sessionId}", ['user_id' => $user->id], 3600);
       $cookies[] = 'enc_sess=' . $sessionId
                  . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600';

       // Long-lived remember token (if requested)
       if ($r->input('remember')) {
           $rememberToken = bin2hex(random_bytes(32));

           // Store hashed token in database
           DB::insert('remember_tokens', [
               'user_id' => $user->id,
               'token_hash' => hash('sha256', $rememberToken),
               'expires_at' => date('Y-m-d H:i:s', time() + 2592000)  // 30 days
           ]);

           $cookies[] = 'enc_remember=' . $rememberToken
                      . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000';
       }

       $response = Response::json(['user' => $user]);
       foreach ($cookies as $cookie) {
           $response = $response->withAddedHeader('Set-Cookie', $cookie);
       }

       return $response;
   });

Cookie Prefixes for Security
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use ``__Host-`` and ``__Secure-`` prefixes:

.. code:: php

   // __Host- prefix (most secure)
   // - Must have Secure flag
   // - Must have Path=/
   // - Must NOT have Domain
   $cookie = '__Host-enc_sess=' . $sessionId
           . '; Path=/; Secure; HttpOnly; SameSite=Strict';

   // __Secure- prefix (secure only)
   // - Must have Secure flag
   // - Can have Domain and Path
   $cookie = '__Secure-enc_sess=' . $sessionId
           . '; Path=/; Secure; HttpOnly; SameSite=Lax; Domain=.example.com';

**Browser enforces these rules** and won't accept cookies that violate them.

Key Rotation (Dual-Key)
~~~~~~~~~~~~~~~~~~~~~~~

Decrypt with multiple keys, encrypt with the first (current) key:

.. code:: php

   $currentKey = $_ENV['COOKIE_KEY_CURRENT'];  // New key
   $oldKey = $_ENV['COOKIE_KEY_OLD'];          // Previous key (grace period)

   new CookieEncryptionMiddleware(
       keyOrKeys: [$currentKey, $oldKey],  // Try decrypting with both
       cookiePrefix: 'enc_'
       // Always encrypts with $currentKey (first in array)
   );

**Rotation Process**:

1. Add new key as first element
2. Keep old key for grace period (e.g., 30 days)
3. Remove old key after grace period
4. Users' cookies auto-upgrade on next request

Server-Side Storage (Large Cookies)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

For cookies exceeding browser limits (~4KB):

.. code:: php

   use Symfony\Component\Cache\Adapter\RedisAdapter;

   $redis = new \Redis();
   $redis->connect('127.0.0.1', 6379);
   $cachePool = new RedisAdapter($redis);

   new CookieEncryptionMiddleware(
       keyOrKeys: $_ENV['WEBRICK_COOKIE_KEY'],
       cookiePrefix: 'enc_',
       maxBytes: 3_800,        // If cookie > 3.8KB...
       store: $cachePool,      // ...store value in Redis
       storeTtl: 86_400        // ...with 24h TTL
   );

**How it works**:

1. Middleware encrypts the value
2. If encrypted value > ``maxBytes``, stores in cache with random ID
3. Sets cookie: ``enc_name=S:abc123`` (pointer to cache)
4. On read, fetches from cache using ID

--------------

Security Best Practices
-----------------------

1. Always Use ``HttpOnly`` for Session Cookies
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Prevents JavaScript access (mitigates XSS):

.. code:: php

   // ✅ Good
   $cookie = 'enc_sess=' . $id . '; HttpOnly; Secure; SameSite=Strict';

   // ❌ Bad (JS can read via document.cookie)
   $cookie = 'enc_sess=' . $id . '; Secure; SameSite=Strict';

2. Always Use ``Secure`` in Production
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Forces HTTPS-only transmission:

.. code:: php

   // Conditional based on environment
   $secure = $_ENV['APP_ENV'] === 'production' ? '; Secure' : '';
   $cookie = 'enc_sess=' . $id . '; HttpOnly; SameSite=Lax' . $secure;

   // Or use middleware with forceSecure: true

3. Use Strong Encryption Keys
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // ❌ Bad
   $key = 'my-secret-key';  // Too short, predictable

   // ✅ Good
   $key = base64_decode($_ENV['WEBRICK_COOKIE_KEY']);  // 32+ bytes, random

   // Generate a key
   $key = random_bytes(32);
   echo base64_encode($key);  // Store this in .env

4. Validate Decrypted Values
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // Don't trust cookie values blindly
   $userId = $r->cookie('enc_user_id');  // ❌ Dangerous even if encrypted

   // Verify against server-side session
   $sessionId = $r->cookie('enc_sess');
   $session = Cache::get("session:{$sessionId}");

   if ($session && $session['user_id'] === $userId) {
       // ✅ Safe
   }

5. Rotate Session IDs
~~~~~~~~~~~~~~~~~~~~~

After privilege escalation or sensitive operations:

.. code:: php

   Route::post('/change-password', function(Request $r) {
       $oldSessionId = $r->cookie('enc_sess');

       // Change password...

       // Invalidate old session
       Cache::forget("session:{$oldSessionId}");

       // Create new session
       $newSessionId = bin2hex(random_bytes(32));
       Cache::put("session:{$newSessionId}", ['user_id' => $userId], 3600);

       return Response::json(['success' => true])
           ->withAddedHeader('Set-Cookie',
               'enc_sess=' . $newSessionId . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600'
           );
   });

6. Limit Cookie Scope
~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // ✅ Good: Scoped to /admin
   $cookie = 'enc_admin_sess=' . $id . '; Path=/admin; HttpOnly; Secure; SameSite=Strict';

   // ❌ Bad: Accessible everywhere
   $cookie = 'enc_admin_sess=' . $id . '; Path=/; HttpOnly; Secure; SameSite=Strict';

7. Set Appropriate Expiration
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   // Session cookie (expires when browser closes)
   $cookie = 'enc_sess=' . $id . '; Path=/; HttpOnly; Secure; SameSite=Lax';

   // Persistent cookie (30 days)
   $cookie = 'enc_remember=' . $token . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000';

   // Short-lived (5 minutes)
   $cookie = 'enc_otp_challenge=' . $challenge . '; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=300';

--------------

Testing
-------

Unit Test
~~~~~~~~~

.. code:: php

   use PHPUnit\Framework\TestCase;

   class CookieTest extends TestCase
   {
       public function testSetsCookieWithCorrectAttributes(): void
       {
           $response = Response::json(['ok' => true])
               ->withAddedHeader('Set-Cookie',
                   'enc_test=value; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600'
               );

           $setCookie = $response->getHeaderLine('Set-Cookie');

           $this->assertStringContainsString('enc_test=value', $setCookie);
           $this->assertStringContainsString('HttpOnly', $setCookie);
           $this->assertStringContainsString('Secure', $setCookie);
           $this->assertStringContainsString('SameSite=Lax', $setCookie);
       }

       public function testReadsCookieFromRequest(): void
       {
           $request = Request::fake(
               headers: ['Cookie' => 'enc_sess=abc123; theme=dark'],
               method: 'GET',
               uri: '/',
           );

           $this->assertEquals('abc123', $request->cookie('enc_sess'));
           $this->assertEquals('dark', $request->cookie('theme'));
           $this->assertNull($request->cookie('nonexistent'));
       }
   }

Integration Test
~~~~~~~~~~~~~~~~

.. code:: bash

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

--------------

Troubleshooting
---------------

Issue: Cookie not decrypting
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Symptoms**: Handler receives encrypted value instead of plain text

**Causes**:

1. Middleware not enabled
2. Wrong cookie prefix
3. Key mismatch

**Debug**:

.. code:: php

   // Check if middleware is active
   $encrypted = $r->getHeaderLine('Cookie');  // Raw from browser
   $decrypted = $r->cookie('enc_sess');       // After middleware

   error_log("Raw: {$encrypted}");
   error_log("Decrypted: {$decrypted}");

**Fix**:

.. code:: php

   // Ensure middleware is in pre-global
   $preGlobal[] = new CookieEncryptionMiddleware(
       keyOrKeys: $_ENV['WEBRICK_COOKIE_KEY'],
       cookiePrefix: 'enc_'  // Match your cookie names
   );

Issue: Users logged out unexpectedly
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Symptoms**: Sessions don't persist after deploy

**Causes**:

1. Key changed between deployments
2. Cookie encryption enabled mid-flight

**Fix**: Use key rotation during transition:

.. code:: php

   new CookieEncryptionMiddleware(
       keyOrKeys: [$newKey, $oldKey],  // Support both
       cookiePrefix: 'enc_'
   );

Issue: Large cookie rejected
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Symptoms**: Browser doesn't store cookie

**Cause**: Cookie exceeds 4KB limit

**Fix**: Enable server-side storage:

.. code:: php

   new CookieEncryptionMiddleware(
       keyOrKeys: $_ENV['WEBRICK_COOKIE_KEY'],
       maxBytes: 3_800,
       store: $cachePool  // Store large values in cache
   );

Issue: Cookie visible in DevTools
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Symptoms**: Can see cookie value in browser

**Cause**: Cookie name doesn't match prefix

**Fix**:

.. code:: php

   // ❌ Wrong: No 'enc_' prefix
   $cookie = 'session=' . $id . '...';

   // ✅ Correct: Has 'enc_' prefix
   $cookie = 'enc_session=' . $id . '...';

--------------

Browser DevTools Inspection
---------------------------

Chrome/Edge DevTools
~~~~~~~~~~~~~~~~~~~~

1. Open DevTools (F12)
2. Go to **Application** tab
3. Expand **Cookies** in left sidebar
4. Select your domain

**What to Check**:

- ✅ ``HttpOnly`` column checked for session cookies
- ✅ ``Secure`` column checked (in production)
- ✅ ``SameSite`` set appropriately
- ✅ ``Expires / Max-Age`` matches expectation
- ✅ ``Path`` scoped correctly
- ✅ Encrypted cookies show gibberish value (not plain text)

--------------

Summary
-------

**Cookie encryption provides**:

- ✅ Confidentiality (values unreadable)
- ✅ Integrity (tampering detected)
- ✅ Automatic decryption in handlers
- ✅ Optional server-side storage for large values

**When to use**:

- ✅ Session tokens
- ✅ Remember-me tokens
- ✅ User preferences with sensitive data
- ✅ CSRF tokens

**When NOT to use**:

- ❌ Public preferences (theme, language)
- ❌ Analytics/tracking cookies
- ❌ Small, non-sensitive values

**Golden rule**: Encrypt anything you wouldn't want users to read or modify.
