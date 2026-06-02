# Response API Reference

Reference for `Infocyph\Webrick\Response\Response`.

## Core factories

### `Response::create(...)`

```php
use Infocyph\Webrick\Response\Response;

$response = Response::create('Hello World', 200, [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
```

### `Response::json(...)`

```php
$response = Response::json(['id' => 42, 'name' => 'Jane']);
$response = Response::json(['error' => 'Not found'], 404);
```

### `Response::plaintext(...)`

```php
$response = Response::plaintext('Hello', 200);
```

### `Response::auto(...)`

Chooses a response form from the request's negotiation state.

```php
$response = Response::auto($request, ['items' => [1, 2, 3]]);
```

### `Response::noContent(...)`

```php
$response = Response::noContent();
```

## Redirects

```php
$response = Response::redirect('/login');
$response = Response::redirect('/moved', 301);
$response = Response::redirect(Route::urlFor('users.show', ['id' => 42]));
```

## Files and downloads

### Attachment download

```php
$response = Response::attachment('/path/to/report.pdf', 'report.pdf');
```

### Inline file display

```php
$response = Response::inline('/path/to/image.jpg');
$response = Response::inline('/path/to/manual.pdf', 'manual.pdf');
```

### Download alias

```php
$response = Response::download('/path/to/report.pdf');
$response = Response::download('/path/to/report.pdf', 'report-2024.pdf');
```

### Ranged file responses

Use these when you want `Range` request support.

```php
$response = Response::rangedFile($request, '/path/to/video.mp4');
$response = Response::rangedDownload($request, '/path/to/archive.zip', 'archive.zip');
```

### Streamed download

```php
$response = Response::streamDownload('/path/to/large-file.zip', 'large-file.zip');
```

## Streaming

### General streaming

```php
$response = Response::stream(function (): iterable {
    for ($i = 0; $i < 3; $i++) {
        yield "chunk {$i}\n";
    }
});
```

### SSE-style streaming

```php
$response = Response::stream(function (): iterable {
    for ($i = 0; $i < 3; $i++) {
        yield "data: event {$i}\n\n";
    }
}, headers: [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache',
]);
```

## Views

`Response::view(...)` renders through the configured `ViewFactoryInterface`.

```php
$response = Response::view('users/show', ['user' => $user]);
```

## Headers and status

```php
$response = $response
    ->withStatus(202)
    ->withHeader('X-Trace-Id', 'abc123')
    ->withAddedHeader('Set-Cookie', 'theme=dark; Path=/; HttpOnly');

$status = $response->getStatusCode();
$reason = $response->getReasonPhrase();
$headers = $response->getHeaders();
$contentType = $response->getHeaderLine('Content-Type');
```

## Cookies

`Response` itself works with normal header operations. For structured cookie handling, pair it with `Cookie` and `CookieJar`.

```php
$response = $response->withAddedHeader(
    'Set-Cookie',
    'session=abc123; Path=/; HttpOnly; SameSite=Lax',
);
```

## Constructing from a stream

```php
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

$stream = new Stream(fopen('php://temp', 'r+'));
$stream->write('streamed body');
$stream->rewind();

$response = new Response(200, $stream, ['Content-Type' => 'text/plain']);
```

## Current helper summary

- `create(string $content = '', int $status = 200, array $headers = []): Response`
- `json(mixed $data, int $status = 200, int $flags = ... , int $depth = 512): Response`
- `plaintext(string $msg, int $code = 400, array $headers = []): Response`
- `auto(Request $request, mixed $data, int $status = 200, array $headers = [], int $flags = ..., int $depth = 512): Response`
- `noContent(array $headers = []): Response`
- `redirect(string $uri, int $status = 302): Response`
- `attachment(string|Stream $file, string $name, ?string $mime = null, array $headers = []): Response`
- `inline(string|Stream $file, ?string $name = null, ?string $mime = null, array $headers = []): Response`
- `download(string|Stream $file, ?string $name = null, array $headers = [], ?string $mime = null): Response`
- `rangedFile(Request $request, string $file, array $headers = [], ?string $mime = null): Response`
- `rangedDownload(Request $request, string $file, ?string $name = null, array $headers = [], ?string $mime = null): Response`
- `stream(callable|iterable $producer, int $status = 200, array $headers = []): Response`
- `streamDownload(string|Stream $file, ?string $name = null, string $mime = 'application/octet-stream', array $headers = []): Response`
- `view(string $template, array $data = [], int $status = 200, array $headers = []): Response`
