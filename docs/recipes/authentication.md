# JWT Authentication Recipe

Build secure API authentication with JWT tokens.

---

## Problem

You need to authenticate users for your API, issue tokens, and protect routes.

---

## Solution

### 1. Install JWT Library
```bash
composer require firebase/php-jwt
```

### 2. Create JWT Service
```php
<?php
// src/Auth/JwtService.php

namespace App\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtService
{
    private string $secret;
    private string $issuer;
    private int $ttl;

    public function __construct(
        string $secret,
        string $issuer = 'webrick-app',
        int $ttl = 3600
    ) {
        $this->secret = $secret;
        $this->issuer = $issuer;
        $this->ttl = $ttl;
    }

    public function generate(int $userId, array $claims = []): string
    {
        $now = time();

        $payload = array_merge($claims, [
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'sub' => $userId,
        ]);

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function verify(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Exception $e) {
            return null;
        }
    }

    public function refresh(string $token): ?string
    {
        $payload = $this->verify($token);

        if (!$payload) {
            return null;
        }

        // Only refresh if expiring within 5 minutes
        if ($payload->exp - time() > 300) {
            return null;
        }

        return $this->generate((int) $payload->sub);
    }
}
```

### 3. Create Auth Middleware
```php
<?php
// src/Middleware/AuthMiddleware.php

namespace App\Middleware;

use App\Auth\JwtService;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Closure;

final class AuthMiddleware
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        $auth = $r->getHeaderLine('Authorization');

        if (!str_starts_with($auth, 'Bearer ')) {
            return Response::json([
                'error' => 'Missing or invalid Authorization header'
            ], 401);
        }

        $token = substr($auth, 7);
        $payload = $this->jwt->verify($token);

        if (!$payload) {
            return Response::json([
                'error' => 'Invalid or expired token'
            ], 401);
        }

        // Attach user info to request
        $r = $r->withAttribute('auth.user_id', (int) $payload->sub);
        $r = $r->withAttribute('auth.payload', $payload);

        return $next($r);
    }
}
```

### 4. Register Middleware Alias
```php
<?php
// config/middleware.php

use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use App\Middleware\AuthMiddleware;
use App\Auth\JwtService;

$jwt = new JwtService(
    secret: $_ENV['JWT_SECRET'],
    issuer: $_ENV['APP_NAME'] ?? 'webrick-app',
    ttl: (int) ($_ENV['JWT_TTL'] ?? 3600)
);

MiddlewareAliases::register('auth', fn() => new AuthMiddleware($jwt));
```

### 5. Create Login Endpoint
```php
<?php
// routes/auth.php

use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use App\Auth\JwtService;
use App\Repository\UserRepository;

Route::post('/auth/login', function (Request $r) use ($jwt) {
    $email = $r->input('email');
    $password = $r->input('password');

    if (!$email || !$password) {
        return Response::json([
            'error' => 'Email and password required'
        ], 400);
    }

    $user = UserRepository::findByEmail($email);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return Response::json([
            'error' => 'Invalid credentials'
        ], 401);
    }

    $token = $jwt->generate($user['id'], [
        'email' => $user['email'],
        'name' => $user['name']
    ]);

    return Response::json([
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name']
        ]
    ]);
});

Route::post('/auth/refresh', function (Request $r) use ($jwt) {
    $oldToken = $r->input('token');

    if (!$oldToken) {
        return Response::json(['error' => 'Token required'], 400);
    }

    $newToken = $jwt->refresh($oldToken);

    if (!$newToken) {
        return Response::json(['error' => 'Token not eligible for refresh'], 400);
    }

    return Response::json(['token' => $newToken]);
});

Route::get('/auth/me', function (Request $r) {
    $userId = $r->getAttribute('auth.user_id');
    $user = UserRepository::find($userId);

    return Response::json($user);
})->withMiddleware(['auth']);
```

---

## Testing

### Manual Test
```bash
# Login
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"secret"}'

# Response:
# {"token":"eyJ0eXAiOiJKV1QiLCJhbGc...","user":{...}}

# Use token
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGc..."

curl http://localhost:8000/auth/me \
  -H "Authorization: Bearer $TOKEN"

# Response:
# {"id":1,"email":"user@example.com","name":"John Doe"}
```

