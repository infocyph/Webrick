# Signed & Temporary URLs

This guide shows how to generate and verify signed URLs in Webrick. It matches the actual code surface:

- URL services are bound at boot time.
- Helpers live on `Infocyph\Webrick\Response\Response` as `urlFor()`, `signedUrlFor()`, and `temporaryUrlFor()`.
- Verification is done via the middleware alias `verifySignedUrl`.

## Prerequisites

At boot, enable URL services and configure signing:

```php
use Infocyph\Webrick\Router\RouterKernel;
use Infocyph\Webrick\Router\Matcher\ShardedMatcher;
use Infocyph\Webrick\Response\Response as R;

$kernel = RouterKernel::bootWithRegistrar(
    new ShardedMatcher(__DIR__.'/var/route-cache'),
    require __DIR__.'/routes.php',
    registrarOptions: [
        'autoSlashRedirect' => true,
        'exposeUrlServices' => true,                  // <- exposes urlFor/signedUrlFor on Response
        'signKey'          => getenv('WEBRICK_SIGN_KEY') ?: 'dev-key-change-me',
        'signedDefaultTtl' => 300,                    // seconds; used by temporaryUrlFor when TTL omitted
        'fallbackAliasesFromRegistrar' => true,
    ]
);

// Optional explicit bind (if not using registrar options)
R::bindUrlServices(
    signKey: getenv('WEBRICK_SIGN_KEY') ?: 'dev-key-change-me',
    defaultTtl: 300
);
```

## Defining a named route

```php
use Infocyph\Webrick\Router\Route;
use Infocyph\Webrick\Response\Response as R;

Route::get('/download/{file}', function (string $file) {
    // Only return if signature verified (see middleware below)
    return R::attachment(__DIR__.'/files/'.$file);
})->name('file.download')->middleware(['verifySignedUrl']);
```

## Generating URLs

```php
// Plain URL for a named route (substitute params)
$url = R::urlFor('file.download', ['file' => 'report.pdf']);

// Signed URL (no expiry)
$signed = R::signedUrlFor('file.download', ['file' => 'report.pdf']);

// Temporary URL (expires in 15 minutes)
$temp = R::temporaryUrlFor('file.download', ['file' => 'report.pdf'], ttl: 900);
```

The `verifySignedUrl` middleware checks the signature and (for temporary URLs) expiry timestamp.
On failure, it returns `403 Forbidden` with a clear reason (bad signature / expired).

### cURL testing

```bash
curl -i "$temp"
# Expect 200 before expiry, 403 after TTL
```

## Tips

- Always **name** routes you intend to link from outside of the handler.
- Set `signedDefaultTtl` to a sane default (e.g. 5–15 minutes) at boot.
- If you reverse-proxy, ensure query strings are preserved exactly; signature is query-string sensitive.
- Prefer `temporaryUrlFor()` for privileged actions (downloads, one-time links) to reduce risk.
