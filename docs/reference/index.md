# Reference

Exact APIs, options, and behaviors. Use this section when you know *what* you want but need the precise knobs.

## Topics
- **Matcher** — Sharded vs Fused vs Generated, boot wiring and tradeoffs.
- **Route Cache** — Warm/clear flow, artifact shipping.
- **Enums** — `HttpMethodEnum`, `MediaTypeEnum`, `StatusEnum` helpers.
- **Request/Response** — Helper methods and optional PSR‑7 factory interop.

## Quick links
- 👉 [Matcher](./matcher.md)
- 👉 [Route Cache](./route-cache.md)
- 👉 [Enums](./enums.md)
- 👉 Request/Response: see notes inside **Enums**, the Route facade URL helpers, and PSR‑7 factory `Infocyph\Webrick\Request\Psr7\HttpFactory`.

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
enums
request
response
utilities
```
