# Gateway Hardening Middleware

Protects the app at the edge of the request pipeline.

## Responsibilities
- Host allow-list & HTTPS enforcement (308 redirects as configured).
- Trusted proxy handling; strips hop-by-hop headers.
- Guard against open redirects and invalid `X-Forwarded-*` chains.

## Placement
Put it near the top of `preGlobal` (after validators if you want validators to short-circuit first).

```php
preGlobal: [
  \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
  \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
  // ...
]
```
