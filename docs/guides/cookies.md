# Cookies & Encryption

Read and set cookies safely. Optionally enable transparent encryption so values at rest (client-side) are unreadable without your key.

---

## Reading cookies (request)

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

Route::get('/cookie/read', function (Request $r) {
    return Response::json([
        'all'    => $r->getCookieParams(),
        'demo'   => $r->cookie('demo'),   // null if missing
    ]);
});
```

If **CookieEncryptionMiddleware** is enabled, `cookie('name')` returns the **decrypted** value.

---

## Setting cookies (response)

Set cookies explicitly via `Set-Cookie` header (clear & PSR friendly):

```php
use Infocyph\Webrick\Response\Response;

Route::get('/cookie/set', function () {
    $cookie = rawurlencode('demo') . '=' . rawurlencode('secret-value')
            . '; Path=/; HttpOnly; SameSite=Lax; Secure';
    return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $cookie);
});
```

**Attributes you’ll commonly use**

* `Path=/` – cookie visible to entire site
* `Domain=example.com` – cross-subdomain sharing if needed
* `Expires=Wed, 31 Dec 2030 23:59:59 GMT` / `Max-Age=3600` – persistence
* `HttpOnly` – hide from JS (mitigates XSS cookie theft)
* `Secure` – HTTPS only
* `SameSite=Lax|Strict|None` – CSRF boundary (set `None` only with `Secure`)

---

## Encrypted cookies (optional)

Enable the middleware once (pre-global) and pass a secret key:

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\CookieEncryptionMiddleware(
    $_ENV['WEBRICK_COOKIE_KEY'] ?? 'change-me'
);
```

* **Write** cookies as usual (plain).
* **Read** via `$r->cookie('name')` → decrypted value if that cookie was written encrypted.
* Rotation: deploy a new key and keep the previous key for a grace period if the middleware supports multi-key decryption; otherwise rotate during a maintenance window.

> Use **encrypted cookies** for any value you don’t want end users to read or tamper with (session info, feature flags with risks). Pair with server-side verification when integrity matters.

---

## Deleting cookies

Overwrite with an expired cookie (and same scope):

```php
Route::get('/cookie/clear', function () {
    $expired = 'demo=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax';
    return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $expired);
});
```

---

## Scoped cookies (subdomains & paths)

* **Path scoping**: `Path=/admin` limits visibility to `/admin/*`.
* **Domain scoping**: `Domain=.example.com` shares across subdomains (use carefully).
* For **domain-scoped routes** (e.g., `api.example.com`), set the cookie domain explicitly to match where the browser should send it.

---

## Security notes

* Treat cookies as **untrusted input** on the server; validate and sanitize even when encrypted.
* Don’t store PII or secrets client-side unless absolutely necessary and encrypted.
* Use `HttpOnly` + `Secure` + `SameSite` appropriately (often `Lax` for app cookies; `Strict` for sensitive flows).
* For login/CSRF-sensitive POST routes, combine SameSite with CSRF tokens.

---

## Examples

### Flash message via cookie

```php
// set
Route::post('/login', function () {
    $c = 'flash=' . rawurlencode('Welcome back!')
       . '; Path=/; Max-Age=10; HttpOnly; SameSite=Lax';
    return Response::redirect('/', 303)->withAddedHeader('Set-Cookie', $c);
});

// read & clear
Route::get('/', function (Request $r) {
    $msg = $r->cookie('flash');
    $clear = 'flash=; Path=/; Max-Age=0';
    return Response::json(['flash'=>$msg])->withAddedHeader('Set-Cookie', $clear);
});
```

### Remember-me (hint)

Use a **signed, encrypted** token (not just a user ID). Validate server-side and rotate on use.

---

## Checklist

* [ ] Prefer `HttpOnly; Secure; SameSite` on all cookies
* [ ] Encrypt sensitive values with CookieEncryptionMiddleware
* [ ] Keep cookie **scope** minimal (Path/Domain)
* [ ] Clear cookies by setting `Max-Age=0` / past `Expires`
* [ ] Never trust cookie contents without validation
