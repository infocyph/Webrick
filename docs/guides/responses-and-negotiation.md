# Responses & Content Negotiation


---

**📖 Note**: This guide focuses on how Response helpers work **with** content negotiation middleware. For comprehensive Response API reference (all helpers, status codes, headers, etc.), see [Response Helpers](./responses.md).

---
This guide shows how `Response` helpers and the negotiation middleware work together.
The examples reflect the real behavior in the codebase.

## Auto content negotiation

`Response::auto($r, $data)` inspects the request's `Accept` (and commonly-used `+json` aliases) and chooses:
- `application/json` for arrays/objects/scalars
- `text/plain` for strings
- falls back to JSON when unsure

```php
use Infocyph\Webrick\Response\Response as R;

Route::get('/profile', function () {
    $payload = ['id' => 123, 'name' => 'Hasan'];
    return R::auto($r, $payload); // JSON by default; can also respect charset/locale if configured
});
```

## Streaming

```php
use Infocyph\Webrick\Response\Response as R;

Route::get('/stream/logs', function () {
    return R::stream(function ($out) {
        foreach (tail('/var/log/app.log') as $line) {
            $out($line . "\n"); // flushes chunked
        }
    }, filename: null); // add filename to suggest download
});
```

## Validators & compression (middleware cooperation)

- **Cache Validators Middleware**: computes/propagates `ETag` / `Last-Modified`, answers 304 or 412 on preconditions and drops stale `Range` headers so handlers return a full 200 when needed.
- **Compression Middleware**: negotiates `zstd`, `br`, `gzip`, `deflate`, sets `Vary: Accept-Encoding` and coordinates with ETags (weak/strong) to avoid validator mismatches.

**Tip:** Let Webrick handle compression; avoid double-encoding at the reverse proxy.

## CORS & Security Policies

Use the CORS/policy middleware to emit ACA* headers, HSTS, CSP, Accept-CH and TAO as configured. Attach per-route via attribute or group middleware if you need different policies.

## PSR-7-style factories and framework adapters

`HttpFactory` creates Webrick message objects with familiar factory method
names. It does not implement PSR-17 and the returned response does not
implement the PSR-7 response interface:

```php
use Infocyph\Webrick\Request\Psr7\HttpFactory;

$factory = new HttpFactory();
$res = $factory->createResponse(200)->withHeader('X-App', 'Webrick');
$stream = $factory->createStream('Hello');
$res = $res->withBody($stream);
```

When another framework owns the response, copy the Webrick status, headers,
protocol and body into that framework's response through an explicit adapter.
Do not pass a Webrick response to a parameter type-hinted as
`Psr\Http\Message\ResponseInterface`.
