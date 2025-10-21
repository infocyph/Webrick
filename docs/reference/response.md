# Response API Reference

Complete reference for `Infocyph\Webrick\Response\Response` class.

---

## Table of Contents

- [Creating Responses](#creating-responses)
- [Status Codes](#status-codes)
- [Headers](#headers)
- [Body Content](#body-content)
- [Content Types](#content-types)
- [Response Helpers](#response-helpers)
- [Redirects](#redirects)
- [File Downloads](#file-downloads)
- [Streaming](#streaming)
- [Cookies](#cookies)
- [PSR-7 Compatibility](#psr-7-compatibility)

---

## Creating Responses

### Basic Response
```php
use Infocyph\Webrick\Response\Response;

// Simple text
$response = Response::create('Hello World', 200);

// With headers
$response = Response::create('Content', 200, [
    'Content-Type' => 'text/plain',
    'X-Custom' => 'value'
]);
```

### From Body
```php
$body = fopen('php://memory', 'r+');
fwrite($body, 'Content');
rewind($body);

$response = Response::fromBody($body, 200);
```

---

## Status Codes

### Set Status
```php
$response = Response::create('Content', 404);

// Or modify existing
$response = $response->withStatus(500);
$response = $response->withStatus(200, 'OK');  // With reason phrase
```

### Common Status Codes
```php
Response::create('', 200);  // OK
Response::create('', 201);  // Created
Response::create('', 204);  // No Content
Response::create('', 301);  // Moved Permanently
Response::create('', 302);  // Found
Response::create('', 304);  // Not Modified
Response::create('', 400);  // Bad Request
Response::create('', 401);  // Unauthorized
Response::create('', 403);  // Forbidden
Response::create('', 404);  // Not Found
Response::create('', 422);  // Unprocessable Entity
Response::create('', 429);  // Too Many Requests
Response::create('', 500);  // Internal Server Error
Response::create('', 503);  // Service Unavailable
```

### Get Status
```php
$code = $response->getStatusCode();  // 200
$reason = $response->getReasonPhrase();  // 'OK'
```

---

## Headers

### Set Header
```php
// Replace existing
$response = $response->withHeader('Content-Type', 'application/json');

// Add (doesn't replace)
$response = $response->withAddedHeader('Set-Cookie', 'session=abc');
```

### Get Headers
```php
$headers = $response->getHeaders();
// ['Content-Type' => ['application/json']]

$type = $response->getHeaderLine('Content-Type');
// 'application/json'

$cookies = $response->getHeader('Set-Cookie');
// ['session=abc', 'theme=dark']  (array)
```

### Remove Header
```php
$response = $response->withoutHeader('X-Debug');
```

### Common Headers
```php
// Content Type
$response->withHeader('Content-Type', 'application/json; charset=utf-8');

// Cache Control
$response->withHeader('Cache-Control', 'public, max-age=3600');

// CORS
$response->withHeader('Access-Control-Allow-Origin', '*');

// Security
$response->withHeader('X-Content-Type-Options', 'nosniff');
$response->withHeader('X-Frame-Options', 'DENY');
```

---

## Body Content

### Set Body
```php
$response = $response->withBody($streamInterface);
```

### Get Body
```php
$body = $response->getBody();
$content = (string) $body;  // Read as string
```

---

## Content Types

### JSON
```php
$response = Response::json(['id' => 42, 'name' => 'John']);
// Content-Type: application/json
// Body: {"id":42,"name":"John"}

// With status
$response = Response::json(['error' => 'Not found'], 404);

// Pretty print (dev)
$response = Response::json($data, 200, JSON_PRETTY_PRINT);
```

### Plain Text
```php
$response = Response::plaintext('Hello World');
// Content-Type: text/plain; charset=utf-8
```

### HTML
```php
$html = '<html><body><h1>Hello</h1></body></html>';
$response = Response::html($html);
// Content-Type: text/html; charset=utf-8
```

### XML
```php
$xml = '<?xml version="1.0"?><root><item>value</item></root>';
$response = Response::xml($xml);
// Content-Type: application/xml
```

### Auto (Content Negotiation)
```php
// Responds based on Accept header
$data = ['items' => [1, 2, 3]];
$response = Response::auto($request, $data);

// Accept: application/json → JSON
// Accept: application/xml → XML
// Accept: text/html → HTML (if supported)
```

---

## Response Helpers

### Empty Response
```php
$response = Response::noContent();
// 204 No Content
// Empty body
```

### Created
```php
$resource = ['id' => 42, 'name' => 'New User'];
$response = Response::created($resource, '/users/42');
// 201 Created
// Location: /users/42
// Body: {"id":42,"name":"New User"}
```

### Accepted
```php
$response = Response::accepted(['job_id' => 'abc123']);
// 202 Accepted
```

---

## Redirects

### Simple Redirect
```php
$response = Response::redirect('/login');
// 302 Found
// Location: /login
```

### Permanent Redirect
```php
$response = Response::redirect('/new-url', 301);
// 301 Moved Permanently
```

### Redirect with Status
```php
// 303 See Other
$response = Response::redirect('/success', 303);

// 307 Temporary Redirect (preserves method)
$response = Response::redirect('/retry', 307);

// 308 Permanent Redirect (preserves method)
$response = Response::redirect('/moved', 308);
```

### Named Route Redirect
```php
// Using route name
$response = Response::redirectToRoute('users.show', ['id' => 42]);
// Resolves to: /users/42
```

---

## File Downloads

### Download File
```php
// Prompt download
$response = Response::download('/path/to/document.pdf');
// Content-Disposition: attachment; filename="document.pdf"
// Content-Type: application/pdf

// Custom filename
$response = Response::download('/path/to/file.pdf', 'report-2024.pdf');
```

### Inline Display
```php
// Display in browser (not download)
$response = Response::file('/path/to/image.jpg');
// Content-Disposition: inline; filename="image.jpg"
// Content-Type: image/jpeg
```

### Stream File
```php
// For large files (memory efficient)
$response = Response::streamDownload(function() {
    $handle = fopen('/path/to/large-file.zip', 'r');
    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);
}, 'archive.zip');
```

---

## Streaming

### Stream Response
```php
$response = Response::stream(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "data: Event {$i}\n\n";
        flush();
        sleep(1);
    }
});
// Transfer-Encoding: chunked
```

### Server-Sent Events (SSE)
```php
$response = Response::stream(function() {
    while (true) {
        $data = ['time' => time(), 'value' => rand(1, 100)];
        echo "data: " . json_encode($data) . "\n\n";
        flush();
        sleep(2);

        if (connection_aborted()) {
            break;
        }
    }
})
->withHeader('Content-Type', 'text/event-stream')
->withHeader('Cache-Control', 'no-cache')
->withHeader('X-Accel-Buffering', 'no');  // Nginx
```

---

## Cookies

### Set Cookie
```php
$cookie = 'session=' . $sessionId
        . '; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=3600';

$response = $response->withAddedHeader('Set-Cookie', $cookie);
```

### Multiple Cookies
```php
$response = $response
    ->withAddedHeader('Set-Cookie', 'session=abc; Path=/; HttpOnly; Secure')
    ->withAddedHeader('Set-Cookie', 'theme=dark; Path=/; Max-Age=31536000');
```

### Delete Cookie
```php
$cookie = 'session=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
$response = $response->withAddedHeader('Set-Cookie', $cookie);
```

---

## PSR-7 Compatibility

### Immutability

All `with*` methods return new instance:
```php
$response1 = Response::json(['id' => 1]);
$response2 = $response1->withHeader('X-Custom', 'value');

// $response1 unchanged
// $response2 has the header
```

### Stream Interface
```php
$body = $response->getBody();

// PSR-7 StreamInterface methods
$body->getSize();
$body->tell();
$body->eof();
$body->isSeekable();
$body->seek(0);
$body->rewind();
$body->isWritable();
$body->write('data');
$body->isReadable();
$body->read(1024);
$body->getContents();
```

---

## Common Patterns

### API Success Response
```php
return Response::json([
    'success' => true,
    'data' => $resource,
    'meta' => ['total' => 100]
], 200);
```

### API Error Response
```php
return Response::json([
    'error' => [
        'code' => 'VALIDATION_ERROR',
        'message' => 'Invalid input data',
        'details' => $errors
    ]
], 422);
```

### Conditional Response

```php
// Based on content negotiation
$accept = $request->getHeaderLine('Accept');

if (str_contains($accept, 'application/json')) {
    return Response::json($data);
} elseif (str_contains($accept, 'text/html')) {
    return Response::html($this->render('template', $data));
} else {
    return Response::plaintext(print_r($data, true));
}
```

### Paginated Response

```php
return Response::json([
    'data' => $items,
    'pagination' => [
        'total' => 1000,
        'per_page' => 20,
        'current_page' => 5,
        'last_page' => 50,
        'next_url' => '/api/items?page=6',
        'prev_url' => '/api/items?page=4'
    ]
]);
```

### CORS Response

```php
return Response::json($data)
    ->withHeader('Access-Control-Allow-Origin', 'https://app.example.com')
    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE')
    ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
    ->withHeader('Access-Control-Max-Age', '3600');
```

### Cache Headers

```php
// Public, cacheable for 1 hour
return Response::json($data)
    ->withHeader('Cache-Control', 'public, max-age=3600')
    ->withHeader('Vary', 'Accept, Accept-Language');

// Private, no cache
return Response::json($privateData)
    ->withHeader('Cache-Control', 'private, no-store, must-revalidate');

// Conditional caching with ETag
$etag = md5(json_encode($data));
return Response::json($data)
    ->withHeader('ETag', "\"{$etag}\"")
    ->withHeader('Cache-Control', 'max-age=3600');
```

### Security Headers

```php
return Response::html($html)
    ->withHeader('X-Content-Type-Options', 'nosniff')
    ->withHeader('X-Frame-Options', 'DENY')
    ->withHeader('X-XSS-Protection', '1; mode=block')
    ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
    ->withHeader('Content-Security-Policy', "default-src 'self'");
```

### Long Polling

```php
return Response::stream(function() use ($queue) {
    $timeout = 30;
    $start = time();

    while ((time() - $start) < $timeout) {
        $message = $queue->pop();

        if ($message) {
            echo json_encode($message);
            break;
        }

        usleep(100000);  // 100ms
    }

    if (empty($message)) {
        echo json_encode(['timeout' => true]);
    }
})->withHeader('Content-Type', 'application/json');
```

---

## Method Summary

### Factory Methods
- `create(string $content = '', int $status = 200, array $headers = []): Response`
- `fromBody(resource $body, int $status = 200, array $headers = []): Response`
- `json(mixed $data, int $status = 200, int $options = 0): Response`
- `plaintext(string $content, int $status = 200): Response`
- `html(string $content, int $status = 200): Response`
- `xml(string $content, int $status = 200): Response`
- `auto(Request $request, mixed $data, int $status = 200): Response`
- `noContent(): Response`
- `created(mixed $data, ?string $location = null): Response`
- `accepted(mixed $data = null): Response`
- `redirect(string $url, int $status = 302): Response`
- `redirectToRoute(string $name, array $params = [], int $status = 302): Response`
- `download(string $path, ?string $name = null, array $headers = []): Response`
- `file(string $path, ?string $name = null, array $headers = []): Response`
- `stream(callable $callback, int $status = 200, array $headers = []): Response`
- `streamDownload(callable $callback, string $name, array $headers = []): Response`

### Status
- `getStatusCode(): int`
- `getReasonPhrase(): string`
- `withStatus(int $code, string $reasonPhrase = ''): static`

### Headers
- `getHeaders(): array`
- `hasHeader(string $name): bool`
- `getHeader(string $name): array`
- `getHeaderLine(string $name): string`
- `withHeader(string $name, $value): static`
- `withAddedHeader(string $name, $value): static`
- `withoutHeader(string $name): static`

### Body
- `getBody(): StreamInterface`
- `withBody(StreamInterface $body): static`

### Protocol
- `getProtocolVersion(): string`
- `withProtocolVersion(string $version): static`
