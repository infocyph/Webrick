# RESTful API Recipe

Build a complete, standards-compliant REST API.

---

## Problem

You need a RESTful API with proper HTTP methods, status codes, error handling and documentation.

---

## Solution

### 1. API Structure
```
routes/
├── api.php          # API routes
src/
├── Controller/
│   └── Api/
│       ├── UserController.php
│       ├── PostController.php
│       └── CommentController.php
└── Repository/
    ├── UserRepository.php
    ├── PostRepository.php
    └── CommentRepository.php
```

### 2. Base API Controller
```php
<?php
// src/Controller/Api/BaseApiController.php

namespace App\Controller\Api;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

abstract class BaseApiController
{
    protected function success(mixed $data, int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'data' => $data
        ], $status);
    }

    protected function error(
        string $message,
        int $status = 400,
        ?array $details = null
    ): Response {
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $status
            ]
        ];

        if ($details) {
            $response['error']['details'] = $details;
        }

        return Response::json($response, $status);
    }

    protected function paginate(
        array $items,
        int $total,
        int $page,
        int $perPage
    ): Response {
        return Response::json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'count' => count($items),
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ]);
    }

    protected function created(mixed $data, string $location): Response
    {
        return Response::json([
            'success' => true,
            'data' => $data
        ], 201)->withHeader('Location', $location);
    }

    protected function noContent(): Response
    {
        return Response::create('', 204);
    }

    protected function validateInput(Request $r, array $rules): array|Response
    {
        $data = $r->input();
        $errors = [];

        foreach ($rules as $field => $rule) {
            if ($rule['required'] ?? false) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    $errors[$field][] = "{$field} is required";
                }
            }

            if (isset($data[$field])) {
                $value = $data[$field];

                if (isset($rule['email']) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} must be a valid email";
                }

                if (isset($rule['min']) && strlen($value) < $rule['min']) {
                    $errors[$field][] = "{$field} must be at least {$rule['min']} characters";
                }

                if (isset($rule['max']) && strlen($value) > $rule['max']) {
                    $errors[$field][] = "{$field} must not exceed {$rule['max']} characters";
                }
            }
        }

        if ($errors) {
            return $this->error('Validation failed', 422, $errors);
        }

        return $data;
    }
}
```

### 3. User API Controller
```php
<?php
// src/Controller/Api/UserController.php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class UserController extends BaseApiController
{
    public function __construct(
        private UserRepository $users
    ) {}

    public function index(Request $r): Response
    {
        $page = (int) $r->query('page', 1);
        $perPage = (int) $r->query('per_page', 20);
        $search = $r->query('search');

        $users = $this->users->paginate($page, $perPage, $search);
        $total = $this->users->count($search);

        return $this->paginate($users, $total, $page, $perPage);
    }

    public function show(int $id): Response
    {
        $user = $this->users->find($id);

        if (!$user) {
            return $this->error('User not found', 404);
        }

        return $this->success($user);
    }

    public function store(Request $r): Response
    {
        $validation = $this->validateInput($r, [
            'name' => ['required' => true, 'min' => 2, 'max' => 100],
            'email' => ['required' => true, 'email' => true],
            'password' => ['required' => true, 'min' => 8]
        ]);

        if ($validation instanceof Response) {
            return $validation;  // Validation error
        }

        // Check email uniqueness
        if ($this->users->existsByEmail($validation['email'])) {
            return $this->error('Email already in use', 422, [
                'email' => ['Email already registered']
            ]);
        }

        $user = $this->users->create([
            'name' => $validation['name'],
            'email' => $validation['email'],
            'password_hash' => password_hash($validation['password'], PASSWORD_DEFAULT)
        ]);

        return $this->created($user, "/api/users/{$user['id']}");
    }

    public function update(Request $r, int $id): Response
    {
        $user = $this->users->find($id);

        if (!$user) {
            return $this->error('User not found', 404);
        }

        $validation = $this->validateInput($r, [
            'name' => ['min' => 2, 'max' => 100],
            'email' => ['email' => true]
        ]);

        if ($validation instanceof Response) {
            return $validation;
        }

        $updated = $this->users->update($id, array_filter($validation));

        return $this->success($updated);
    }

    public function destroy(int $id): Response
    {
        $user = $this->users->find($id);

        if (!$user) {
            return $this->error('User not found', 404);
        }

        $this->users->delete($id);

        return $this->noContent();
    }
}
```

