# CORS & Security Policies Middleware

Emits CORS headers and common security policy headers.

## What it covers
- CORS: `Access-Control-Allow-Origin`, `-Methods`, `-Headers`, credentials, max-age; auto 204 for preflight.
- Security: HSTS, CSP, `Accept-CH`, `Timing-Allow-Origin`.

## Per-route control
Use attributes or group middleware to tune policies for admin vs public routes.

```php
Route::group(['prefix' => '/api', 'middleware' => ['cors:public']], function () {
    // ...
});
```

### Tips
- Keep `Access-Control-Allow-Origin` narrow in production.
- Pair with validators/compression; order: validators → negotiation → handler → compression → CORS/policies.
