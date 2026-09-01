Security Hardening
==================

OWASP Top 10 coverage and production security checklist for Webrick.

--------------

OWASP Top 10:2021 Coverage
--------------------------

✅ **A01 – Broken Access Control**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Middleware guards on routes
- Validate ownership in handlers
- Never trust client-supplied IDs

.. code:: php

   Route::get('/users/{id}/profile', function (Request $r, int $id) {
       $authUserId = $r->getAttribute('auth.user_id');

       if ($authUserId !== $id && !$r->getAttribute('auth.is_admin')) {
           return Response::json(['error' => 'Forbidden'], 403);
       }

       $user = UserRepository::find($id);
       return Response::json($user);
   })->withMiddleware(['auth']);

**Defense in Depth**:

1. Authentication middleware
2. Authorization checks in handler
3. Database-level row security (PostgreSQL RLS)

--------------

✅ **A02 – Cryptographic Failures**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Cookie encryption: ``CookieEncryptionMiddleware``
- HTTPS enforcement: ``GatewayHardeningMiddleware``
- Signed URLs for sensitive actions
- Never log secrets

.. code:: php

   // Encrypt sensitive cookies
   new CookieEncryptionMiddleware(
       keyOrKeys: $_ENV['WEBRICK_COOKIE_KEY'],
       cookiePrefix: 'enc_',
       forceSecure: true,
       forceHttpOnly: true,
       defaultSameSite: 'Strict'
   );

   // Force HTTPS
   new GatewayHardeningMiddleware(
       enforceHttps: true,
       httpsPort: 443
   );

   // Signed actions
   Route::post('/admin/delete-user/{id}', [AdminController::class, 'deleteUser'], [
       'middleware' => ['auth', 'admin', 'verifySignedUrl']
   ]);

--------------

✅ **A03 – Injection**
~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- **Always** use prepared statements
- Input sanitization: ``InputSanitizerMiddleware``
- Output encoding by default (helpers do this)

.. code:: php

   // ❌ NEVER
   $db->query("SELECT * FROM users WHERE email = '{$email}'");

   // ✅ ALWAYS
   $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
   $stmt->execute([$email]);

   // ✅ Named parameters
   $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND active = :active");
   $stmt->execute(['email' => $email, 'active' => 1]);

**SQL Injection Test**:

.. code:: bash

   # Should NOT bypass authentication
   curl -X POST http://localhost/login \
     -d "email=admin' OR '1'='1&password=anything"

--------------

✅ **A04 – Insecure Design**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Rate limiting: ``ThrottleMiddleware``
- Request size limits: ``RequestLimitsMiddleware``
- Maintenance mode: ``MaintenanceModeMiddleware``
- Fail-closed on security checks

.. code:: php

   // Protect auth endpoints
   Route::post('/login', [AuthController::class, 'login'], [
       'middleware' => ['throttle:5,300']  // 5 attempts per 5 minutes
   ]);

   Route::post('/register', [AuthController::class, 'register'], [
       'middleware' => ['throttle:3,3600']  // 3 registrations per hour
   ]);

   // Expensive operations
   Route::post('/api/export', [ExportController::class, 'generate'], [
       'middleware' => ['auth', 'throttle:1,300']  // 1 export per 5 minutes
   ]);

--------------

✅ **A05 – Security Misconfiguration**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Security headers: ``CorsAndPoliciesMiddleware``
- Error handling (no stack traces in prod)
- Disable dev tools in prod

.. code:: php

   // Production headers
   new CorsAndPoliciesMiddleware(
       hsts: true,
       hstsIncludeSubdomains: true,
       csp: "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.example.com; style-src 'self' 'unsafe-inline'",
       origins: ['https://app.example.com'],
       allowCredentials: true
   );

   // Error handler (production)
   set_exception_handler(function (Throwable $e) use ($logger) {
       $id = bin2hex(random_bytes(8));
       $logger->error('Unhandled exception', [
           'id' => $id,
           'exception' => get_class($e),
           'message' => $e->getMessage(),
           'file' => $e->getFile(),
           'line' => $e->getLine()
       ]);

       // Never expose internals to client
       return Response::json([
           'error' => [
               'code' => 'E_INTERNAL',
               'message' => 'Internal server error',
               'id' => $id  // For support lookup
           ]
       ], 500);
   });

--------------

✅ **A06 – Vulnerable Components**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Keep dependencies updated
- Run security audits
- Monitor CVE databases

.. code:: bash

   # Check for known vulnerabilities
   composer audit

   # Update dependencies
   composer update --with-all-dependencies

   # CI/CD check
   composer audit || exit 1

**Automated Monitoring**:

.. code:: yaml

   # .github/workflows/security.yml
   name: Security Audit

   on:
     schedule:
       - cron: '0 0 * * *'  # Daily
     push:

   jobs:
     audit:
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4
         - run: composer audit

--------------

✅ **A07 – Authentication Failures**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Strong password hashing
- Multi-factor authentication
- Session timeout
- Rate limit auth endpoints

.. code:: php

   // Hash passwords
   $hash = password_hash($password, PASSWORD_ARGON2ID, [
       'memory_cost' => 65536,  // 64MB
       'time_cost' => 4,
       'threads' => 2
   ]);

   // Verify
   if (!password_verify($input, $hash)) {
       return Response::json(['error' => 'Invalid credentials'], 401);
   }

   // Rehash if needed (algorithm upgraded)
   if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
       $newHash = password_hash($password, PASSWORD_ARGON2ID);
       // Update in database
   }

**Session Security**:

.. code:: php

   // Set secure session cookies
   $cookie = 'sess=' . bin2hex(random_bytes(32))
           . '; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=3600';
   return Response::json(['ok' => true])
       ->withAddedHeader('Set-Cookie', $cookie);