### 4. API Routes
```php
<?php
// routes/api.php

use Infocyph\Webrick\Router\Facade\Router as Route;
use App\Controller\Api\UserController;

Route::group(prefix: '/api', middleware: ['throttle:120,60'], callback: function() {

    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);

    // Protected routes
    Route::group(middleware: ['auth'], callback: function() {

        // Users resource
        Route::get('/users', [UserController::class, 'index'], 'api.users.index');
        Route::get('/users/{id:int}', [UserController::class, 'show'], 'api.users.show');
        Route::post('/users', [UserController::class, 'store'], 'api.users.store');
        Route::put('/users/{id:int}', [UserController::class, 'update'], 'api.users.update');
        Route::delete('/users/{id:int}', [UserController::class, 'destroy'], 'api.users.destroy');

        // Posts resource
        Route::get('/posts', [PostController::class, 'index'], 'api.posts.index');
        Route::get('/posts/{id:int}', [PostController::class, 'show'], 'api.posts.show');
        Route::post('/posts', [PostController::class, 'store'], 'api.posts.store');
        Route::put('/posts/{id:int}', [PostController::class, 'update'], 'api.posts.update');
        Route::delete('/posts/{id:int}', [PostController::class, 'destroy'], 'api.posts.destroy');

        // Nested resource: Post comments
        Route::get('/posts/{postId:int}/comments', [CommentController::class, 'index'], 'api.posts.comments.index');
        Route::post('/posts/{postId:int}/comments', [CommentController::class, 'store'], 'api.posts.comments.store');
    });
});
```

### 5. Error Handler
```php
<?php
// src/Handler/ApiErrorHandler.php

namespace App\Handler;

use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;

final class ApiErrorHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $debug = false
    ) {}

    public function handle(\Throwable $e): Response
    {
        $this->logger->error('API Error', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        $response = [
            'success' => false,
            'error' => [
                'message' => 'Internal Server Error',
                'code' => 500
            ]
        ];

        if ($this->debug) {
            $response['error']['debug'] = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ];
        }

        return Response::json($response, 500);
    }
}
```

---

## Testing

### Manual Tests
```bash
# List users (paginated)
curl http://localhost:8000/api/users?page=1&per_page=10 \
  -H "Authorization: Bearer $TOKEN"

# Get single user
curl http://localhost:8000/api/users/1 \
  -H "Authorization: Bearer $TOKEN"

# Create user
curl -X POST http://localhost:8000/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"John Doe","email":"john@example.com","password":"secret123"}'

# Update user
curl -X PUT http://localhost:8000/api/users/1 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane Doe"}'

# Delete user
curl -X DELETE http://localhost:8000/api/users/1 \
  -H "Authorization: Bearer $TOKEN"
```

### Integration Test
```php
<?php

use PHPUnit\Framework\TestCase;
use Infocyph\Webrick\Request\Request;

class UserApiTest extends TestCase
{
    private $kernel;
    private $token;

    protected function setUp(): void
    {
        $this->kernel = /* ... boot kernel ... */;
        $this->token = $this->loginAndGetToken();
    }

    public function testListUsers(): void
    {
        $request = Request::fake(
            headers: ['Authorization' => "Bearer {$this->token}"],
            method: 'GET',
            uri: '/api/users',
        );

        $response = $this->kernel->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('pagination', $data);
    }

    public function testCreateUser(): void
    {
        $request = Request::fake(
            post: [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret123',
            ],
            headers: [
                'Authorization' => "Bearer {$this->token}",
                'Content-Type' => 'application/json',
            ],
            method: 'POST',
            uri: '/api/users',
        );

        $response = $this->kernel->handle($request);

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));

        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('id', $data['data']);
    }

    public function testValidationError(): void
    {
        $request = Request::fake(
            post: [
                'name' => 'X',  // Too short
                'email' => 'invalid-email',  // Invalid format
            ],
            headers: [
                'Authorization' => "Bearer {$this->token}",
                'Content-Type' => 'application/json',
            ],
            method: 'POST',
            uri: '/api/users',
        );

        $response = $this->kernel->handle($request);

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('details', $data['error']);
    }
}
```

---

## Variations

### Versioned API
```php
// routes/api.php

Route::group(prefix: '/api/v1', namePrefix: 'v1.', callback: function() {
    Route::get('/users', [V1\UserController::class, 'index']);
});

Route::group(prefix: '/api/v2', namePrefix: 'v2.', callback: function() {
    Route::get('/users', [V2\UserController::class, 'index']);
});
```

### API with HATEOAS Links
```php
protected function success(mixed $data, int $status = 200): Response
{
    if (is_array($data) && isset($data['id'])) {
        $data['_links'] = [
            'self' => ['href' => "/api/users/{$data['id']}"],
            'posts' => ['href' => "/api/users/{$data['id']}/posts"],
            'edit' => ['href' => "/api/users/{$data['id']}"],
            'delete' => ['href' => "/api/users/{$data['id']}"]
        ];
    }

    return Response::json([
        'success' => true,
        'data' => $data
    ], $status);
}
```

