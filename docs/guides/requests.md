# Request Handling

This guide shows how to read input (query, JSON, form, files), headers, cookies, route params and attributes from `Infocyph\Webrick\Request\Request`. It also covers method overrides and locale/negotiation attributes.

---

## Getting a `Request` instance

Handlers receive it as the first parameter when you type-hint it:

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

Route::post('/echo', function (Request $r) {
    return Response::json([
        'method'  => $r->getMethod(),
        'query'   => $r->query(),
        'input'   => $r->all(),
        'headers' => $r->getHeaders(),
    ]);
});
```

> You can mix typed route params and a `Request` argument in any order.

---

## HTTP method & URI

```php
$r->getMethod();                 // "GET", "POST", ...
$r->getUri();                    // PSR-7 style URI or a Router-native Uri object
$r->getPath();                   // "/users/42"
$r->getQueryParams();            // ['page' => '2']
```

### Method override (forms)

If you enabled **NormalizeMethodMiddleware**, HTML forms can send `POST` with `_method=PUT|PATCH|DELETE` (in body or query). The effective method becomes the override.

---

## Query string & input body

### Query

```php
$r->query('page', 1);            // 1 if missing
$r->query();                     // all query params
```

### Form-URL-Encoded or Multipart

```php
$r->input('email');              // from body (form fields)
$r->all();                       // merged input (query + body)
$r->only(['email','name']);      // convenience filters (if provided)
$r->except(['_token']);          // exclude keys
```

### JSON

If `Content-Type: application/json`, the request parses JSON and exposes it via the same helpers:

```php
$r->json();                      // full decoded JSON (array|scalar|null on invalid/empty)
$r->json('user.id');             // dot-access helper if available, else null
```

> If payload can be large, prefer reading only the keys you need.

---

## Files (multipart)

```php
$files = $r->files();            // array-like, keyed by input name
$avatar = $r->file('avatar');    // UploadedFile-like (tmp path, size, error, clientName, clientType)
```

Validate size and extension at upload time; store outside webroot and generate your own filenames.

---

## Headers & server params

```php
$r->getHeaders();                // ['content-type'=>['application/json'], ...]
$r->getHeader('accept');         // ["application/json"]
$r->getHeaderLine('accept');     // "application/json"
$r->server('REMOTE_ADDR');       // "203.0.113.10"
```

### Common helpers

* **Client IP** (behind trusted proxies): ensure your proxy settings are correct before trusting `X-Forwarded-For`.
* **User-Agent**: `$r->getHeaderLine('user-agent')`

---

## Cookies

If **CookieEncryptionMiddleware** is enabled, decrypted values appear in the request:

```php
$r->getCookieParams();           // all cookies (decrypted when enabled)
$r->cookie('session_id');        // value or null
```

> For security, mark auth cookies `HttpOnly; SameSite=...; Secure`.

---

## Route parameters

Values captured from the path are injected into your handler by name and also available on the request:

```php
Route::get('/users/{id:int}', function (Request $r, int $id) {
    // Both are equivalent:
    $idA = $id;
    $idB = (int)$r->param('id');
    return Response::json(['idA'=>$idA,'idB'=>$idB]);
});
```

---

## Attributes (context passed by middleware)

Middleware may attach contextual data to the Request:

```php
$r->getAttributes();             // all attributes
$r->getAttribute('locale');      // e.g., "en" from negotiation middleware
$r->getAttribute('signed');      // e.g., true if VerifySignedUrl passed
```

You can also set your own attributes in custom middleware to pass state down the pipeline.

---

## Content negotiation helpers

If **NegotiationMiddleware** is active, you can read negotiation decisions:

```php
$r->getAttribute('locale');      // resolved locale
$r->getHeaderLine('accept');     // client's Accept header
```

Then produce a response via convenience helpers:

```php
Response::auto($r, ['data' => 'value']);   // chooses JSON/text/XML sanely
```

---

## Reading the raw body / streams

For advanced cases (e.g., HMAC verification or NDJSON streams):

```php
$raw = $r->getBody();            // raw string or stream contents
$stream = $r->getBodyStream();   // stream resource if available
```

Avoid reading the entire body into memory for very large uploads; stream/pipe instead when possible.

---

## CSRF & idempotency tips

* **CSRF**: for state-changing routes from browsers, enforce a CSRF token (pair with SameSite cookies or use double-submit).
* **Idempotency**: consider an `Idempotency-Key` header on POST APIs that can be retried; store and dedupe server-side.

---

## Examples

### Echo JSON with validation

```php
Route::post('/api/user', function (Request $r) {
    $user = $r->json();
    if (!isset($user['email'])) {
        return Response::json(['error'=>'email required'], 422);
    }
    // ... create user
    return Response::json(['ok'=>true], 201);
});
```

### File upload

```php
Route::post('/upload', function (Request $r) {
    $file = $r->file('avatar');
    if (!$file || $file->getError()) {
        return Response::json(['error'=>'invalid upload'], 400);
    }
    // validate size/mime; move to storage
    // move_uploaded_file($file->getTmpName(), $destination);
    return Response::json(['ok'=>true]);
});
```

---

## Checklist

* [ ] Trust proxy headers only if you control the proxy chain
* [ ] Validate and sanitize input; never trust `Content-Type` blindly
* [ ] Use method override only when necessary, via NormalizeMethodMiddleware
* [ ] Keep large bodies streaming; avoid buffering unbounded payloads
* [ ] Treat cookies as untrusted input unless you encrypted & signed them

