
# Negotiation Middleware

Negotiates media type (and optionally charset and locale) based on `Accept`, `Accept-Charset`, and `Accept-Language`.

## Behavior
- Maps common `+json` vendor types to `application/json`.
- Sets `Content-Type` and optionally `Content-Language` on the response.
- Returns **406 Not Acceptable** early when no common media type is found (configurable).

## Example
```php
preGlobal: [
  \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
  // ...
]
```

Use `Response::auto()` when building bodies to align with negotiated types.
