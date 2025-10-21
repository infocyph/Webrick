# Response Helpers

Webrick ships ergonomic helpers to return JSON, text, XML/HTML, file downloads, redirects, and streamed output. You can also set headers/cookies, status codes, and rely on content negotiation.

---

## Basics

Import the helper:

```php
use Infocyph\Webrick\Response\Response;
```php

Return a response from any handler:

```php
Route::get('/plain', fn () => Response::plaintext('OK'));
Route::get('/json',  fn () => Response::json(['ok' => true]));
```php

Each helper returns an immutable Response; chaining methods returns a **new** instance.

---

## Status codes

```php
Response::json(['created'=>true], 201);
Response::plaintext('Not found', 404);
Response::create('<h1>Hi</h1>', 200, ['Content-Type'=>'text/html; charset=UTF-8']);
```php

---

## Headers

```php
return Response::json(['ok'=>true])
  ->withHeader('X-Request-Id', 'abc123')
  ->withAddedHeader('Cache-Control', 'no-store');
```php

> Use `withHeader()` to overwrite, `withAddedHeader()` to append.

---

## Cookies (response)

Set a cookie via header (keep it explicit so attributes are clear):

```php
$cookie = rawurlencode('demo') . '=' . rawurlencode('value') .
          '; Path=/; HttpOnly; SameSite=Lax; Secure';
return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $cookie);
```php

If you enabled **CookieEncryptionMiddleware**, the request side will automatically decrypt.

---

## JSON

```php
return Response::json([
  'id'    => 42,
  'name'  => 'Hasan',
  'roles' => ['admin','ops'],
]);
```php

* Uses UTF-8 JSON with sane defaults.
* For large payloads, consider pagination or streaming where appropriate.

---

## Text

```php
return Response::plaintext("Hello world\n");
```php

---

## XML / HTML

```php
$xml = '<note><to>World</to><msg>Hello</msg></note>';
return Response::create($xml, 200, ['Content-Type' => 'application/xml']);
```php

```php
$html = '<!doctype html><title>OK</title><h1>It works</h1>';
return Response::create($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
```php

---

## Redirects

```php
return Response::redirect('/login', 302);

// With named route
$url = Response::urlFor('profile.show', ['id'=>7]);
return Response::redirect($url, 302);
```php

Absolute vs relative is supported by `Response::urlFor(..., absolute:true)`.

---

## File downloads

```php
$path = __DIR__ . '/../storage/report.csv';
return Response::attachment($path, 'report.csv');  // sets headers & streams file
```php

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
```php

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
```php

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
```php

For generic 500s, prefer a global exception handler that transforms throwables into structured responses (and logs).


---

## Custom Status Codes

Use any valid HTTP status code:
```php
// 201 Created (with Location header)
$id = 42;
$url = Response::urlFor('users.show', ['id' => $id], absolute: true);
return Response::json(['id' => $id], 201)
    ->withHeader('Location', $url);

// 204 No Content (empty body for DELETE)
return Response::create('', 204);

// 206 Partial Content (for Range requests - handled by middleware usually)
return Response::create($partialContent, 206, [
    'Content-Range' => "bytes 0-1023/4096",
    'Content-Length' => '1024'
]);

// 304 Not Modified (handled by CacheValidatorsMiddleware usually)
return Response::create('', 304)
    ->withHeader('ETag', $etag);

// 401 Unauthorized (with WWW-Authenticate)
return Response::json(['error' => 'Authentication required'], 401)
    ->withHeader('WWW-Authenticate', 'Bearer realm="api"');

// 403 Forbidden
return Response::json(['error' => 'Insufficient permissions'], 403);

// 405 Method Not Allowed (with Allow header)
return Response::json(['error' => 'Method not allowed'], 405)
    ->withHeader('Allow', 'GET, POST');

// 406 Not Acceptable
return Response::json(['error' => 'Cannot produce requested format'], 406);

// 410 Gone (for expired/deleted resources)
return Response::json(['error' => 'Resource no longer available'], 410);

// 418 I'm a teapot (RFC 2324 - Easter egg)
return Response::plaintext("I'm a teapot", 418);

// 422 Unprocessable Entity (validation errors)
return Response::json([
    'error' => 'Validation failed',
    'errors' => [
        'email' => ['Email is required', 'Email must be valid'],
        'password' => ['Password must be at least 8 characters']
    ]
], 422);

// 429 Too Many Requests (handled by ThrottleMiddleware usually)
return Response::json(['error' => 'Rate limit exceeded'], 429)
    ->withHeader('Retry-After', '60');

// 451 Unavailable For Legal Reasons
return Response::json([
    'error' => 'This content is not available in your region'
], 451);

// 503 Service Unavailable (maintenance mode)
return Response::json([
    'error' => 'Service temporarily unavailable',
    'retry_after' => 300
], 503)->withHeader('Retry-After', '300');
```php

### Status Code Quick Reference

| Code | Meaning                  | Use Case                               |
| ---- | ------------------------ | -------------------------------------- |
| 200  | OK                       | Standard success                       |
| 201  | Created                  | Resource created (POST)                |
| 202  | Accepted                 | Async processing started               |
| 204  | No Content               | Success, no body (DELETE)              |
| 301  | Moved Permanently        | Resource relocated forever             |
| 302  | Found                    | Temporary redirect                     |
| 303  | See Other                | Redirect after POST                    |
| 304  | Not Modified             | Cached version still valid             |
| 307  | Temporary Redirect       | Preserve method on redirect            |
| 308  | Permanent Redirect       | Preserve method, permanent             |
| 400  | Bad Request              | Invalid syntax/parameters              |
| 401  | Unauthorized             | Authentication required                |
| 403  | Forbidden                | Authenticated but not authorized       |
| 404  | Not Found                | Resource doesn't exist                 |
| 405  | Method Not Allowed       | Wrong HTTP verb                        |
| 406  | Not Acceptable           | Can't satisfy Accept header            |
| 408  | Request Timeout          | Client too slow                        |
| 409  | Conflict                 | Resource state conflict                |
| 410  | Gone                     | Resource permanently deleted           |
| 413  | Payload Too Large        | Body exceeds limit                     |
| 415  | Unsupported Media Type   | Wrong Content-Type                     |
| 422  | Unprocessable Entity     | Validation failed                      |
| 429  | Too Many Requests        | Rate limit exceeded                    |
| 451  | Unavailable For Legal    | Censored/blocked                       |
| 500  | Internal Server Error    | Unhandled exception                    |
| 502  | Bad Gateway              | Upstream error                         |
| 503  | Service Unavailable      | Maintenance/overload                   |
| 504  | Gateway Timeout          | Upstream timeout                       |


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
```php

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
```php

---

## Checklist

* [ ] Choose the right helper (`json`, `plaintext`, `create`, `redirect`, `attachment`, `stream`)
* [ ] Set explicit content types for HTML/XML/custom formats
* [ ] Add headers immutably (`withHeader`, `withAddedHeader`)
* [ ] Prefer streaming for large or long-running responses
* [ ] Let middleware manage ETags/compression/CORS consistently

