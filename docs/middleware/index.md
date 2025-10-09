
# Middleware

Webrick’s pipeline is **pre‑global → handler → post‑global**. Place each middleware where it has the most effect with minimal cost.

## Core sets
- **Pre‑global**: Cache Validators, Gateway Hardening, Normalize Method, Negotiation, Throttle, Cookies, Telemetry
- **Post‑global**: Compression, Vary Accumulator, Policies/CORS, Dev Linter (if any)

## Why order matters
- Validators early → cheap 304/412 short‑circuit.
- Negotiation before body building → consistent `Content-Type`/`Content-Language`.
- Compression late → avoid re‑encoding and get correct ETag semantics.
- Vary accumulation last → dedupe/merge tokens from everything before it.

## Quick links
- 👉 [Cache Validators](./cache-validators.md)
- 👉 [Compression](./compression.md)
- 👉 [Cookies](./cookies.md)
- 👉 [Negotiation](./negotiation.md)
- 👉 [CORS & Policies](./cors-policies.md)
- 👉 [Gateway Hardening](./gateway-hardening.md)
- 👉 [Normalize Method](./normalize-method.md)
- 👉 [Throttle](./throttle.md)
- 👉 [Telemetry](./telemetry.md)
- 👉 [Vary Accumulator](./vary-accumulator.md)

## Example pipeline (recommended)

```php
preGlobal: [
  \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
  \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
  \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class,
  \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
  // optional: throttle/cookies/telemetry
],
postGlobal: [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
  // optional: Policies/CORS
]
```

## Troubleshooting
- 406s? Client `Accept` header has no overlap; use `Response::auto()`.
- Stale content? Confirm validators are attached and clock skew isn’t extreme.
- Cache poisoning? Ensure `Vary` covers what you negotiate (language, encoding, etc.).
