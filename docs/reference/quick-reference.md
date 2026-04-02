# Quick Reference

Cheat sheet for common Webrick operations.

---

## Request

```php
// Method
$method = $r->getMethod();
$r->isGet();  $r->isPost();

// URI
$path = $r->getPath();              // '/users/42'
$uri = $r->getUri();                // 'https://example.com/users/42'
$query = $r->getQueryString();      // 'page=2'

// Query params
$page = $r->query('page', 1);
$all = $r->query();

// Headers
$type = $r->getHeaderLine('Content-Type');
$accept = $r->getHeader('Accept');  // Array

// Body
$json = $r->json();                 // Parsed JSON
$data = $r->input();                // Any parsed body
$raw = $r->getContent();            // Raw string

// Files
$file = $r->file('upload');
$file->moveTo('/uploads/' . $file->getClientFilename());

// Cookies
$session = $r->cookie('session');

// Attributes
$userId = $r->getAttribute('auth.user_id');
$r = $r->withAttribute('key', 'value');
```

---

## Response

```php
// JSON
Response::json(['id' => 42]);
Response::json(['error' => 'Not found'], 404);

// Text
Response::plaintext('Hello World', 200);

// HTML
Response::create('<h1>Title</h1>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

// Empty
Response::noContent();              // 204

// Redirect
Response::redirect('/login');
Response::redirect('/new-url', 301);
Response::redirect(Route::urlFor('users.show', ['id' => 42]));

// Download
Response::download('/path/to/file.pdf');
Response::download('/path/to/file.pdf', 'report.pdf');

// Headers
$response->withHeader('X-Custom', 'value');
$response->withAddedHeader('Set-Cookie', $cookie);
$response->withoutHeader('X-Debug');

// Status
$response->withStatus(404);
$response->getStatusCode();
```

---

## Routes

```php
// Basic
Route::get('/users', fn() => Response::json([]));
Route::post('/users', fn(Request $r) => Response::json($r->input(), 201));
Route::put('/users/{id:int}', fn(int $id) => Response::json(['id' => $id]));
Route::delete('/users/{id:int}', fn(int $id) => Response::noContent());

// With name
Route::get('/users/{id:int}', $handler, 'users.show');

// With middleware
Route::get('/admin', $handler, options: ['middleware' => ['auth', 'admin']]);

// Groups
Route::group(prefix: '/api', middleware: ['throttle:60,60'], callback: function() {
    Route::get('/users', $handler);
});

// Parameters
Route::get('/users/{id:int}', fn(int $id) => /* ... */);
Route::get('/posts/{slug:slug}', fn(string $slug) => /* ... */);
Route::get('/items/{uuid:uuid}', fn(string $uuid) => /* ... */);
```

---

## Middleware

```php
// Closure
$middleware = function (Request $r, Closure $next): Response {
    $r = $r->withAttribute('start', microtime(true));
    $response = $next($r);
    $duration = microtime(true) - $r->getAttribute('start');
    return $response->withHeader('X-Time', $duration . 'ms');
};

// Class
final class AuthMiddleware {
    public function __invoke(Request $r, Closure $next): Response {
        if (!$this->isAuthenticated($r)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($r);
    }
}

// Register alias
MiddlewareAliases::register('auth', fn() => new AuthMiddleware());

// Use
Route::get('/protected', $handler, options: ['middleware' => ['auth']]);
```

---

## URL Generation

```php
// From named route
$url = Route::urlFor('users.show', ['id' => 42]);
// '/users/42'

// Absolute
$url = Route::urlFor('users.show', ['id' => 42], absolute: true);
// 'https://example.com/users/42'

// With query
$url = Route::urlFor('users.index', query: ['page' => 2]);
// '/users?page=2'
```

---

## Signed URLs

```php
// Sign
$signed = Route::signedUrlFor('download.file', ['file' => 'report.pdf']);

// Temporary
$temp = Route::temporaryUrlFor('download.file', ['file' => 'report.pdf'], ttl: 3600);
```

---

## Common Patterns

### RESTful CRUD

```php
Route::get('/posts', [PostController::class, 'index'], 'posts.index');
Route::post('/posts', [PostController::class, 'store'], 'posts.store');
Route::get('/posts/{id:int}', [PostController::class, 'show'], 'posts.show');
Route::put('/posts/{id:int}', [PostController::class, 'update'], 'posts.update');
Route::delete('/posts/{id:int}', [PostController::class, 'destroy'], 'posts.destroy');
```

### API with Auth

```php
Route::group(prefix: '/api', middleware: ['throttle:120,60'], callback: function() {
    // Public
    Route::post('/login', [AuthController::class, 'login']);

    // Protected
    Route::group(middleware: ['auth'], callback: function() {
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
    });
});
```

### File Upload

```php
Route::post('/upload', function(Request $r) {
    $file = $r->file('document');

    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        return Response::json(['error' => 'Upload failed'], 400);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
    $file->moveTo('/uploads/' . $filename);

    return Response::json(['filename' => $filename], 201);
});
```

### Paginated API

```php
Route::get('/api/users', function(Request $r) {
    $page = (int) $r->query('page', 1);
    $perPage = 20;

    $users = UserRepository::paginate($page, $perPage);
    $total = UserRepository::count();

    return Response::json([
        'data' => $users,
        'pagination' => [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
        ]
    ]);
});
```

---

## HTTP Status Codes

```php
200  OK
201  Created
204  No Content
301  Moved Permanently
302  Found
304  Not Modified
400  Bad Request
401  Unauthorized
403  Forbidden
404  Not Found
422  Unprocessable Entity
429  Too Many Requests
500  Internal Server Error
503  Service Unavailable
```
