# Normalize Method Middleware

Normalizes HTTP verbs safely (e.g., `POST` with `_method=PUT`) for clients that cannot send all verbs.

## Guidance
- Only allow overrides from safe sources (form field or header) that you explicitly enable.
- Disallow overrides on `GET` requests to avoid cache poisoning.

```php
preGlobal: [
  [\Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class, [
      'param' => '_method',       // or header: 'X-HTTP-Method-Override'
      'allow_on' => ['POST'],     // only allow overrides on these verbs
      'allowed' => ['PUT','PATCH','DELETE']
  ]],
]
```
