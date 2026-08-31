# Middleware

Webrick’s pipeline is **pre-global → route middleware → handler → post-global unwind**. Keep the portable stack small and let compiled production prepare route/global middleware before traffic.

## Core sets

- **Pre-global**: gateway hardening, request limits, throttle, optional cookie encryption, negotiation, response cache, cache validators, telemetry where required.
- **Post-global**: compression, CORS/security policy application, Vary accumulation.
- **Development/test only**: response linter.
- **Explicit transformation only**: input sanitizer. Do not register blanket sanitization as a security control.

HTTP method normalization is performed at the request/runtime boundary once. There is no `NormalizeMethodMiddleware` in Webrick 5.

## Why order matters

- Reject invalid/oversized requests before expensive application work.
- Negotiate before handlers that consume negotiated request attributes.
- Cache/validator middleware can short-circuit before body construction.
- Compression runs after representation construction.
- Vary accumulation observes every policy that changes the selected representation.

## Quick links

- [Cache Validators](./cache-validators.md)
- [Compression](./compression.md)
- [Cookie Encryption](./cookie-encryption.md)
- [Negotiation](./negotiation.md)
- [CORS & Policies](./cors-and-policies.md)
- [Gateway Hardening](./gateway-hardening.md)
- [Input Sanitizer](./input-sanitizer.md)
- [Maintenance Mode](./maintenance-mode.md)
- [Request Limits](./request-limits.md)
- [Response Cache](./response-cache.md)
- [Response Linter](./response-linter.md)
- [Throttle](./throttle.md)
- [Telemetry](./telemetry.md)
- [Vary Accumulator](./vary-accumulator.md)

## Example development stack

```php
preGlobal: [
    \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
    \Infocyph\Webrick\Middleware\RequestLimitsMiddleware::class,
    \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
    \Infocyph\Webrick\Middleware\ResponseCacheMiddleware::class,
    \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
],
postGlobal: [
    \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
    \Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware::class,
    \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
]
```

Production should compile the selected middleware graph through `RouteCompiler` / `ReleaseCompiler` and boot `CompiledRouterKernel`.

```{toctree}
:maxdepth: 2
:hidden:
:caption: Middleware

overview
aliases
cache-validators
compression
cookie-encryption
cors-and-policies
gateway-hardening
input-sanitizer
maintenance-mode
negotiation
request-limits
response-cache
response-linter
telemetry
throttle
vary-accumulator
```