### API with ETags (Caching)

```php
public function show(Request $r, int $id): Response
{
    $user = $this->users->find($id);

    if (!$user) {
        return $this->error('User not found', 404);
    }

    // Generate ETag from content
    $etag = md5(json_encode($user));
    $clientETag = $r->getHeaderLine('If-None-Match');

    if ($clientETag === "\"{$etag}\"") {
        return Response::create('', 304)
            ->withHeader('ETag', "\"{$etag}\"");
    }

    return $this->success($user)
        ->withHeader('ETag', "\"{$etag}\"")
        ->withHeader('Cache-Control', 'max-age=60');
}
```

### Sparse Fieldsets (JSON API)

```php
public function show(Request $r, int $id): Response
{
    $user = $this->users->find($id);

    if (!$user) {
        return $this->error('User not found', 404);
    }

    // Filter fields based on query parameter
    $fields = $r->query('fields');

    if ($fields) {
        $allowedFields = explode(',', $fields);
        $user = array_intersect_key($user, array_flip($allowedFields));
    }

    return $this->success($user);
}

// Usage: GET /api/users/1?fields=id,name,email
```

### Bulk Operations

```php
Route::post('/api/users/bulk', function(Request $r) {
    $operations = $r->input('operations');
    $results = [];

    foreach ($operations as $op) {
        switch ($op['action']) {
            case 'create':
                $results[] = UserRepository::create($op['data']);
                break;
            case 'update':
                $results[] = UserRepository::update($op['id'], $op['data']);
                break;
            case 'delete':
                UserRepository::delete($op['id']);
                $results[] = ['id' => $op['id'], 'deleted' => true];
                break;
        }
    }

    return Response::json([
        'success' => true,
        'data' => $results
    ]);
});

// Usage:
// POST /api/users/bulk
// {
//   "operations": [
//     {"action": "create", "data": {"name": "John", "email": "john@example.com"}},
//     {"action": "update", "id": 5, "data": {"name": "Jane"}},
//     {"action": "delete", "id": 10}
//   ]
// }
```

---

## API Documentation

### OpenAPI/Swagger Specification

```yaml
# docs/openapi.yaml

openapi: 3.0.0
info:
  title: Webrick API
  version: 1.0.0
  description: RESTful API for user management

servers:
  - url: http://localhost:8000/api
    description: Development server

components:
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: JWT

  schemas:
    User:
      type: object
      properties:
        id:
          type: integer
          example: 1
        name:
          type: string
          example: John Doe
        email:
          type: string
          format: email
          example: john@example.com
        created_at:
          type: string
          format: date-time

    Error:
      type: object
      properties:
        success:
          type: boolean
          example: false
        error:
          type: object
          properties:
            message:
              type: string
            code:
              type: integer
            details:
              type: object

paths:
  /users:
    get:
      summary: List users
      security:
        - BearerAuth: []
      parameters:
        - name: page
          in: query
          schema:
            type: integer
            default: 1
        - name: per_page
          in: query
          schema:
            type: integer
            default: 20
        - name: search
          in: query
          schema:
            type: string
      responses:
        '200':
          description: Successful response
          content:
            application/json:
              schema:
                type: object
                properties:
                  success:
                    type: boolean
                  data:
                    type: array
                    items:
                      $ref: '#/components/schemas/User'
                  pagination:
                    type: object

    post:
      summary: Create user
      security:
        - BearerAuth: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required:
                - name
                - email
                - password
              properties:
                name:
                  type: string
                email:
                  type: string
                  format: email
                password:
                  type: string
                  minLength: 8
      responses:
        '201':
          description: User created
          content:
            application/json:
              schema:
                type: object
                properties:
                  success:
                    type: boolean
                  data:
                    $ref: '#/components/schemas/User'
        '422':
          description: Validation error
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'

  /users/{id}:
    get:
      summary: Get user by ID
      security:
        - BearerAuth: []
      parameters:
        - name: id
          in: path
          required: true
          schema:
            type: integer
      responses:
        '200':
          description: Successful response
        '404':
          description: User not found
```

### Serve Documentation

```php
Route::get('/api/docs', function() {
    return Response::create(
        file_get_contents(__DIR__ . '/../docs/api.html'),
        200,
        ['Content-Type' => 'text/html; charset=UTF-8']
    );
});

Route::get('/api/openapi.yaml', function() {
    return Response::create(
        file_get_contents(__DIR__ . '/../docs/openapi.yaml'),
        200,
        ['Content-Type' => 'application/x-yaml']
    );
});
```

