# Security Hardening

OWASP Top 10 coverage and production security checklist for Webrick.

---

## OWASP Top 10:2021 Coverage

### ✅ **A01 – Broken Access Control**

**Mitigations**:
- Middleware guards on routes
- Validate ownership in handlers
- Never trust client-supplied IDs
```php
Route::get('/users/{id}/profile', function (Request $r, int $id) {
    $authUserId = $r->getAttribute('auth.user_id');

    if ($authUserId !== $id && !$r->getAttribute('auth.is_admin')) {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    $user = UserRepository::find($id);
    return Response::json($user);
})->withMiddleware(['auth']);
```

**Defense in Depth**:
1. Authentication middleware
2. Authorization checks in handler
3. Database-level row security (PostgreSQL RLS)

---

### ✅ **A02 – Cryptographic Failures**

**Mitigations**:
- Cookie encryption: `CookieEncryptionMiddleware`
- HTTPS enforcement: `GatewayHardeningMiddleware`
- Signed URLs for sensitive actions
- Never log secrets
```php
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
```

---

### ✅ **A03 – Injection**

**Mitigations**:
- **Always** use prepared statements
- Input sanitization: `InputSanitizerMiddleware`
- Output encoding by default (helpers do this)
```php
// ❌ NEVER
$db->query("SELECT * FROM users WHERE email = '{$email}'");

// ✅ ALWAYS
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);

// ✅ Named parameters
$stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND active = :active");
$stmt->execute(['email' => $email, 'active' => 1]);
```

**SQL Injection Test**:
```bash
# Should NOT bypass authentication
curl -X POST http://localhost/login \
  -d "email=admin' OR '1'='1&password=anything"
```

---

### ✅ **A04 – Insecure Design**

**Mitigations**:
- Rate limiting: `ThrottleMiddleware`
- Request size limits: `RequestLimitsMiddleware`
- Maintenance mode: `MaintenanceModeMiddleware`
- Fail-closed on security checks
```php
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
```

---

### ✅ **A05 – Security Misconfiguration**

**Mitigations**:
- Security headers: `CorsAndPoliciesMiddleware`
- Error handling (no stack traces in prod)
- Disable dev tools in prod
```php
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
```

---

### ✅ **A06 – Vulnerable Components**

**Mitigations**:
- Keep dependencies updated
- Run security audits
- Monitor CVE databases
```bash
# Check for known vulnerabilities
composer audit

# Update dependencies
composer update --with-all-dependencies

# CI/CD check
composer audit || exit 1
```

**Automated Monitoring**:
```yaml
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
```

---

### ✅ **A07 – Authentication Failures**

**Mitigations**:
- Strong password hashing
- Multi-factor authentication
- Session timeout
- Rate limit auth endpoints
```php
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
```

**Session Security**:
```php
// Set secure session cookies
$cookie = 'sess=' . bin2hex(random_bytes(32))
        . '; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=3600';
return Response::json(['ok' => true])
    ->withAddedHeader('Set-Cookie', $cookie);
```

---

### ✅ **A08 – Software and Data Integrity**

**Mitigations**:
- Signed URLs for critical actions
- Verify file uploads (magic bytes)
- Subresource Integrity for CDN
```php
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
```

---

### ✅ **A09 – Logging Failures**

**Mitigations**:
- Telemetry: `TelemetryMiddleware`
- Structured logging
- **Never log passwords/tokens**
```php
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
```

**Sensitive Data Redaction**:
```php
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
```

---

### ✅ **A10 – SSRF**

**Mitigations**:
- Validate URLs before fetching
- Whitelist allowed domains
- Block private/internal IPs
```php
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
```

---

## Security Checklist

### Infrastructure

- [ ] HTTPS enforced everywhere
- [ ] HSTS enabled with `includeSubDomains`
- [ ] CSP configured and tested
- [ ] Security headers set (nosniff, frame-ancestors, etc.)
- [ ] Secrets in environment variables
- [ ] Private keys rotated regularly

### Application

- [ ] Cookie encryption enabled for sensitive data
- [ ] Signed URLs for privileged actions
- [ ] Rate limiting on auth/sensitive endpoints
- [ ] Input sanitization enabled
- [ ] Request size limits enforced
- [ ] Host header validated
- [ ] Open redirect protection enabled
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (output encoding)
- [ ] CSRF tokens for state-changing operations

### Authentication

- [ ] Passwords hashed with Argon2id
- [ ] Session cookies Secure + HttpOnly + SameSite
- [ ] MFA available for admin accounts
- [ ] Account lockout after failed attempts
- [ ] Password strength requirements enforced

### Logging & Monitoring

- [ ] Security events logged
- [ ] Failed auth attempts monitored
- [ ] Sensitive data redacted from logs
- [ ] Logs sent to centralized system
- [ ] Alerting set up for anomalies

###### Data Protection

- [ ] Encryption at rest for sensitive data
- [ ] Encryption in transit (TLS 1.3)
- [ ] Personal data minimization
- [ ] Data retention policies implemented
- [ ] Secure deletion procedures

### Dependencies

- [ ] `composer audit` in CI/CD
- [ ] Dependencies updated monthly
- [ ] Security advisories monitored
- [ ] Unused dependencies removed

---

