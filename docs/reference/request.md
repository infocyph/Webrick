# Request API Reference

Complete reference for `Infocyph\Webrick\Request\Request` class.

---

## Table of Contents

- [Creating Requests](#creating-requests)
- [HTTP Method](#http-method)
- [URI & Path](#uri--path)
- [Query Parameters](#query-parameters)
- [Headers](#headers)
- [Body & Input](#body--input)
- [Files](#files)
- [Cookies](#cookies)
- [Attributes](#attributes)
- [Server Variables](#server-variables)
- [Client Information](#client-information)
- [Content Negotiation](#content-negotiation)
- [PSR-7-Style Surface](#psr-7-style-surface)

---

## Creating Requests

### From Globals (Standard)
```php
use Infocyph\Webrick\Request\Request;

$request = Request::fromGlobals();
```

**Reads from**:
- `$_SERVER`
- `$_GET`
- `$_POST`
- `$_COOKIE`
- `$_FILES`
- `php://input`

### Manual Construction
```php
$request = Request::fake(
    query: ['page' => 2],
    post: ['name' => 'John'],
    headers: ['Authorization' => 'Bearer token'],
    method: 'GET',
    uri: '/users/42',
);
```

### From a PSR-7 host

`Request` does not implement `ServerRequestInterface`. Convert at the framework
boundary and preserve all message data needed by the application:

```php
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Psr\Http\Message\ServerRequestInterface;

$psrRequest = /* ... */;
$request = new Request(
    $psrRequest->getMethod(),
    (string) $psrRequest->getUri(),
    $psrRequest->getServerParams(),
    $psrRequest->getHeaders(),
    new Stream((string) $psrRequest->getBody()),
    $psrRequest->getProtocolVersion(),
    $psrRequest->getParsedBody(),
    files: [],
    query: $psrRequest->getQueryParams(),
    cookies: $psrRequest->getCookieParams(),
);
```

This compact example buffers the incoming body. A production adapter may copy
or bridge it differently for large or streaming requests, and should also
normalize uploaded files.

---

## HTTP Method

### Get Method
```php
$method = $request->getMethod();  // 'GET', 'POST', 'PUT', etc.
```

### Check Method
```php
if ($request->isMethod('POST')) {
    // Handle POST
}

// Case-insensitive
$request->isMethod('get');   // true for GET
$request->isMethod('POST');  // true for POST
```

### Common Checks
```php
$request->isGet();      // GET request
$request->isPost();     // POST request
$request->isPut();      // PUT request
$request->isPatch();    // PATCH request
$request->isDelete();   // DELETE request
$request->isHead();     // HEAD request
$request->isOptions();  // OPTIONS request
```

### Method Override

Respects `X-Http-Method-Override` header:
```
POST /resource
X-Http-Method-Override: PUT

// Treated as PUT
$request->getMethod();  // 'PUT'
```

---

## URI & Path

### Full URI
```php
$uri = $request->getUri();
// https://example.com:8080/users/42?page=2#section
```

### Components
```php
$scheme = $request->getScheme();        // 'https'
$host = $request->getHost();            // 'example.com'
$port = $request->getPort();            // 8080
$path = $request->getPath();            // '/users/42'
$queryString = $request->getQueryString();  // 'page=2'
```

### Path Information
```php
$path = $request->getPathInfo();        // '/users/42'
$basePath = $request->getBasePath();    // '' or '/app' if in subdirectory
$baseUrl = $request->getBaseUrl();      // 'https://example.com' or 'https://example.com/app'
```

### URL Building
```php
// Full URL
$url = $request->getSchemeAndHttpHost();  // 'https://example.com:8080'

// With path
$fullUrl = $request->getUri();  // 'https://example.com:8080/users/42?page=2'
```

### HTTPS Detection
```php
if ($request->isSecure()) {
    // HTTPS connection
}

// Respects X-Forwarded-Proto
```

---

## Query Parameters

### Get All
```php
$query = $request->query();
// ['page' => '2', 'sort' => 'name']
```

### Get Single
```php
$page = $request->query('page');           // '2' or null
$page = $request->query('page', 1);        // '2' or default 1
$page = (int) $request->query('page', 1);  // Type cast
```

### Check Existence
```php
if ($request->query->has('filter')) {
    // Filter parameter exists
}
```

### Get Multiple
```php
$params = $request->query(['page', 'sort', 'filter']);
// ['page' => '2', 'sort' => 'name', 'filter' => null]
```

---

## Headers

### Get All
```php
$headers = $request->getHeaders();
// ['Content-Type' => ['application/json'], 'Accept' => ['*/*']]
```

### Get Single
```php
$contentType = $request->getHeaderLine('Content-Type');
// 'application/json'

$accept = $request->getHeader('Accept');
// ['application/json', 'text/html']  (array)
```

### Case-Insensitive
```php
$request->getHeaderLine('content-type');  // Works
$request->getHeaderLine('Content-Type');  // Works
$request->getHeaderLine('CONTENT-TYPE');  // Works
```

### Check Existence
```php
if ($request->hasHeader('Authorization')) {
    $token = $request->getHeaderLine('Authorization');
}
```

### Common Headers
```php
$contentType = $request->getHeaderLine('Content-Type');
$accept = $request->getHeaderLine('Accept');
$userAgent = $request->getHeaderLine('User-Agent');
$referer = $request->getHeaderLine('Referer');
$auth = $request->getHeaderLine('Authorization');
```

---

## Body & Input

### Raw Body
```php
$body = $request->getContent();
// Raw string from php://input
```

### Parsed Input (Any Method)
```php
// Parsed based on Content-Type
$data = $request->input();

// JSON: Content-Type: application/json
// Returns associative array

// Form: Content-Type: application/x-www-form-urlencoded
// Returns associative array

// Multipart: Content-Type: multipart/form-data
// Returns associative array
```

### Get Specific Field
```php
$name = $request->input('name');              // null if missing
$name = $request->input('name', 'Guest');     // With default
```

### Nested Fields
```php
// Input: {"user": {"name": "John", "age": 30}}
$name = $request->input('user.name');  // 'John'
$age = $request->input('user.age');    // 30
```

### JSON Input
```php
// Content-Type: application/json
// Body: {"name":"John","email":"john@example.com"}

$json = $request->json();
// ['name' => 'John', 'email' => 'john@example.com']

$name = $request->json('name');  // 'John'
```

### POST Data
```php
// Only for POST method + form data
$post = $request->post();           // All POST data
$value = $request->post('field');   // Single field
```

### Input Presence
```php
if ($request->has('email')) {
    // Field exists in input
}

if ($request->filled('email')) {
    // Field exists and is not empty
}

// Multiple fields
if ($request->hasAll(['name', 'email'])) {
    // All fields exist
}
```

### Get Subset
```php
// Only these fields
$data = $request->only(['name', 'email']);

// Everything except these
$data = $request->except(['password', 'token']);
```

---

## Files

### Get All Files
```php
$files = $request->files();
// Array of UploadedFileInterface objects
```

### Get Single File
```php
$avatar = $request->file('avatar');

if ($avatar && $avatar->getError() === UPLOAD_ERR_OK) {
    $avatar->moveTo('/path/to/uploads/' . $avatar->getClientFilename());
}
```

### File Properties
```php
$file = $request->file('document');

$name = $file->getClientFilename();       // 'document.pdf'
$size = $file->getSize();                 // 1048576 (bytes)
$type = $file->getClientMediaType();      // 'application/pdf'
$error = $file->getError();               // UPLOAD_ERR_OK
$tmpPath = $file->getStream()->getMetadata('uri');  // Temp file path
```

### Multiple Files
```php
// HTML: <input type="file" name="photos[]" multiple>
$photos = $request->file('photos');  // Array of files

foreach ($photos as $photo) {
    if ($photo->getError() === UPLOAD_ERR_OK) {
        $photo->moveTo('/uploads/' . $photo->getClientFilename());
    }
}
```

---

## Cookies

### Get All
```php
$cookies = $request->getCookieParams();
// ['session' => 'abc123', 'theme' => 'dark']
```

### Get Single
```php
$session = $request->cookie('session');           // 'abc123' or null
$session = $request->cookie('session', 'guest');  // With default
```

**Note**: If `CookieEncryptionMiddleware` is enabled, values are automatically decrypted.

---

## Attributes

Request attributes store middleware/handler data.

### Set Attribute
```php
$request = $request->withAttribute('user_id', 42);
$request = $request->withAttribute('auth.roles', ['admin', 'editor']);
```

### Get Attribute
```php
$userId = $request->getAttribute('user_id');              // 42 or null
$userId = $request->getAttribute('user_id', 0);           // With default
$roles = $request->getAttribute('auth.roles', []);
```

### Get All Attributes
```php
$attributes = $request->getAttributes();
```

### Common Attributes (Set by Middleware)
```php
$request->getAttribute('client_ip');              // Real client IP
$request->getAttribute('auth.user_id');           // Authenticated user
$request->getAttribute('negotiated.type');        // Negotiated media type
$request->getAttribute('locale');                 // Negotiated locale
$request->getAttribute('trace.trace_id');         // W3C trace ID
$request->getAttribute('request_id');             // Request ID
```

---

## Server Variables

### Get All
```php
$server = $request->getServerParams();
```

### Get Single
```php
$remoteAddr = $request->server('REMOTE_ADDR');
$scriptName = $request->server('SCRIPT_NAME');
$requestUri = $request->server('REQUEST_URI');
```

### Common Variables
```php
$request->server('REQUEST_METHOD');     // 'GET'
$request->server('SERVER_PROTOCOL');    // 'HTTP/1.1'
$request->server('REMOTE_ADDR');        // '203.0.113.10'
$request->server('HTTP_HOST');          // 'example.com'
$request->server('REQUEST_URI');        // '/users/42?page=2'
```

---

## Client Information

### IP Address
```php
// Real client IP (respects X-Forwarded-For if proxy trusted)
$ip = $request->getAttribute('client_ip');

// Direct connection IP
$ip = $request->server('REMOTE_ADDR');
```

### User Agent
```php
$userAgent = $request->getHeaderLine('User-Agent');
// 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)...'
```

### Referrer
```php
$referer = $request->getHeaderLine('Referer');
// 'https://google.com/search?q=...'
```

### Accept Languages
```php
$languages = $request->getHeaderLine('Accept-Language');
// 'en-US,en;q=0.9,es;q=0.8'

// Parsed by NegotiationMiddleware
$locale = $request->getAttribute('locale');  // 'en'
```

---

## Content Negotiation

These attributes are set by `NegotiationMiddleware`:
```php
// Best media type match
$type = $request->getAttribute('negotiated.type');
// 'application/json'

// Best charset match
$charset = $request->getAttribute('negotiated.charset');
// 'utf-8'

// Best locale match
$locale = $request->getAttribute('locale');
// 'en'
```

---

## PSR-7-Style Surface

Webrick follows familiar immutable message method names, but its concrete
request, URI, stream, and uploaded-file classes do not implement the PSR-7
interfaces. Use explicit adapters when an interface-typed framework owns the
HTTP boundary.

### Immutability

All `with*` methods return new instance:
```php
$request1 = Request::createFromGlobals();
$request2 = $request1->withHeader('X-Custom', 'value');

// $request1 unchanged
// $request2 has the header
```

### Methods
```php
// Immutable modifications
$request->withMethod('PUT');
$request->withUri($uri);
$request->withHeader('X-Custom', 'value');
$request->withAddedHeader('Accept', 'application/json');
$request->withoutHeader('X-Debug');
$request->withBody($stream);
$request->withAttribute('key', 'value');
$request->withoutAttribute('key');
$request->withQueryParams(['page' => 2]);
$request->withCookieParams(['session' => 'abc']);
$request->withUploadedFiles($files);
$request->withParsedBody($data);
```

---

## Common Patterns

### Extract Route Parameters
```php
// In handler (after routing)
Route::get('/users/{id:int}', function (Request $r, int $id) {
    // $id is automatically injected
    return Response::json(['id' => $id]);
});
```

### Validate Input
```php
Route::post('/users', function (Request $r) {
    $data = $r->input();

    if (!isset($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return Response::json(['error' => 'Invalid email'], 400);
    }

    // Process...
});
```

### Check Authentication
```php
Route::get('/profile', function (Request $r) {
    $userId = $r->getAttribute('auth.user_id');

    if (!$userId) {
        return Response::json(['error' => 'Not authenticated'], 401);
    }

    $user = UserRepository::find($userId);
    return Response::json($user);
});
```

### Content Type Branching
```php
Route::post('/data', function (Request $r) {
    $contentType = $r->getHeaderLine('Content-Type');

    if (str_contains($contentType, 'application/json')) {
        $data = $r->json();
    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        $data = $r->post();
    } else {
        return Response::json(['error' => 'Unsupported media type'], 415);
    }

    // Process $data...
});
```

### Debug Request
```php
Route::get('/__debug/request', function (Request $r) {
    return Response::json([
        'method' => $r->getMethod(),
        'uri' => $r->getUri(),
        'headers' => $r->getHeaders(),
        'query' => $r->query(),
        'input' => $r->input(),
        'cookies' => $r->getCookieParams(),
        'attributes' => $r->getAttributes(),
        'server' => $r->getServerParams(),
    ]);
});
```

---

## Method Summary

### HTTP Method
- `getMethod(): string`
- `isMethod(string $method): bool`
- `isGet(): bool`
- `isPost(): bool`
- `isPut(): bool`
- `isPatch(): bool`
- `isDelete(): bool`
- `isHead(): bool`
- `isOptions(): bool`

### URI
- `getUri(): string`
- `getScheme(): string`
- `getHost(): string`
- `getPort(): int`
- `getPath(): string`
- `getPathInfo(): string`
- `getQueryString(): string`
- `getBaseUrl(): string`
- `getSchemeAndHttpHost(): string`
- `isSecure(): bool`

### Query
- `query(?string $key = null, mixed $default = null): mixed`
- `getQueryParams(): array`

### Headers
- `getHeaders(): array`
- `hasHeader(string $name): bool`
- `getHeader(string $name): array`
- `getHeaderLine(string $name): string`

### Body
- `getContent(): string`
- `input(?string $key = null, mixed $default = null): mixed`
- `json(?string $key = null, mixed $default = null): mixed`
- `post(?string $key = null, mixed $default = null): mixed`
- `has(string|array $keys): bool`
- `hasAll(array $keys): bool`
- `filled(string $key): bool`
- `only(array $keys): array`
- `except(array $keys): array`

### Files
- `files(): UploadedFileCollection`
- `file(?string $key = null): UploadedFile|array|null`

### Cookies
- `getCookieParams(): array`
- `cookie(?string $key = null): mixed`

### Attributes
- `getAttributes(): array`
- `getAttribute(string $name, mixed $default = null): mixed`
- `withAttribute(string $name, mixed $value): static`
- `withoutAttribute(string $name): static`

### Server
- `getServerParams(): array`
- `server(?string $key = null): mixed`

### PSR-7-style methods
- `withMethod(string $method): static`
- `withUri(Uri $uri, bool $preserveHost = false): static`
- `withHeader(string $name, $value): static`
- `withAddedHeader(string $name, $value): static`
- `withoutHeader(string $name): static`
- `withBody(Stream $body): static`
- `withQueryParams(array $query): static`
- `withCookieParams(array $cookies): static`
- `withUploadedFiles(array $files): static`
- `withParsedBody($data): static`
