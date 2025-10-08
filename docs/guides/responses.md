# Response Helpers

Webrick ships ergonomic helpers to return JSON, text, XML/HTML, file downloads, redirects, and streamed output. You can also set headers/cookies, status codes, and rely on content negotiation.

---

## Basics

Import the helper:

```php
use Infocyph\Webrick\Response\Response;
```

Return a response from any handler:

```php
Route::get('/plain', fn () => Response::plaintext('OK'));
Route::get('/json',  fn () => Response::json(['ok' => true]));
```

Each helper returns an immutable Response; chaining methods returns a **new** instance.

---

## Status codes

```php
Response::json(['created'=>true], 201);
Response::plaintext('Not found', 404);
Response::create('<h1>Hi</h1>', 200, ['Content-Type'=>'text/html; charset=UTF-8']);
```

---

## Headers

```php
return Response::json(['ok'=>true])
  ->withHeader('X-Request-Id', 'abc123')
  ->withAddedHeader('Cache-Control', 'no-store');
```

> Use `withHeader()` to overwrite, `withAddedHeader()` to append.

---

## Cookies (response)

Set a cookie via header (keep it explicit so attributes are clear):

```php
$cookie = rawurlencode('demo') . '=' . rawurlencode('value') .
          '; Path=/; HttpOnly; SameSite=Lax; Secure';
return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $cookie);
```

If you enabled **CookieEncryptionMiddleware**, the request side will automatically decrypt.

---

## JSON

```php
return Response::json([
  'id'    => 42,
  'name'  => 'Hasan',
  'roles' => ['admin','ops'],
]);
```

* Uses UTF-8 JSON with sane defaults.
* For large payloads, consider pagination or streaming where appropriate.

---

## Text

```php
return Response::plaintext("Hello world\n");
```

---

## XML / HTML

```php
$xml = '<note><to>World</to><msg>Hello</msg></note>';
return Response::create($xml, 200, ['Content-Type' => 'application/xml']);
```

```php
$html = '<!doctype html><title>OK</title><h1>It works</h1>';
return Response::create($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
```

---

## Redirects

```php
return Response::redirect('/login', 302);

// With named route
$url = Response::urlFor('profile.show', ['id'=>7]);
return Response::redirect($url, 302);
```

Absolute vs relative is supported by `Response::urlFor(..., absolute:true)`.

---

## File downloads

```php
$path = __DIR__ . '/../storage/report.csv';
return Response::attachment($path, 'report.csv');  // sets headers & streams file
```

* Validates the file path and sets `Content-Disposition`.
* Prefer streaming (the helper does) for large files.

---

## Streaming responses

Emit chunked output without buffering the whole body:

```php
return Response::stream(function () {
  for ($i=1; $i<=5; $i++) {
    yield "chunk: {$i}\n";
    usleep(120_000);
  }
  return ''; // optional last chunk
});
```

Tips:

* Great for server-sent events, logs, progress output.
* Pair with appropriate web-server settings to avoid buffering.

---

## Content negotiation: `Response::auto()`

Use client headers to decide JSON/text/XML automatically:

```php
Route::get('/auto-demo', function ($r) {
  $data = ['msg'=>'hello','time'=>time()];
  return Response::auto($r, $data);
});
```

* Looks at `Accept` and (optionally) request attributes injected by negotiation middleware.
* Keeps your handlers simple when multiple formats are acceptable.

---

## ETags & cache validators (behavioral)

When **CacheValidatorsMiddleware** is active, responses can carry `ETag` / `Last-Modified`, and the middleware will short-circuit `304 Not Modified` / `412 Precondition Failed` when client validators match. Combine with compression (post-global) for optimal wire bytes.

> You generally don’t set ETag manually; the middleware coordinates it (and with compression).

---

## CORS & security headers

Add CORS/Policy headers in a post-global middleware (recommended):

* `Access-Control-Allow-Origin`, `Access-Control-Allow-Headers`, etc.
* `X-Content-Type-Options: nosniff`, `Referrer-Policy: ...`, `Permissions-Policy: ...`

Keep policy decisions centralized, not per-handler.

---

## Error responses

```php
return Response::json(['error' => 'Invalid input'], 422)
  ->withHeader('X-Error-Code', 'E_INPUT');
```

For generic 500s, prefer a global exception handler that transforms throwables into structured responses (and logs).

---

## Examples

### Paginated list with links

```php
Route::get('/users', function ($r) {
  $page = (int)($r->query('page', 1));
  $per  = 10;
  $data = [/* ... fetch ... */];
  $next = Response::urlFor('users.index', ['page'=>$page+1]);

  return Response::json([
    'page' => $page,
    'per'  => $per,
    'data' => $data,
    'links'=> ['next'=>$next],
  ])->withHeader('Cache-Control', 'private, max-age=60');
}, 'users.index');
```

### Conditional attachment vs inline

```php
Route::get('/report', function ($r) {
  $path = __DIR__.'/../reports/today.csv';
  if ($r->query('dl')) {
    return Response::attachment($path, 'today.csv');
  }
  return Response::create(file_get_contents($path), 200, [
    'Content-Type' => 'text/csv; charset=UTF-8',
  ]);
});
```

---

## Checklist

* [ ] Choose the right helper (`json`, `plaintext`, `create`, `redirect`, `attachment`, `stream`)
* [ ] Set explicit content types for HTML/XML/custom formats
* [ ] Add headers immutably (`withHeader`, `withAddedHeader`)
* [ ] Prefer streaming for large or long-running responses
* [ ] Let middleware manage ETags/compression/CORS consistently

