# Reference

Exact APIs, options, and behaviors. Use this section when you know *what* you want but need the precise knobs.

## Topics
- **Matcher** — Sharded vs Fused vs Generated, boot wiring and tradeoffs.
- **Route Cache** — Warm/clear flow, artifact shipping.
- **Emitters** — PHP-FPM, CLI, Swoole, RoadRunner, Workerman, and host-owned emission.
- **Enums** — `HttpMethodEnum`, `MediaTypeEnum`, `StatusEnum` helpers.
- **Request/Response** — Message helpers and explicit framework adapter boundaries.

## Quick links
- 👉 [Matcher](./matcher.md)
- 👉 [Route Cache](./route-cache.md)
- 👉 [Response Emitters](./emitters.md)
- 👉 [Enums](./enums.md)
- 👉 Request/Response: see the dedicated request and response references. Webrick
  offers PSR-7-style methods but does not implement the PSR-7 interfaces.

## Tip
Pair Reference with **Guides** for end‑to‑end flows (e.g., signed URLs using Route facade helpers + middleware).

```{toctree}
:maxdepth: 2
:hidden:
:caption: Reference

quick-reference
router
middleware
matcher
route-cache
emitters
enums
request
response
utilities
```