## Penetration Testing Checklist
```bash
#!/bin/bash
# security-tests.sh - Basic security validation

set -e

BASE_URL="${1:-http://localhost:8000}"

echo "🔒 Running security tests against $BASE_URL"

# Test 1: SQL Injection
echo "Test 1: SQL Injection protection..."
curl -s "$BASE_URL/login" \
  -d "email=admin' OR '1'='1&password=test" | grep -q "Invalid" && echo "✅ Protected" || echo "❌ VULNERABLE"

# Test 2: XSS
echo "Test 2: XSS protection..."
curl -s "$BASE_URL/search?q=<script>alert(1)</script>" | grep -q "&lt;script&gt;" && echo "✅ Protected" || echo "❌ VULNERABLE"

# Test 3: HTTPS redirect
echo "Test 3: HTTPS enforcement..."
curl -s -o /dev/null -w "%{http_code}" "http://${BASE_URL#http://}" | grep -q "301\|302\|308" && echo "✅ Redirects" || echo "⚠️  No redirect"

# Test 4: Security headers
echo "Test 4: Security headers..."
HEADERS=$(curl -s -I "$BASE_URL")
echo "$HEADERS" | grep -qi "X-Content-Type-Options: nosniff" && echo "✅ nosniff" || echo "❌ Missing nosniff"
echo "$HEADERS" | grep -qi "X-Frame-Options" && echo "✅ Frame-Options" || echo "❌ Missing Frame-Options"
echo "$HEADERS" | grep -qi "Content-Security-Policy" && echo "✅ CSP" || echo "⚠️  No CSP"
echo "$HEADERS" | grep -qi "Strict-Transport-Security" && echo "✅ HSTS" || echo "⚠️  No HSTS"

# Test 5: Rate limiting
echo "Test 5: Rate limiting..."
for i in {1..10}; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/login" -d "email=test&password=test")
  if [ "$STATUS" = "429" ]; then
    echo "✅ Rate limited after $i attempts"
    break
  fi
done

echo "Security tests complete."
```

Run regularly in CI/CD and before releases.

---

## Incident Response Plan

### 1. Detection

**Monitoring**:
- Failed auth spike (> 100/min)
- 5xx rate spike (> 5% of traffic)
- Unusual data access patterns
- Suspicious file uploads

**Alerting**:
```php
// Example: Alert on auth failures
if ($failedAuthCount > 100) {
    $alerting->critical('Auth spike detected', [
        'count' => $failedAuthCount,
        'time_window' => '1min',
        'top_ips' => $topIps
    ]);
}
```

### 2. Response

**Immediate Actions**:
1. Enable maintenance mode
2. Review access logs
3. Block attacking IPs (temporarily)
4. Rotate compromised credentials
5. Notify stakeholders
```php
// Quick IP block via middleware
Route::group(['middleware' => [
    function (Request $r, Closure $next) {
        $blocked = ['203.0.113.10', '198.51.100.0/24'];
        $ip = $r->getAttribute('client_ip');

        foreach ($blocked as $cidr) {
            if (IpCidr::match($ip, $cidr)) {
                return Response::json(['error' => 'Blocked'], 403);
            }
        }

        return $next($r);
    }
]], function() {
    // Your routes
});
```

### 3. Recovery

**Steps**:
1. Patch vulnerability
2. Deploy fix
3. Review logs for impact
4. Reset affected accounts
5. Communicate to affected users

### 4. Post-Incident

**Documentation**:
- Timeline of events
- Root cause analysis
- Remediation steps taken
- Preventive measures added

**Improvements**:
- Update security tests
- Add monitoring/alerting
- Train team on new threats

---

## Tools & Resources

### Security Scanners

- **OWASP ZAP**: Web app scanner
- **Nikto**: Web server scanner
- **Snyk**: Dependency vulnerabilities
- **SonarQube**: Code quality & security

### PHP-Specific
```bash
# Security audit
composer audit

# Static analysis (security rules)
vendor/bin/phpstan analyse --level=8

# Security-focused linters
vendor/bin/psalm --show-info=false
```

### Headers Testing
```bash
# Check security headers
curl -I https://example.com | grep -i "x-\|content-security\|strict-transport"

# Online tools
# - securityheaders.com
# - observatory.mozilla.org
```

### Penetration Testing Services

- **HackerOne**: Bug bounty platform
- **Synack**: Continuous penetration testing
- **Cobalt**: Pentest as a service

---

## Compliance

### GDPR (EU)

- [ ] Privacy policy published
- [ ] Data processing agreements signed
- [ ] User consent mechanisms implemented
- [ ] Right to erasure (deletion) implemented
- [ ] Data portability (export) available
- [ ] Breach notification procedures (72h)

### PCI DSS (Payment Cards)

- [ ] No storage of CVV/CVC
- [ ] Encrypted storage of card data
- [ ] Tokenization preferred over storage
- [ ] Regular security scans
- [ ] Annual PCI assessment

### HIPAA (Healthcare)

- [ ] Encrypted PHI at rest and in transit
- [ ] Access controls and audit logs
- [ ] Business Associate Agreements
- [ ] Regular risk assessments

---

## Security Contact

**For security vulnerabilities, please email**: `security@example.com`

**PGP Key**: Available at `https://example.com/.well-known/security.txt`

**Bug Bounty**: `https://hackerone.com/example` (if applicable)
