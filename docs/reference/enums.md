# Enums & Constants

Canonical enums/constants used across Webrick—handy for IDE completion and avoiding stringly-typed bugs.

---

## HTTP Methods

```php
namespace Infocyph\Webrick\Http;

enum Method: string {
  case GET = 'GET';
  case POST = 'POST';
  case PUT = 'PUT';
  case PATCH = 'PATCH';
  case DELETE = 'DELETE';
  case HEAD = 'HEAD';
  case OPTIONS = 'OPTIONS';
  case ANY = 'ANY'; // internal use for match/any
}
```

Where you can use it:

```php
use Infocyph\Webrick\Http\Method;
Route::match([Method::GET->value, Method::POST->value], '/search', $handler);
```

---

## Status Codes

```php
namespace Infocyph\Webrick\Http;

enum Status: int {
  case OK = 200;
  case CREATED = 201;
  case NO_CONTENT = 204;

  case MOVED_PERMANENTLY = 301;
  case FOUND = 302;
  case SEE_OTHER = 303;
  case NOT_MODIFIED = 304;

  case BAD_REQUEST = 400;
  case UNAUTHORIZED = 401;
  case FORBIDDEN = 403;
  case NOT_FOUND = 404;
  case METHOD_NOT_ALLOWED = 405;
  case CONFLICT = 409;
  case PRECONDITION_FAILED = 412;
  case PAYLOAD_TOO_LARGE = 413;
  case TOO_MANY_REQUESTS = 429;

  case INTERNAL_SERVER_ERROR = 500;
  case SERVICE_UNAVAILABLE = 503;
}
```

Example:

```php
return Response::json(['ok'=>true], \Infocyph\Webrick\Http\Status::CREATED->value);
```

---

## Content Types

```php
namespace Infocyph\Webrick\Http;

final class Media {
  public const JSON  = 'application/json; charset=UTF-8';
  public const TEXT  = 'text/plain; charset=UTF-8';
  public const HTML  = 'text/html; charset=UTF-8';
  public const XML   = 'application/xml';
  public const NDJSON= 'application/x-ndjson; charset=UTF-8';
  public const CSV   = 'text/csv; charset=UTF-8';
}
```

---

## Route Token Aliases

```php
namespace Infocyph\Webrick\Router;

final class Token {
  // Names map to regexes internally
  public const INT  = ':int';
  public const UUID = ':uuid';
  public const SLUG = ':slug';
  public const HEX  = ':hex';
  public const ANY  = ':any';
}
```

Use in route templates:

```php
Route::get('/users/{id'.Token::INT.'}', $handler);
```

---

## Throttle Headers

```php
namespace Infocyph\Webrick\RateLimit;

final class Headers {
  public const X_LIMIT      = 'X-RateLimit-Limit';
  public const X_REMAINING  = 'X-RateLimit-Remaining';
  public const X_RESET      = 'X-RateLimit-Reset';
  public const RETRY_AFTER  = 'Retry-After';

  // IETF optional
  public const LIMIT        = 'RateLimit-Limit';
  public const REMAINING    = 'RateLimit-Remaining';
  public const RESET        = 'RateLimit-Reset';
}
```

---

## Vary Tokens

```php
namespace Infocyph\Webrick\Http;

final class VaryToken {
  public const ACCEPT           = 'Accept';
  public const ACCEPT_LANGUAGE  = 'Accept-Language';
  public const ACCEPT_ENCODING  = 'Accept-Encoding';
  public const ORIGIN           = 'Origin';
}
```

---

## Error Codes (suggested, project-specific)

While applications define their own, here’s a common set used in examples:

```php
final class Err {
  public const INPUT        = 'E_INPUT';
  public const RATE_LIMIT   = 'E_RATE_LIMIT';
  public const UNAUTHORIZED = 'E_UNAUTHORIZED';
  public const MAINTENANCE  = 'E_MAINTENANCE';
  public const BODY_TOO_LARGE = 'E_BODY_TOO_LARGE';
}
```
