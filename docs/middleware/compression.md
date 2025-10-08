# Compression

Compress responses with **zstd**, **Brotli**, or **gzip** (and optionally **deflate**) while keeping ETags correct and avoiding double-encoding. The middleware negotiates the best available codec from the client’s `Accept-Encoding` and your runtime capabilities.

---

## What it does

* Chooses the **best** encoding (`zstd` → `br` → `gzip` → identity) based on `Accept-Encoding` and server support
* Skips unsafe cases (already-compressed content, streaming/SSE when disabled, tiny bodies below a threshold)
* Coordinates **ETag** to reflect what’s on the wire (see strategies below)
* Adds/normalizes `Content-Encoding`, `Content-Length` (or removes when chunked), and `Vary: Accept-Encoding`
* Leaves binary attachments alone if they’re known to be compressed already (e.g., `image/*`, `application/zip`)

---

## Usage

Add to your **post-global** stack:

```php
$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  // ... CORS, Vary, etc.
];
```

That’s it. The middleware will handle negotiation and encoding.

---

## Supported encodings

Order of preference (when the client allows it and your PHP has the codec):

1. **zstd** (`zstd`)
2. **Brotli** (`br`)
3. **gzip** (`gzip`)
4. **identity** (no compression)

> If a codec isn’t available at runtime, it is skipped automatically.

---

## ETag strategies

ETags should represent the bytes the client receives. The middleware supports three coordinated strategies:

1. **Recompute strong** *(default)*

    * Compress first, then compute a **strong** ETag for the compressed bytes.
    * Pros: most correct for proxies/caches.
    * Cons: needs the full compressed buffer (not streaming).

2. **Weak-on-encode**

    * Keep the original strong ETag, mark it **weak** (e.g., `W/"abc123"`), and compress.
    * Pros: preserves origin hash, okay for “semantic equality” checks.
    * Cons: shared caches may store per-encoding variants less reliably.

3. **Derive strong**

    * Derive a strong ETag from the uncompressed ETag + encoding metadata.
    * Pros: works when original ETag exists and you want per-encoding strong tags.
    * Cons: requires stable derivation logic.

Pick one globally (constructor/config) and be consistent across deploys.

---

## Behavior & safety rules

* **Streaming bodies**: by default **not** compressed (most codecs need full buffers). For large static files, prefer `Response::attachment()` which streams efficiently; do offline pre-compression if needed.
* **Already-compressed types**: images, zips, etc. are skipped to avoid bloat.
* **Small responses**: a minimum-size threshold avoids making payloads bigger due to headers/overhead.
* **HEAD requests**: never encode a body; only set headers that make sense.
* **Vary**: ensures `Vary: Accept-Encoding` is present (or uses your Vary accumulator if enabled).

---

## Configuration (typical knobs)

When constructing the middleware (or via config), you can usually set:

* `minSize` – don’t compress below N bytes (e.g., 512–1024)
* `encoders` – enable/disable `zstd`, `br`, `gzip`, `deflate`
* `etagStrategy` – `recompute-strong` (default) | `weak-on-encode` | `derive-strong`
* `compressStreams` – false by default; enable only if you fully understand buffering trade-offs
* `skipContentTypes` – array of content-type prefixes to skip (e.g., `image/`, `video/`, `application/zip`)
* `quality` – per-encoder quality/level (e.g., gzip level 6–9, br 5–9, zstd 10–15)

*(Adjust names to the exact constructor/options in your codebase.)*

---

## Examples

### 1) Default compression with safe headers

```php
$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
];
```

Request/response:

```
Request:  Accept-Encoding: gzip, br, zstd
Response: Content-Encoding: zstd
          Vary: Accept-Encoding
          ETag: "4b9f..."         # recomputed for compressed bytes (default)
```

### 2) Weak-on-encode strategy

```php
$postGlobal[] = new \Infocyph\Webrick\Middleware\CompressionMiddleware(
  etagStrategy: 'weak-on-encode'
);
```

Response:

```
ETag: W/"a1b2c3"   # original ETag marked weak
```

### 3) Skip tiny payloads

```php
$postGlobal[] = new \Infocyph\Webrick\Middleware\CompressionMiddleware(
  minSize: 1024
);
```

---

## Interplay with caches & validators

* If **CacheValidatorsMiddleware** runs pre-handler, it may short-circuit with **304** before compression ever happens—great for performance.
* When a full response is generated, the compression middleware updates **ETag** according to the active strategy so downstream caches don’t store stale variants.
* Ensure `Vary: Accept-Encoding` is present so intermediaries don’t serve gzip to zstd-only clients.

---

## Troubleshooting

| Symptom                                 | Likely cause                | Fix                                                                             |
| --------------------------------------- | --------------------------- | ------------------------------------------------------------------------------- |
| Client receives garbled text            | Double compression          | Ensure upstream proxies/CDN don’t re-compress; set `Content-Encoding` only once |
| 304/412 not matching                    | ETag strategy mismatch      | Use **recompute-strong** (default) or ensure validators expect weak tags        |
| No compression applied                  | Codec not enabled/available | Install/enable `zstd`/`brotli` libs; check `Accept-Encoding`                    |
| SSE feels stuck                         | Buffering by server/proxy   | Don’t compress SSE; disable proxy buffering; use streaming without compression  |
| “Wrong” ETag after enabling compression | Strategy changed            | Keep strategy consistent across deployments; purge caches when changing         |

---

## Operational tips

* **Observability**: emit `Server-Timing: encode;dur=…` for encode time if you track latency (optional).
* **CDN**: Prefer doing compression **once** at the app (zstd/br for modern clients). Disable CDN auto-compress or align strategies.
* **Binary assets**: serve via a static server (Nginx) or CDN; don’t waste CPU compressing JPEG/PNG/MP4.
* **Pre-compress for hot static content**: store `.br`/`.gz` artifacts and serve with the right `Content-Encoding`.

---

## Checklist

* [ ] Add CompressionMiddleware to **post-global**
* [ ] Keep `Vary: Accept-Encoding` present (use Vary accumulator)
* [ ] Choose an **ETag strategy** and stick to it (default: recompute-strong)
* [ ] Set a **minSize** to avoid tiny-payload overhead
* [ ] Skip already-compressed content types
* [ ] Don’t compress streaming/SSE unless you’ve tested behavior end-to-end
