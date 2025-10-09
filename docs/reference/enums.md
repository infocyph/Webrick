# Enums & Constants

Canonical enums/constants used across Webrick — straight from the public API. Namespaces and helper methods below match the source code.

---

## HTTP Methods

```php
use Infocyph\Webrick\Constants\HttpMethodEnum as Method;

Method::POST->allowsBody();     // bool
Method::GET->isIdempotent();    // bool
Method::HEAD->isSafe();         // bool
Method::PATCH->specAllowsBody();// bool (per RFC semantics)
Method::tryFromInsensitive('get'); // HttpMethodEnum::GET|null
```

The enum includes core verbs plus CDN/cache and WebDAV extensions.

---

## Media Types

```php
use Infocyph\Webrick\Constants\MediaTypeEnum as Media;

Media::APPLICATION_JSON->isTextual();     // bool
Media::TEXT_HTML->header();                // "text/html"
Media::APPLICATION_JSON->charset();       // "UTF-8"

Media::fromExtension('json');              // APPLICATION_JSON
Media::fromFilename('report.csv');         // TEXT_CSV
```

---

## HTTP Status Codes

```php
use Infocyph\Webrick\Constants\StatusEnum as Status;

Status::OK->reason();               // "OK"
Status::NO_CONTENT->isEmpty();      // true
Status::MOVED_PERMANENTLY->needsLocationHeader(); // true
Status::NOT_MODIFIED->allowsBody(); // false
Status::INTERNAL_SERVER_ERROR->isServerError(); // true
Status::FOUND->isRedirect();        // true
Status::OK->isCacheable();          // true
```

`StatusEnum` also exposes `series()` helpers (`INFORMATIONAL`, `SUCCESS`, `REDIRECTION`, `CLIENT_ERROR`, `SERVER_ERROR`) and `text()` for a short label.

---

## Common constant sets used in examples

While applications define their own, these constants are referenced across the docs:

```php
final class Err {
    public const INPUT           = 'E_INPUT';
    public const RATE_LIMIT      = 'E_RATE_LIMIT';
    public const UNAUTHORIZED    = 'E_UNAUTHORIZED';
    public const MAINTENANCE     = 'E_MAINTENANCE';
    public const BODY_TOO_LARGE  = 'E_BODY_TOO_LARGE';
}
```

