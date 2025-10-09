# Request API Reference

Everything available on `Infocyph\Webrick\Request\Request` passed into your handlers and middleware.

> Types below use PHP-style hints for clarity. Some methods may be aliases; names can be adapted to your exact implementation.

---

## Construction & immutability

`Request` is created by the framework and passed to you. Treat it as **immutable**—methods that “set” values typically return a **new** instance (rarely needed in handlers).

---

## HTTP basics

```php
string   getMethod();                 // "GET", "POST", "PUT", ...
Uri      getUri();                    // PSR-7 style or Router-native
string   getPath();                   // "/users/42"
array    getQueryParams();            // ['page'=>'2']
string   getProtocolVersion();        // "1.1" | "2"
```

Method override (via `NormalizeMethodMiddleware`) is applied **before** routing.

---

## Query & input (body)

Unify access to query/form/json:

```php
mixed    input(string $key, mixed $default = null);
array    all();                       // merged query + body (non-file)
array    query(?string $key = null, mixed $default = null);
array    json(?string $key = null, mixed $default = null); // parsed JSON (null if absent/invalid)
bool     has(string $key);
array    only(array $keys);
array    except(array $keys);
```

Notes

* `json()` returns decoded data; with a key (supports dot access if implemented).
* For very large payloads, prefer reading **only** what you need.

---

## Files (multipart/form-data)

```php
array                files();          // keyed by input name
?UploadedFile        file(string $name);
```

`UploadedFile` (typical):

```php
string  getClientFilename();
string  getClientMediaType();
int     getSize();
int     getError();                    // UPLOAD_ERR_*
string  getTmpName();                  // temp path
```

---

## Headers & server params

```php
array    getHeaders();                 // ['content-type'=>['application/json'], ...]
array    getHeader(string $name);      // case-insensitive; returns array of values
string   getHeaderLine(string $name);  // single, comma-joined
mixed    server(string $key, mixed $default = null); // $_SERVER-like
```

Common helpers (implementation-dependent):

```php
?string  ip();                         // derived from REMOTE_ADDR/X-Forwarded-For (trust proxy!)
?string  userAgent();
```

---

## Cookies

If `CookieEncryptionMiddleware` is enabled, values are **decrypted** automatically:

```php
array    getCookieParams();
mixed    cookie(string $name, mixed $default = null);
```

---

## Route params

```php
array    getParams();                  // ['id'=>'42', ...]
mixed    param(string $name, mixed $default = null);
```

Handlers can also receive params as typed arguments:

```php
function (Request $r, int $id) { /* ... */ }
```

---

## Attributes (context)

Middleware can stash values on the request:

```php
mixed    getAttribute(string $key, mixed $default = null);
array    getAttributes();

// examples set by other middleware:
$r->getAttribute('route.name');        // "users.show"
$r->getAttribute('media');             // "application/json" (Negotiation)
$r->getAttribute('locale');            // "en"
$r->getAttribute('signed');            // true if VerifySignedUrl passed
$r->getAttribute('auth.user_id');      // your auth layer
```

---

## Body access (raw/stream)

```php
string|StreamInterface  getBody();         // raw contents (string or reads stream)
StreamInterface         getBodyStream();   // for streaming/HMAC verification
```

Use streams for very large payloads or signature verification.

---

## Content type helpers

```php
?string  getContentType();             // "application/json", etc.
bool     isJson();                     // convenience
bool     isForm();                     // x-www-form-urlencoded
bool     isMultipart();                // multipart/form-data
```

---

## Method shortcuts (if provided)

```php
bool     isGet();
bool     isPost();
bool     isPut();
bool     isPatch();
bool     isDelete();
bool     isHead();
bool     isOptions();
```

---

## Negotiation helpers (if middleware enabled)

```php
string   media();                      // resolved media type
string   locale();                     // resolved locale or default
```

---

## Cloning / mutation (rare in handlers)

For middleware or advanced cases:

```php
Request  withAttribute(string $key, mixed $value);
Request  withMethod(string $method);       // NormalizeMethodMiddleware usually does this
Request  withHeader(string $name, string|array $value);
Request  withoutHeader(string $name);
```

Prefer using middleware to change requests; handlers should mostly **read**.

---

## Examples

### Read JSON with fallback to query

```php
Route::post('/search', function (Request $r) {
    $q = $r->json('q') ?? $r->query('q', '');
    $page = (int)($r->input('page', 1));
    return Response::json(compact('q','page'));
});
```

### Get a file and some headers

```php
Route::post('/avatar', function (Request $r) {
    $f = $r->file('avatar');
    if (!$f || $f->getError()) {
        return Response::json(['error'=>'invalid upload'], 400);
    }
    $ua = $r->getHeaderLine('user-agent');
    // ...validate + move_uploaded_file($f->getTmpName(), $dest)...
    return Response::json(['ok'=>true, 'ua'=>$ua]);
});
```

### Attributes from middleware

```php
Route::get('/hello', function (Request $r) {
    $locale = $r->getAttribute('locale') ?? 'en';
    return Response::json(['locale'=>$locale]);
});
```

---

## Troubleshooting

| Symptom                                         | Likely cause                               | Fix                                                                      |
| ----------------------------------------------- | ------------------------------------------ | ------------------------------------------------------------------------ |
| `cookie()` returns null though browser sends it | Encryption key mismatch / middleware order | Ensure `CookieEncryptionMiddleware` runs before reading; keys consistent |
| Body parsing fails                              | Wrong `Content-Type` or huge payload       | Check headers; increase limits; use streams                              |
| Route param missing                             | Name mismatch                              | Use `{id:int}` and `function (int $id)` or `$r->param('id')`             |
| Wrong client IP                                 | Untrusted proxy headers                    | Configure trusted proxies; prefer `REMOTE_ADDR` otherwise                |
| Mixed encodings                                 | Client sent `charset` ≠ UTF-8              | Normalize/validate; prefer UTF-8 everywhere                              |

---

## Checklist

* [ ] Use `input()/json()/query()` rather than hitting superglobals
* [ ] Validate and sanitize inputs; keep sanitization middleware lightweight
* [ ] Read files via `file()` / `files()` and validate size/mime
* [ ] Use attributes for cross-cutting context (auth, locale, signed)
* [ ] For large/verified payloads, use `getBodyStream()` instead of buffering