--------------

✅ **A08 – Software and Data Integrity**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Signed URLs for critical actions
- Verify file uploads (magic bytes)
- Subresource Integrity for CDN

.. code:: php

   // Signed critical action
   Route::post('/admin/delete-account/{id}', function (int $id) {
       // Only accessible via signed URL
       AccountService::delete($id);
       return Response::json(['deleted' => $id]);
   })->withMiddleware(['auth', 'admin', 'verifySignedUrl']);

   // Verify file uploads
   function validateUpload(UploadedFile $file): bool {
       // Check magic bytes, not extension
       $finfo = finfo_open(FILEINFO_MIME_TYPE);
       $mime = finfo_file($finfo, $file->getTmpName());
       finfo_close($finfo);

       $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
       return in_array($mime, $allowed, true);
   }

--------------

✅ **A09 – Logging Failures**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Mitigations**:

- Telemetry: ``TelemetryMiddleware``
- Structured logging
- **Never log passwords/tokens**

.. code:: php

   // ✅ Good: structured with context
   $logger->warning('Login failed', [
       'email' => $email,
       'ip' => $r->getAttribute('client_ip'),
       'request_id' => $r->getAttribute('request_id'),
       'user_agent' => substr($r->getHeaderLine('User-Agent'), 0, 100)
   ]);

   // ❌ Bad: logs password
   $logger->info("Login: {$email}:{$password}");

   // ❌ Bad: logs tokens
   $logger->debug("Request headers: " . json_encode($r->getHeaders()));

**Sensitive Data Redaction**:

.. code:: php

   final class SensitiveDataFilter
   {
       private const REDACT_KEYS = ['password', 'token', 'secret', 'api_key', 'credit_card'];

       public static function filter(array $data): array
       {
           foreach ($data as $key => $value) {
               if (self::isSensitive($key)) {
                   $data[$key] = '[REDACTED]';
               } elseif (is_array($value)) {
                   $data[$key] = self::filter($value);
               }
           }
           return $data;
       }

       private static function isSensitive(string $key): bool
       {
           $lower = strtolower($key);
           foreach (self::REDACT_KEYS as $pattern) {
               if (str_contains($lower, $pattern)) {
                   return true;
               }
           }
           return false;
       }
   }

--------------

✅ **A10 – SSRF**
~~~~~~~~~~~~~~~~~

**Mitigations**:

- Validate URLs before fetching
- Whitelist allowed domains
- Block private/internal IPs

.. code:: php

   function isSafeUrl(string $url): bool {
       $parsed = parse_url($url);
       if (!$parsed || !isset($parsed['host'])) {
           return false;
       }

       $host = $parsed['host'];

       // Resolve to IP
       $ip = gethostbyname($host);

       // Block private/reserved ranges
       if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
           return false;
       }

       // Whitelist domains
       $allowed = ['api.example.com', 'cdn.example.com', 'partner.com'];
       return in_array($host, $allowed, true);
   }

   // Usage
   Route::post('/fetch-external', function (Request $r) {
       $url = $r->input('url');

       if (!isSafeUrl($url)) {
           return Response::json(['error' => 'Invalid URL'], 400);
       }

       $response = file_get_contents($url);
       return Response::json(['data' => $response]);
   });

--------------

Security Checklist
------------------

Infrastructure
~~~~~~~~~~~~~~

- ☐ HTTPS enforced everywhere
- ☐ HSTS enabled with ``includeSubDomains``
- ☐ CSP configured and tested
- ☐ Security headers set (nosniff, frame-ancestors, etc.)
- ☐ Secrets in environment variables
- ☐ Private keys rotated regularly

Application
~~~~~~~~~~~

- ☐ Cookie encryption enabled for sensitive data
- ☐ Signed URLs for privileged actions
- ☐ Rate limiting on auth/sensitive endpoints
- ☐ Input sanitization enabled
- ☐ Request size limits enforced
- ☐ Host header validated
- ☐ Open redirect protection enabled
- ☐ SQL injection prevention (prepared statements)
- ☐ XSS prevention (output encoding)
- ☐ CSRF tokens for state-changing operations

Authentication
~~~~~~~~~~~~~~

- ☐ Passwords hashed with Argon2id
- ☐ Session cookies Secure + HttpOnly + SameSite
- ☐ MFA available for admin accounts
- ☐ Account lockout after failed attempts
- ☐ Password strength requirements enforced

Logging & Monitoring
~~~~~~~~~~~~~~~~~~~~

- ☐ Security events logged
- ☐ Failed auth attempts monitored
- ☐ Sensitive data redacted from logs
- ☐ Logs sent to centralized system
- ☐ Alerting set up for anomalies

Data Protection
~~~~~~~~~~~~~~~

- ☐ Encryption at rest for sensitive data
- ☐ Encryption in transit (TLS 1.3)
- ☐ Personal data minimization
- ☐ Data retention policies implemented
- ☐ Secure deletion procedures

Dependencies
~~~~~~~~~~~~

- ☐ ``composer audit`` in CI/CD
- ☐ Dependencies updated monthly
- ☐ Security advisories monitored
- ☐ Unused dependencies removed

--------------

Responsibility Boundaries
-------------------------

Webrick owns routing and HTTP-kernel controls such as signed URLs, request limits, cookie handling, trusted-proxy processing, CORS, response headers and typed HTTP errors.

The embedding application owns authorization policy, authentication lifecycle, prepared database queries, contextual output encoding and business access control. Deployment infrastructure owns TLS, firewall and operating-system hardening. Installing Webrick does not by itself establish regulatory compliance.

Security Contact
----------------

Report suspected vulnerabilities through the private disclosure process in the repository's ``SECURITY.md``. Do not open a public issue for a suspected security vulnerability.
