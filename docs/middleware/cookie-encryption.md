# Cookie Encryption

Keep sensitive cookie values private by decrypting them on **request** and letting you set plain values on **response**. This middleware handles the crypto so handlers and controllers stay simple.

---

## What it does

* **Decrypts** incoming cookies using your secret and exposes plaintext via `$request->cookie('name')` and `$request->getCookieParams()`.
* **Leaves setting cookies up to you** (explicit `Set-Cookie` headers). You write *plaintext*; the middleware handles encryption for subsequent requests.
* Supports **key rotation** patterns and can ignore non-encrypted cookies gracefully.
* Plays well with **SameSite**, **HttpOnly**, **Secure** attributes you set on responses.

---

## Wiring

Place it in **pre-global** (order matters: before anything that reads cookies):

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\CookieEncryptionMiddleware(
  $_ENV['WEBRICK_COOKIE_KEY'] ?? 'change-me'   // 32+ bytes recommended
);
```

**Recommended order (snippet):**

```php
$preGlobal = [
  // hardening, telemetry, maintenance, request-limits, throttle, ...
  new \Infocyph\Webrick\Middleware\CookieEncryptionMiddleware($_ENV['WEBRICK_COOKIE_KEY'] ?? 'dev-key'),
  \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class,
  \Infocyph\Webrick\Middleware\InputSanitizerMiddleware::class,
  // negotiation, response cache, cache validators...
];
```

---

## Using it in handlers

**Read cookies (auto-decrypted):**

```php
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

Route::get('/cookie/read', function (Request $r) {
    return Response::json([
        'raw'   => $r->getCookieParams(), // all (decrypted when applicable)
        'token' => $r->cookie('token'),   // null if absent
    ]);
});
```

**Set cookies (normal `Set-Cookie`):**

```php
Route::get('/cookie/set', function () {
    $cookie = 'token=' . rawurlencode('user:7|scope:admin')
            . '; Path=/; HttpOnly; SameSite=Lax; Secure';
    return Response::json(['ok'=>true])->withAddedHeader('Set-Cookie', $cookie);
});
```

> On the **next** request, the middleware will decrypt `token` automatically.

---

## Key management

* Use a **strong, random** key (>= 32 bytes). Load from an environment variable:

    * `WEBRICK_COOKIE_KEY="base64:..."`
* **Rotation** (patterns your implementation may support):

    * **Dual-key**: decrypt with `[old, current]`, encrypt with **current**. Drop the old after a grace period.
    * **Cutover**: change the key during a maintenance window; clears old sessions.

**Tip:** Namespacing: if you run multiple apps on the same domain, prefix cookie names (`app1_session`, `app2_session`) to avoid collisions.

---

## Scoping & attributes (still your job)

Encryption doesn’t set cookie attributes. Always choose appropriate scope:

* `Path=/` (or narrower)
* `Domain=example.com` (only if sharing across subdomains)
* `HttpOnly; Secure; SameSite=Lax|Strict|None` (with `Secure` for `None`)
* `Max-Age` / `Expires` (session vs persistent)

---

## Security guidance

* Treat decrypted cookie values as **untrusted** input—validate and sanitize.
* Don’t store raw PII if you can avoid it; prefer opaque tokens that map to server-side state.
* Combine with **signed URLs** or server-side checks; encryption ≠ authorization.
* For authentication, consider an **HMAC-signed** session payload (or server-side sessions) in addition to encryption.

---

## Error handling

If decryption fails (tampering, wrong key, truncated value), the middleware should:

* Ignore that cookie (treat as missing) **or**
* Provide a way to surface a structured error to logs/metrics while returning a normal response

Don’t leak crypto specifics to clients; simply re-authenticate or start a new session as needed.

---

## Testing

```bash
# 1) Set cookie
curl -i http://127.0.0.1:8000/cookie/set

# 2) Copy the Set-Cookie value and send it back
curl -i --cookie "token=..." http://127.0.0.1:8000/cookie/read
```

If encryption is working, you’ll see the **plaintext** in the JSON response.

---

## Troubleshooting

| Symptom                                            | Cause                             | Fix                                                                     |
| -------------------------------------------------- | --------------------------------- | ----------------------------------------------------------------------- |
| `cookie('name')` is `null` though browser sends it | Key mismatch / decryption failure | Ensure same `WEBRICK_COOKIE_KEY` in all instances; check rotation setup |
| Users sign out unexpectedly after deploy           | Key changed                       | Use dual-key rotation or keep key stable; document rotation plan        |
| JS can read sensitive cookie                       | Missing `HttpOnly`                | Add `HttpOnly` (and `Secure`, `SameSite`) when setting                  |
| Cookie not sent on cross-site                      | `SameSite` policy                 | Use `SameSite=None; Secure` only when truly needed                      |

---

## Checklist

* [ ] Add CookieEncryption to **pre-global** before reading cookies
* [ ] Use a strong key from environment; plan rotation
* [ ] Set proper cookie attributes (`HttpOnly; Secure; SameSite`) on responses
* [ ] Validate decrypted values like any other input
* [ ] Monitor decryption failures to detect tampering or key drift
