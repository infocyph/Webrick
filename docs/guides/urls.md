# URLs: Named, Absolute, Signed & Temporary

Generate URLs from route names, build absolute links, and issue signed/temporary URLs that validate on access. This guide shows how to bind URL services, create links, and secure endpoints with verification middleware.

---

## 1) Bind URL services (one-time at boot)

In your front controller when booting the kernel:

```php
use Infocyph\Webrick\Response\Response;

$signKey    = $_ENV['WEBRICK_SIGN_KEY']  ?? 'dev-sign-key';
$defaultTtl = (int)($_ENV['WEBRICK_SIGN_TTL'] ?? 900);

$kernel = RouterKernel::bootWithRegistrar(
  /* ... */
  bindUrlServices: static function ($routes) use ($signKey, $defaultTtl) {
    Response::bindUrlServices($routes, $signKey, $defaultTtl);
  },
  registrarOptions: [
    'exposeUrlServices' => true,
    'signKey'           => $signKey,
    'signedDefaultTtl'  => $defaultTtl,
  ],
);
```

* `bindUrlServices()` wires helpers for **named**, **signed**, and **temporary** URLs.
* Keep your `signKey` secret; rotate it periodically in production.

---

## 2) Named URLs

Turn a **route name** + params into a path (or absolute URL).

```php
use Infocyph\Webrick\Response\Response;

// routes.php
Route::get('/profile/{id:int}', fn($id)=>"profile $id", 'profile.show');

// later (anywhere in a handler)
$url = Response::urlFor('profile.show', ['id'=>42]);                  // "/profile/42"
$abs = Response::urlFor('profile.show', ['id'=>42], absolute: true);  // "https://example.com/profile/42"
```

* Extra query params: `Response::urlFor('profile.show', ['id'=>7], query:['tab'=>'activity'])`

---

## 3) Signed URLs

A **signed URL** is tamper-evident. Clients can’t change the path/params/query without breaking the signature.

```php
// Create a signed URL (does not expire by itself)
$signed = Response::signedUrlFor('profile.show', ['id'=>7], query:['ref'=>'email']);

// Verify on the endpoint with middleware
Route::get('/profile/{id:int}', fn($id)=>"profile $id", [
  'as' => 'profile.show',
  'middleware' => ['verifySignedUrl'],
]);
```

* Good for “action links” sent by email (e.g., verify address, view invoice).
* If you need **expiring** links, use temporary URLs (next section).

---

## 4) Temporary (expiring) signed URLs

Add an expiration (TTL). After it elapses, verification fails.

```php
// 15-minute link, relative URL
$tmp = Response::temporaryUrlFor(
  'secure.download',
  params: ['id' => 99],
  query:  ['dl' => 1],
  absolute: false,
  ttl: 900
);

// Endpoint must verify signature
Route::get('/download/{id:int}', function (int $id) {
  // serve file...
}, ['as'=>'secure.download','middleware'=>['verifySignedUrl']]);
```

* **TTL** is seconds from generation time.
* To **force absolute**, pass `absolute:true`.

---

## 5) Redirect flows

Often you’ll compute a URL then redirect:

```php
Route::get('/make-signed/{id:int}', function (int $id) {
  $url = Response::temporaryUrlFor('secure.download', ['id'=>$id], ttl:900);
  return Response::redirect($url, 302);
});
```

---

## 6) Query parameters & hashing scope

* Both `params` (path variables) and `query` are covered by the signature.
* Changing **any** part of the URL that’s signed (route, params, query) invalidates the signature.
* You can include user-facing flags in `query` (e.g., `?dl=1&theme=dark`); they’ll be verified.

---

## 7) Verification middleware

Attach `verifySignedUrl` to routes that **require** a valid signature:

```php
Route::get('/secure/{id:int}', fn()=>Response::json(['ok'=>true]), [
  'as' => 'secure.show',
  'middleware' => ['verifySignedUrl','throttle:5,1'],
]);
```

Typical failure → **403/401** with a structured error body; you can customize error handling globally.

---

## 8) Clock skew, rotation & revocation

* **Clock skew**: Temporary URLs tolerate small skew (use a sane TTL; 5–15 minutes for actions, 24h for low-risk links).
* **Key rotation**: Keep **current** and **previous** active keys during rotation, or roll out rotation during deploy windows.
* **Revocation**: When necessary, rotate keys immediately; links generated with the previous key will fail verification.

> For “one-time” links, pair with a server-side nonce table (consume-once).

---

## 9) Testing with `curl`

```bash
# 1) Ask your app to generate a temporary URL (returns 302 Location)
curl -i http://127.0.0.1:8000/make-signed/42

# 2) Follow the redirect to hit the verified endpoint
curl -iL http://127.0.0.1:8000/make-signed/42
```

To test failure cases, tweak the last character in the query or wait past the TTL and retry.

---

## 10) Common errors & fixes

| Symptom                             | Likely cause                     | Fix                                                                  |
| ----------------------------------- | -------------------------------- | -------------------------------------------------------------------- |
| 403/401 on verified route           | Missing/incorrect middleware     | Add `verifySignedUrl` to the route (or group)                        |
| 403 immediately                     | `signKey` mismatch or not bound  | Ensure `Response::bindUrlServices($routes, $key, $ttl)` runs at boot |
| Works on dev, fails on prod         | Different keys per environment   | Confirm env var deployment; rotate safely                            |
| Signature breaks on link shorteners | Shortener reorders/changes query | Avoid shorteners that mutate URLs; or use POST forms                 |
| Expires too early/late              | Clock skew or low TTL            | Increase TTL moderately; ensure servers’ clocks are NTP-synced       |

---

## 11) Patterns & tips

* Use **relative** signed URLs for same-origin redirects; **absolute** for emails.
* Pair secure routes with **throttling** to avoid brute-force signature probing.
* Add business guards **after** verification (e.g., “user owns resource”).
* Consider including a **purpose** param in the signed URL (validated server-side).

---

## 12) Cheatsheet

```php
// Named
Response::urlFor('route.name', ['id'=>1], query:['q'=>'x'], absolute:false);

// Signed (no expiry)
Response::signedUrlFor('route.name', ['id'=>1], query:['q'=>'x'], absolute:true);

// Temporary (expires)
Response::temporaryUrlFor('route.name', ['id'=>1], ['q'=>'x'], absolute:false, ttl:900);
```
