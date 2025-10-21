# Streaming Responses

Stream data to clients without buffering whole payloads in memory. Great for logs, progress updates, server-sent events, long CSVs, or large file transformations. Webrick’s `Response::stream()` hands you a generator interface that yields chunks efficiently.

---

## Basics: yield chunks

```php
use Infocyph\Webrick\Response\Response;

Route::get('/stream', function () {
    return Response::stream(function () {
        for ($i = 1; $i <= 5; $i++) {
            yield "chunk: {$i}\n";
            usleep(150_000); // simulate work
        }
        return ''; // optional trailer
    });
});
```php

**Test it**

```bash
curl -N http://127.0.0.1:8000/stream
```php

> The `-N` flag tells `curl` not to buffer.

---

## Binary/data streams

Write bytes directly—useful for NDJSON, CSV, or custom binary protocols.

```php
Route::get('/numbers.ndjson', function () {
    return Response::stream(function () {
        for ($i = 1; $i <= 3; $i++) {
            yield json_encode(['n' => $i]) . "\n";
        }
    })->withHeader('Content-Type', 'application/x-ndjson; charset=utf-8');
});
```php

---

## Server-Sent Events (SSE)

Keep a long-lived HTTP connection and push events.

```php
Route::get('/events', function () {
    return Response::stream(function () {
        for ($i = 1; $i <= 5; $i++) {
            yield "event: tick\n";
            yield "data: " . json_encode(['i'=>$i, 't'=>time()]) . "\n\n";
            usleep(1000_000);
        }
    })->withHeader('Content-Type', 'text/event-stream')
      ->withHeader('Cache-Control', 'no-cache')
      ->withHeader('Connection', 'keep-alive');
});
```php

Client (browser):

```js
const es = new EventSource('/events');
es.addEventListener('tick', e => console.log('tick', JSON.parse(e.data)));
```php

---

## Streaming large files (transforming on the fly)

When you need to **transform** or **throttle** a big file:

```php
Route::get('/download/report', function () {
    $path = __DIR__ . '/../storage/big-report.csv';

    return Response::stream(function () use ($path) {
        $h = fopen($path, 'rb');
        if (!$h) {
            yield "error: cannot open file\n";
            return '';
        }
        while (!feof($h)) {
            yield fread($h, 64 * 1024); // 64KB chunks
        }
        fclose($h);
    })->withHeader('Content-Type', 'text/csv; charset=UTF-8')
      ->withHeader('Content-Disposition', 'attachment; filename="report.csv"');
});
```php

> If you’re not transforming, `Response::attachment($path, 'name.csv')` already streams efficiently—prefer that helper.

---

## Compression & streaming

* **Compression middleware** may skip or adjust behavior for streaming responses (many compressors need full buffers).
* For SSE or already-compressed content, compression is often disabled by design.
* If you need compression for predictable chunking, consider pre-compressing artifacts and serving them via `attachment()` with the correct `Content-Encoding`.

---

## Caching & validators

* Streaming responses typically **don’t** set `ETag` automatically (content length unknown upfront).
* If your stream is deterministic and cacheable, consider computing a known hash/file size and using `Response::attachment()` or a precomputed body.

---

## Web server considerations (important)

To avoid unwanted buffering:

* **Nginx**: disable proxy buffering for your upstream location (if proxying), e.g.
  `proxy_buffering off;`
* **Apache**: avoid output filters that buffer; use `SetEnv no-gzip 1` for SSE if needed.
* **CDNs / proxies**: some buffer small responses—test with and without CDN.
* **Timeouts**: extend upstream timeouts for long streams (both server and client).

---

## Backpressure & pace

Yield in **bounded** chunks (e.g., 8–64KB) and sleep only as needed. For I/O-bound producers (DB, network), chunk sizes of 16–32KB are usually a good balance.

---

## Error handling & early termination

Wrap your generator body with try/finally if you manage resources:

```php
return Response::stream(function () {
    $h = fopen('php://temp', 'wb+');
    try {
        // ... yield chunks ...
    } finally {
        if ($h) fclose($h);
    }
});
```php

If an exception is thrown mid-stream, the connection will close; ensure upstream logs capture the error.

---

## Progress patterns

Emit **structured** progress for clients to parse:

```php
yield json_encode(['stage'=>'prepare']) . "\n";
yield json_encode(['stage'=>'processing','pct'=>42]) . "\n";
yield json_encode(['stage'=>'done']) . "\n";
```php

For browsers, use SSE; for CLIs, newline-delimited JSON.

---

## Checklist

* [ ] Use `Response::stream()` for long or dynamic outputs
* [ ] Pick a sensible chunk size (8–64KB)
* [ ] Disable proxy/web-server buffering for real-time streams
* [ ] Don’t rely on compression for SSE/real-time unless pre-compressed
* [ ] Prefer `attachment()` when serving static large files
* [ ] Manage resources with try/finally in generators
