
# Cookie Encryption Middleware

Encrypts/decrypts cookies using **AES-256-GCM**, with optional compression and chunking for large values.

## Capabilities
- Authenticated encryption (AEAD) with automatic nonce handling.
- Optional `zstd`/`br`/`gzip` compression before encryption.
- Chunking for large cookie values.
- Optional server-side storage pointer format `S:<id>` when exceeding limits.
- Enforces secure attributes (`Secure`, `HttpOnly`, `SameSite` where applicable).

## Configure
Provide a 32-byte raw key (do NOT hex/base64-encode unless the code expects raw bytes after decoding).

```php
define('WEBRICK_COOKIE_KEY', getenv('WEBRICK_COOKIE_KEY'));

$pre = [
  [\Infocyph\Webrick\Middleware\CookieEncryptMiddleware::class, [
      'key' => WEBRICK_COOKIE_KEY,
      'compress' => 'zstd',   // null|'zstd'|'br'|'gzip'
      'rotate' => []          // optionally supply old keys for rotation
  ]],
];
```

### Notes
- Rotate keys by supplying previous keys; decryption tries keys in order.
- For large cookies, prefer server-side storage (`S:<id>`) and keep cookie size minimal.
