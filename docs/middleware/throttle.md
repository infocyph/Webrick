
# Throttle Middleware

Limits request rate using the alias syntax `throttle:<limit>,<seconds>`.

## Usage

```php
Route::group(['middleware' => ['throttle:60,60']], function () {
    Route::get('/api/users', [UserController::class, 'index']);
});
```

- Place coarse throttles in **preGlobal** as a safety net.
- Use fine-grained throttles per route/group for fairness.

### Headers
Emits standard rate limit headers (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` when blocked).