---

## Best Practices

### 1. Use Proper HTTP Methods

```php
// ✅ Correct
Route::get('/users', $handler);          // List/Read
Route::post('/users', $handler);         // Create
Route::put('/users/{id}', $handler);     // Full update
Route::patch('/users/{id}', $handler);   // Partial update
Route::delete('/users/{id}', $handler);  // Delete

// ❌ Wrong
Route::post('/users/delete', $handler);  // Use DELETE method
Route::get('/users/create', $handler);   // Use POST method
```

### 2. Use Proper Status Codes

```php
200  // OK - Successful GET, PUT, PATCH
201  // Created - Successful POST
204  // No Content - Successful DELETE
400  // Bad Request - Invalid input
401  // Unauthorized - Missing/invalid token
403  // Forbidden - Valid token, insufficient permissions
404  // Not Found - Resource doesn't exist
422  // Unprocessable Entity - Validation failed
429  // Too Many Requests - Rate limit exceeded
500  // Internal Server Error - Server error
```

### 3. Consistent Response Format

```php
// Success
{
  "success": true,
  "data": { /* ... */ }
}

// Error
{
  "success": false,
  "error": {
    "message": "Error description",
    "code": 422,
    "details": { /* validation errors */ }
  }
}
```

### 4. Version Your API

```php
// URL versioning (recommended)
/api/v1/users
/api/v2/users

// Header versioning (alternative)
Accept: application/vnd.myapi.v1+json
```

### 5. Implement Rate Limiting

```php
Route::group(
    prefix: '/api',
    middleware: ['throttle:120,60'],  // 120 requests per minute
    callback: function() { /* ... */ }
);
```

### 6. Use HTTPS in Production

```php
if ($_ENV['APP_ENV'] === 'production' && !$request->isSecure()) {
    return Response::redirect(
        'https://' . $request->getHost() . $request->getPath(),
        301
    );
}
```

### 7. Implement CORS Properly

```php
Route::options('/api/*', function() {
    return Response::create('', 204, [
        'Access-Control-Allow-Origin' => 'https://app.example.com',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        'Access-Control-Max-Age' => '3600'
    ]);
});
```

### 8. Log API Usage

```php
Route::group(prefix: '/api', callback: function() use ($logger) {
    Route::post('/users', function(Request $r) use ($logger) {
        $logger->info('User created', [
            'user_id' => $r->getAttribute('auth.user_id'),
            'ip' => $r->getAttribute('client_ip'),
            'user_agent' => $r->getHeaderLine('User-Agent')
        ]);

        // ... create user ...
    });
});
```

---

## Performance Optimization

### Database Query Optimization

```php
// ❌ N+1 problem
public function index(): Response
{
    $users = UserRepository::all();

    foreach ($users as &$user) {
        $user['posts'] = PostRepository::findByUser($user['id']);  // N queries
    }

    return $this->success($users);
}

// ✅ Eager loading
public function index(): Response
{
    $users = UserRepository::allWithPosts();  // 1 query with JOIN
    return $this->success($users);
}
```

### Response Caching

```php
Route::get('/api/users', function(Request $r) {
    $cacheKey = 'api:users:' . $r->getQueryString();

    $cached = Cache::get($cacheKey);
    if ($cached) {
        return Response::json($cached)
            ->withHeader('X-Cache', 'HIT');
    }

    $users = UserRepository::all();
    Cache::set($cacheKey, $users, 300);  // 5 minutes

    return Response::json($users)
        ->withHeader('X-Cache', 'MISS');
});
```

### Database Pagination

```php
// ❌ Bad: Load all records
$allUsers = UserRepository::all();
$page = array_slice($allUsers, ($page - 1) * $perPage, $perPage);

// ✅ Good: Database-level pagination
$users = UserRepository::paginate($page, $perPage);
// SELECT * FROM users LIMIT 20 OFFSET 0
```

---

## Summary

**This recipe provides**:
- ✅ RESTful resource routing
- ✅ Proper HTTP methods and status codes
- ✅ Input validation
- ✅ Error handling
- ✅ Pagination
- ✅ Authentication integration
- ✅ OpenAPI documentation

**Production checklist**:
- [ ] Use proper HTTP methods and status codes
- [ ] Implement authentication and authorization
- [ ] Add rate limiting
- [ ] Enable CORS for allowed origins
- [ ] Version your API
- [ ] Document with OpenAPI/Swagger
- [ ] Add comprehensive error handling
- [ ] Implement caching where appropriate
- [ ] Log API usage for monitoring
- [ ] Use HTTPS in production
