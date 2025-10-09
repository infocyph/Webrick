
# Vary Accumulator Middleware

Accumulates `Vary` tokens from downstream components (e.g., `Accept-Encoding`, `Accept-Language`) and deduplicates them.

## Why it matters
Correct `Vary` headers prevent cache poisoning and ensure CDN/proxy keys are accurate.

## Placement (post-global)
```php
postGlobal: [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
  // ...
]
```