### Unit Test
```php
<?php

use PHPUnit\Framework\TestCase;
use App\Auth\JwtService;

class JwtServiceTest extends TestCase
{
    public function testGenerateAndVerify(): void
    {
        $jwt = new JwtService('test-secret-key');

        $token = $jwt->generate(42, ['role' => 'admin']);
        $payload = $jwt->verify($token);

        $this->assertNotNull($payload);
        $this->assertEquals(42, $payload->sub);
        $this->assertEquals('admin', $payload->role);
    }

    public function testVerifyInvalidToken(): void
    {
        $jwt = new JwtService('test-secret-key');

        $payload = $jwt->verify('invalid.token.here');

        $this->assertNull($payload);
    }
}
```

---

## Variations

### With User Roles
```php
public function generate(int $userId, array $roles = []): string
{
    return JWT::encode([
        'iss' => $this->issuer,
        'iat' => time(),
        'exp' => time() + $this->ttl,
        'sub' => $userId,
        'roles' => $roles,
    ], $this->secret, 'HS256');
}

// Role middleware
final class RequireRoleMiddleware
{
    public function __construct(private array $requiredRoles) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        $payload = $r->getAttribute('auth.payload');
        $userRoles = $payload->roles ?? [];

        if (!array_intersect($this->requiredRoles, $userRoles)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        return $next($r);
    }
}

// Usage
Route::get('/admin/users', $handler)
    ->withMiddleware(['auth', new RequireRoleMiddleware(['admin'])]);
```

### With Refresh Tokens
```php
// Store refresh tokens in database
Route::post('/auth/login', function (Request $r) use ($jwt) {
    // ... validate credentials ...

    $accessToken = $jwt->generate($user['id'], ttl: 900);  // 15 min
    $refreshToken = bin2hex(random_bytes(32));

    // Store in database
    DB::insert('refresh_tokens', [
        'user_id' => $user['id'],
        'token' => hash('sha256', $refreshToken),
        'expires_at' => date('Y-m-d H:i:s', time() + 2592000)  // 30 days
    ]);

    return Response::json([
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => 900
    ]);
});

Route::post('/auth/refresh', function (Request $r) use ($jwt) {
    $refreshToken = $r->input('refresh_token');

    $record = DB::queryOne(
        'SELECT * FROM refresh_tokens WHERE token = ? AND expires_at > NOW()',
        [hash('sha256', $refreshToken)]
    );

    if (!$record) {
        return Response::json(['error' => 'Invalid refresh token'], 401);
    }

    $accessToken = $jwt->generate($record['user_id'], ttl: 900);

    return Response::json([
        'access_token' => $accessToken,
        'expires_in' => 900
    ]);
});
```

### OAuth-Style Token Response
```php
Route::post('/oauth/token', function (Request $r) use ($jwt) {
    $grantType = $r->input('grant_type');

    if ($grantType === 'password') {
        // Resource Owner Password Credentials Grant
        $email = $r->input('username');
        $password = $r->input('password');

        $user = UserRepository::findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return Response::json([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid username or password'
            ], 400);
        }

        $accessToken = $jwt->generate($user['id']);

        return Response::json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'read write'
        ]);
    }

    return Response::json([
        'error' => 'unsupported_grant_type',
        'error_description' => 'Grant type not supported'
    ], 400);
});
```

---

## Security Best Practices

1. **Use Strong Secrets**
```bash
   # Generate secure secret
   php -r "echo base64_encode(random_bytes(32));"
```

2. **Short TTL for Access Tokens**
```php
   $accessToken = $jwt->generate($userId, ttl: 900);  // 15 minutes
```

3. **Implement Token Revocation**
```php
   // Store token JTI in blacklist
   $jti = bin2hex(random_bytes(16));
   Cache::set("token_blacklist:{$jti}", true, $ttl);

   // Check in middleware
   if (Cache::has("token_blacklist:{$payload->jti}")) {
       return Response::json(['error' => 'Token revoked'], 401);
   }
```

4. **Rate Limit Login Attempts**
```php
   Route::post('/auth/login', $handler)->withMiddleware(['throttle:5,300']);
```

5. **HTTPS Only**
```php
   // In production
   if ($_ENV['APP_ENV'] === 'production' && !$request->isSecure()) {
       return Response::redirect('https://' . $request->getHost() . $request->getPath(), 301);
   }
```

---

## Summary

**This recipe provides**:
- ✅ JWT token generation and verification
- ✅ Protected routes with middleware
- ✅ Login and refresh endpoints
- ✅ Role-based access control (optional)
- ✅ OAuth-compatible token format (optional)

**Production checklist**:
- [ ] Use strong JWT secret (32+ bytes)
- [ ] HTTPS in production
- [ ] Rate limit login endpoint
- [ ] Implement refresh tokens
- [ ] Add token revocation
- [ ] Log authentication events
