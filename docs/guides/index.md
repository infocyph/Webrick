# Guides

Practical, copy‑paste friendly walkthroughs that show how to use Webrick’s features in real apps. Each guide mirrors the **actual code paths** and middleware interactions.

## What’s inside
- **Signed & Temporary URLs** — Generate secure links and verify them via middleware.
- **Attribute Routes** — Co‑locate route metadata with your handlers using PHP attributes.
- **Responses & Negotiation** — Let Webrick pick the right `Content-Type`, stream data, and cooperate with ETags/compression.

## When to use guides vs reference
Use **Guides** when you want end‑to‑end, working examples. Use **Reference** when you need API shape, options, or deep behavior notes.

## Quick links
- 👉 [Signed & Temporary URLs](../guides/urls.md)
- 👉 [Attribute Routes](../guides/attributes.md)
- 👉 [Responses & Content Negotiation](../guides/responses-and-negotiation.md)

## Example: sharing a temporary download link

```php
use Infocyph\Webrick\Router\Route;
use Infocyph\Webrick\Response\Response as R;

Route::get('/download/{file}', fn(string $file) => R::attachment(__DIR__.'/files/'.$file))
    ->name('file.download')
    ->middleware(['verifySignedUrl']);

$link = Route::temporaryUrlFor('file.download', ['file' => 'report.pdf'], ttl: 900);
```

**Why it works:** `verifySignedUrl` checks signature + TTL. Keep proxies from altering the query string, or the signature will fail.

## Common pitfalls
- Double compression: enable **either** Webrick compression **or** proxy gzip/deflate/zstd, not both.
- Missing `WEBRICK_SIGN_KEY`: signed URL helpers will fail without a key.
- Attribute discovery: ensure your scan paths are correct and classes are autoloadable.

```{toctree}
:maxdepth: 2
:hidden:
:caption: Guides

routing
groups-and-domains
attribute-routes
attributes
requests
responses
responses-and-negotiation
content-negotiation
streaming
cookies
throttling
urls
```
