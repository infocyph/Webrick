
# Deployments Overview

This section provides copy‑paste ready recipes for deploying Webrick behind common servers and in containers.
The guidance aligns with Webrick's own middleware (validators, compression, CORS/policies, etc.).

## General principles
- **One source of compression**: Prefer app‑level compression middleware OR proxy compression, not both.
- **Pass the original method/headers**: Preserve `Host`, `X-Forwarded-*`, and query strings exactly.
- **Cache correctness**: Respect `Vary` emitted by Webrick; avoid stripping hop‑by‑hop headers at the edge.
- **Streaming**: Disable proxy buffering for endpoints using `Response::stream()` to avoid latency.
- **Static assets**: Let the server serve immutable assets directly with `Cache-Control: public, max-age=...`.
