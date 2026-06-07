# Error Handling Recipe

For the current framework-level error model, start with the guide:

- [Error Rendering](../guides/error-rendering.md)

Quick summary:

- framework-owned rejections now throw typed HTTP exceptions
- `RouterKernel` catches them through `ErrorHandler`
- you can override final rendering with `responseRenderer`
- controllers and user middleware can still return explicit `Response` objects directly

Use the guide when you need:

- API-only JSON error envelopes
- browser-friendly HTML errors for non-API paths
- preserved headers like `Retry-After` or `Allow`
