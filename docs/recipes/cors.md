# CORS Configuration Recipe

Set up Cross-Origin Resource Sharing for your API.

---

## Problem

Your API needs to be accessible from web applications hosted on different domains.

---

## Solution

### Basic CORS Middleware

```php
<?php
// src/Middleware/CorsMiddleware.php

namespace App\Middleware;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Closure;

final class CorsMiddleware
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    private int $maxAge;
    private bool $allowCredentials;

    public function __construct(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization'],
        int $maxAge = 3600,
        bool $allowCredentials = false
    ) {
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
        $this->maxAge = $maxAge;
        $this->allowCredentials = $allowCredentials;
    }

    public function __invoke(Request $r, Closure $next): Response
    {
        $origin = $r->getHeaderLine('Origin');

        // Handle preflight request
        if ($r->isOptions()) {
            return $this->handlePreflight($origin);
        }

        // Handle actual request
        $response = $next($r);

        return $this->addCorsHeaders($response, $origin);
    }

    private function handlePreflight(string $origin): Response
    {
        $response = Response::create('', 204);

        return $this->addCorsHeaders($response, $origin)
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        // Check if origin is allowed
        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $this->getAllowedOrigin($origin))
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins);
    }

    private function getAllowedOrigin(string $origin): string
    {
        if (in_array('*', $this->allowedOrigins)) {
            return $this->allowCredentials ? $origin : '*';
        }

        return $origin;
    }
}
```

### Configuration

```php
<?php
// config/cors.php

return [
    // Development
    'development' => [
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['*'],
        'max_age' => 3600,
        'allow_credentials' => false
    ],

    // Production
    'production' => [
        'allowed_origins' => [
            'https://app.example.com',
            'https://admin.example.com'
        ],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'max_age' => 86400,  // 24 hours
        'allow_credentials' => true
    ]
];

// Usage
$corsConfig = require __DIR__ . '/../config/cors.php';
$env = $_ENV['APP_ENV'] ?? 'development';
$config = $corsConfig[$env];

$preGlobal[] = new CorsMiddleware(
    allowedOrigins: $config['allowed_origins'],
    allowedMethods: $config['allowed_methods'],
    allowedHeaders: $config['allowed_headers'],
    maxAge: $config['max_age'],
    allowCredentials: $config['allow_credentials']
);
```

### Testing

```bash
# Test preflight request
curl -X OPTIONS http://localhost:8000/api/users \
  -H "Origin: https://app.example.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type" \
  -v

# Expected headers:
# Access-Control-Allow-Origin: https://app.example.com
# Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
# Access-Control-Allow-Headers: Content-Type, Authorization
# Access-Control-Max-Age: 3600

# Test actual request
curl http://localhost:8000/api/users \
  -H "Origin: https://app.example.com" \
  -v
```

---

## Best Practices

### 1. Never Use `*` with Credentials

```php
// ❌ Bad: Security risk
new CorsMiddleware(
    allowedOrigins: ['*'],
    allowCredentials: true
);

// ✅ Good: Explicit origins
new CorsMiddleware(
    allowedOrigins: ['https://app.example.com'],
    allowCredentials: true
);
```

### 2. Whitelist Specific Origins

```php
// ❌ Bad: Too permissive
$allowedOrigins = ['*'];

// ✅ Good: Explicit whitelist
$allowedOrigins = [
    'https://app.example.com',
    'https://admin.example.com',
    'https://mobile.example.com'
];
```

### 3. Limit Allowed Headers

```php
// ❌ Bad: All headers allowed
$allowedHeaders = ['*'];

// ✅ Good: Only necessary headers
$allowedHeaders = [
    'Content-Type',
    'Authorization',
    'X-Requested-With',
    'X-API-Key'
];
```

### 4. Set Appropriate Max-Age

```php
// Development: Short cache
$maxAge = 600;  // 10 minutes

// Production: Longer cache
$maxAge = 86400;  // 24 hours
```

---

## Summary

**This recipe provides**:
- ✅ Complete CORS middleware
- ✅ Preflight request handling
- ✅ Environment-based configuration
- ✅ Origin validation
- ✅ Credentials support

**Production checklist**:
- [ ] Whitelist specific origins (never use `*` with credentials)
- [ ] Limit allowed methods
- [ ] Limit allowed headers
- [ ] Set appropriate max-age
- [ ] Test preflight and actual requests
- [ ] Monitor CORS errors
