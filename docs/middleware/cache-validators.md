
# Cache Validators Middleware

Handles HTTP conditional requests with `ETag` and `Last-Modified`, and short-circuits when appropriate.

## Responsibilities
- Evaluate `If-None-Match`, `If-Modified-Since`, and other preconditions before controller work.
- Return **304 Not Modified** or **412 Precondition Failed** when conditions match.
- Drop stale `Range` headers so downstream returns a full `200` when needed.
- Auto-attach validators (ETag/Last-Modified) for GET/HEAD responses if missing.
- Mark personalized responses as `Cache-Control: private`.

## Boot placement
Place it **early** in `preGlobal` so we can skip expensive work:

```php
preGlobal: [
    \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
    // ... hardening, negotiation, etc.
]
```

## Strong vs Weak ETags
- **Weak** ETags are used for encoded variants (compression) or semantically equivalent bodies.
- **Strong** ETags are used for byte-identical bodies (no transform). The middleware coordinates this with compression.
