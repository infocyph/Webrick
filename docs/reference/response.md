
# Response API Reference

Everything you can return from handlers. Responses are **immutable**: every mutator returns a **new** instance.

```php
use Infocyph\Webrick\Response\Response;
```

---

## Constructors & helpers

### `Response::json(array|object $data, int $status = 200, array $headers = []): Response`

* Sets `Content-Type: application/json; charset=UTF-8`
* Encodes UTF-8 safely

```php
return Response::json(['ok'=>true], 201);
```

### `Response::plaintext(string $text, int $status = 200, array $headers = []): Response`

* Sets `Content-Type: text/plain; charset=UTF-8`

```php
return Response::plaintext("pong\n");
```

### `Response::create(string $body, int $status = 200, array $headers = []): Response`

* Raw factory; you must set the right `Content-Type`

```php
$html = '<!doctype html><h1>Hello</h1>';
return Response::create($html, 200, ['Content-Type'=>'text/html; charset=UTF-8']);
```

### `Response::redirect(string $location, int $status = 302, array $headers = []): Response`

* Adds `Location: ...`
* Common codes: 302 (temporary), 301 (permanent), **303** (after POST)

```php
return Response::redirect('/login', 302);
```

### `Response::attachment(string $path, ?string $downloadName = null, array $headers = []): Response`

* Streams a file with appropriate `Content-Type` and `Content-Disposition: attachment`
* Validates existence/readability

```php
return Response::attachment($path, 'report.csv');
```

### `Response::stream(callable $producer, int $status = 200, array $headers = []): Response`

* `$producer(): Generator|string|null` — yield **chunks** (strings)
* Do **not** set `Content-Length`

```php
return Response::stream(function () {
  for ($i=1; $i<=3; $i++) { yield "chunk:$i\n"; }
});
```

### `Response::auto(Request $r, mixed $payload, int $status = 200, array $headers = []): Response`

* Uses negotiation attributes to choose JSON/text/XML

```php
return Response::auto($r, ['msg'=>'hi']);
```

---

## URL helpers (when URL services are bound)

### `Response::urlFor(string $name, array $params = [], array $query = [], bool $absolute = false): string`

```php
$url = Response::urlFor('users.show', ['id'=>7], ['tab'=>'activity'], true);
```

### `Response::signedUrlFor(...)` and `Response::temporaryUrlFor(..., int $ttl)`

* Produce signed (and expiring) URLs; verify on route with `verifySignedUrl` middleware

```php
$tmp = Response::temporaryUrlFor('secure.download', ['id'=>99], ttl:900);
```

---

## Header & status mutators

All return **new** responses:

```php
Response withHeader(string $name, string $value);
Response withAddedHeader(string $name, string $value); // append
Response withoutHeader(string $name);
Response withStatus(int $status, ?string $reasonPhrase = null);
```

Examples:

```php
return Response::json($data)
  ->withHeader('Cache-Control','public, max-age=60')
  ->withAddedHeader('X-Request-Id', $rid);
```

---

## Cookies (set via header)

Explicit `Set-Cookie` is the most portable pattern:

```php
$cookie = 'sess=' . rawurlencode($token)
        . '; Path=/; HttpOnly; SameSite=Lax; Secure; Max-Age=86400';
return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $cookie);
```

If **CookieEncryptionMiddleware** is enabled, the next **request** will be auto-decrypted when you read it; responses are written as usual.

---

## Cache & validators

Set your own headers or rely on middleware providers:

* `Cache-Control`, `Expires`
* `ETag`, `Last-Modified`

```php
return Response::json($post)
  ->withHeader('ETag', '"post-'.$post['rev'].'"')
  ->withHeader('Cache-Control', 'public, max-age=120');
```

With **Compression** enabled, the middleware will coordinate ETag strategy.

---

## Content types (common)

* JSON: `application/json; charset=UTF-8`
* Text: `text/plain; charset=UTF-8`
* HTML: `text/html; charset=UTF-8`
* XML: `application/xml`
* NDJSON: `application/x-ndjson; charset=UTF-8`
* CSV: `text/csv; charset=UTF-8`

Always set the explicit type when using `create()`.

---

## Streaming rules (important)

* Don’t set `Content-Length` on streamed responses.
* Compression is generally **off** for streams (SSE especially).
* For large static files, prefer `attachment()` over manual loops.

---

## Inspectors (typical)

```php
int      getStatusCode();
string   getReasonPhrase();
array    getHeaders();                // ['Content-Type'=>['...']]
array    getHeader(string $name);     // array values
string   getHeaderLine(string $name); // comma-joined
string   getBody();                   // may buffer (non-stream)
bool     isStream();                  // implementation-specific
```

---

## Patterns & examples

### 1) Created resource with Location

```php
$id  = 42;
$url = Response::urlFor('users.show', ['id'=>$id], absolute:true);
return Response::json(['id'=>$id], 201)->withHeader('Location', $url);
```

### 2) Conditional inline vs download

```php
Route::get('/report', function ($r) {
  $path = __DIR__.'/../reports/today.csv';
  if ($r->query('dl')) return Response::attachment($path, 'today.csv');
  return Response::create(file_get_contents($path), 200, [
    'Content-Type' => 'text/csv; charset=UTF-8',
  ]);
});
```

### 3) Error shape with headers

```php
return Response::json(['error'=>['code'=>'E_INPUT','message'=>'Invalid']], 422)
  ->withHeader('X-Error-Code', 'E_INPUT');
```

### 4) SSE

```php
return Response::stream(function () {
  for ($i=0; $i<3; $i++) {
    yield "event: tick\n";
    yield "data: " . json_encode(['i'=>$i]) . "\n\n";
    usleep(750_000);
  }
})->withHeader('Content-Type','text/event-stream')
  ->withHeader('Cache-Control','no-cache')
  ->withHeader('Connection','keep-alive');
```

---

## Troubleshooting

| Symptom                            | Likely cause                     | Fix                                                                     |
| ---------------------------------- | -------------------------------- | ----------------------------------------------------------------------- |
| Browser downloads JSON as file     | Missing/incorrect `Content-Type` | Use `Response::json()` or set header explicitly                         |
| 204/304 carries a body             | Wrong status/body combo          | Return empty body or change status; dev linter can catch                |
| Garbled output                     | Double compression               | Ensure only one layer compresses; don’t set `Content-Encoding` yourself |
| ETag/304 mismatch with compression | Strategy mismatch                | Use Compression’s **recompute-strong** or align headers                 |
| Large file exhausts memory         | Buffering whole file             | Use `Response::attachment()` (streams)                                  |
| Signed URL rejected                | Missing verify middleware        | Add `verifySignedUrl` to the route/group; ensure key bound              |

---

## Checklist

* [ ] Prefer helpers (`json`, `plaintext`, `attachment`, `stream`, `redirect`)
* [ ] Always set **explicit** `Content-Type` when not using helpers
* [ ] Mutate with `withHeader/withAddedHeader/withStatus` (immutably)
* [ ] Coordinate cache headers & validators; keep ETags consistent with compression
* [ ] Use URL helpers for named/signed/temporary links
* [ ] Stream wisely; avoid `Content-Length` on streams; favor `attachment()` for big files

rel_path=docs/reference/response.md


---

## PSR-7 factory interop (optional)

For tests and small utilities you can construct responses via the factory:

```php
use Infocyph\Webrick\Request\Psr7\HttpFactory;

$http = new HttpFactory();
$res  = $http->createResponse(204); // empty response
$txt  = $http->createStream('ok');
```

Prefer using `Response::json()`, `Response::text()`, `Response::stream()` etc. in handlers.
