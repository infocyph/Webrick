# Vary Accumulator

Manage the `Vary` header **consistently** across your app. When different parts of your stack add `Vary` tokens (`Accept`, `Accept-Encoding`, `Authorization`, etc.), this middleware collects them, dedupes, canonicalizes order, and removes empty/invalid values—so caches behave predictably.

---

## Why it matters

Intermediaries (CDNs, browsers, proxies) select cache entries by `Vary`. If `Vary` is inconsistent or missing, clients can receive the wrong variant (e.g., gzip to a zstd-only client, HTML to a JSON client).

Common producers of `Vary`:

* **Content negotiation** → `Accept`, `Accept-Language`
* **Compression** → `Accept-Encoding`
* **Auth/Zones** (rarely) → `Authorization` or custom headers
* **Locale/Timezone** → custom `X-*` headers (prefer attributes/query instead)

The accumulator puts them together safely.

---

## Usage

Add to your **post-global** stack (after anything that decides on the final representation):

```php
$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,   // may add Accept-Encoding
  \Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware::class,
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
];
```

> Order: run **after** compression and any content negotiators that might request a `Vary` token.

---

## How it works (conceptually)

* Collect tokens registered during the pipeline (e.g., middleware can call `Vary::add('Accept')`).
* Merge with any existing `Vary` header from the response.
* Normalize: trim, lowercase comparison for dedupe, preserve canonical casing in output (`Accept-Encoding`, `Accept`).
* Remove empties and serialize as a single comma-separated header.

---

## Adding tokens

Anywhere in your code (typically middleware), request `Vary` tokens:

```php
use Infocyph\Webrick\Middleware\VaryAccumulator;

// inside middleware
VaryAccumulator::add('Accept-Encoding');
VaryAccumulator::addIf($shouldVaryByAccept, 'Accept');
VaryAccumulator::add('Accept-Language');
```

At the end of the pipeline, the accumulator writes:

```
Vary: Accept-Encoding, Accept, Accept-Language
```

> If your response already had `Vary: Accept`, it’ll be merged and deduped automatically.

---

## Common recipes

### Content negotiation

```php
VaryAccumulator::add('Accept');
VaryAccumulator::add('Accept-Language');
```

### Compression

```php
VaryAccumulator::add('Accept-Encoding');
```

### Conditional auth variants (use sparingly)

```php
// Only if you truly return different bodies by Authorization (rare)
VaryAccumulator::add('Authorization');
```

---

## Safety notes

* **Don’t over-vary**: every token multiplies cache keys. Use only what truly affects the body.
* **Avoid per-user headers** in `Vary` (e.g., `Cookie`, `Authorization`) unless you **must** serve different bodies by them; otherwise you’ll nuke cache efficiency. Prefer `Cache-Control: private` for user-specific content.
* **Order independence**: caches treat sets, not sequences; the accumulator’s canonical sort helps produce stable strings for observability and diffing.

---

## Example

```php
Route::get('/profile/{id:int}', function ($r, $id) {
    // choose representation by Accept; compression will decide Accept-Encoding
    return Response::auto($r, ['id'=>$id,'ok'=>true]);
});

// Pre/Post stacks
$preGlobal = [
  \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
  \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
];

$postGlobal = [
  \Infocyph\Webrick\Middleware\CompressionMiddleware::class,     // adds Accept-Encoding
  \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class, // consolidates
];
```

Resulting header:

```
Vary: Accept-Encoding, Accept
```

---

## Configuration knobs (typical)

* `defaultTokens`: pre-seeded tokens (e.g., `['Accept-Encoding']`)
* `canonicalize`: bool; sort & standard-case the output (recommended)
* `stripEmpty`: bool; drop empty tokens (default true)

*(Adjust names to your implementation.)*

---

## Troubleshooting

| Symptom                         | Likely cause                              | Fix                                                                  |
| ------------------------------- | ----------------------------------------- | -------------------------------------------------------------------- |
| Missing `Vary: Accept-Encoding` | Compression runs after accumulator        | Place **VaryAccumulatorMiddleware** after compression                |
| Duplicated tokens               | Multiple writers appending manually       | Let the accumulator manage `Vary`; avoid manual string concatenation |
| Cache serving wrong format      | `Vary` missing `Accept`/`Accept-Language` | Add tokens where format/language affects body                        |
| Cache fragmentation             | Over-varying (e.g., `Cookie`)             | Remove noisy tokens; mark responses `private` instead                |

---

## Checklist

* [ ] Add VaryAccumulator to **post-global**, after compression & policies
* [ ] Add only tokens that truly change the body
* [ ] Prefer `private` over `Vary: Cookie/Authorization` for user-specific responses
* [ ] Keep output canonical and deduped for reliable caching & metrics
