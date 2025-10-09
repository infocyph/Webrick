# Reference

Exact APIs, options, and behaviors. Use this section when you know *what* you want but need the precise knobs.

## Topics
- **Matcher** — Sharded vs Fused, when to choose which, boot wiring.
- **Route Cache** — Warm/clear flow, artifact shipping.
- **Enums** — `HttpMethodEnum`, `MediaTypeEnum`, `StatusEnum` helpers.
- **Request/Response** — Helper methods and optional PSR‑7 factory interop.

## Quick links
- 👉 [Matcher](./matcher.md)
- 👉 [Route Cache](./route_cache.md)
- 👉 [Enums](./enums.md)
- 👉 Request/Response: see notes inside **Enums** and your project’s Response helpers; PSR‑7: `Infocyph\Webrick\Request\Psr7\HttpFactory`.

## Tip
Pair Reference with **Guides** for end‑to‑end flows (e.g., signed URLs using Response helpers + middleware).

```{toctree}
:maxdepth: 1
:caption: Reference
matcher
route_cache
enums
request
response
```