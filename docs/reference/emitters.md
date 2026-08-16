# Response Emitters

An emitter transfers a Webrick `Response` to the active PHP or server runtime.
Emission is a boundary concern: standalone applications use an emitter, while
applications embedded in another framework normally return an adapted response
to that framework.

## Available emitters

| Emitter | Runtime | Required request attribute |
| --- | --- | --- |
| `DefaultEmitter` | PHP-FPM, FrankenPHP, LiteSpeed, Nginx Unit, generic SAPI | None |
| `CliEmitter` | CLI and phpdbg | None |
| `SwooleEmitter` | Swoole HTTP server | `swoole.response` |
| `RoadRunnerEmitter` | RoadRunner bridge | `roadrunner.respond` |
| `WorkermanEmitter` | Workerman | `workerman.response` or `workerman.connection` |
| `AutoEmitter` | Selects and memoizes one of the above | Depends on selected emitter |

## Automatic selection

```php
use Infocyph\Webrick\Response\Emitter\AutoEmitter;

$emitter = new AutoEmitter();
$emitter->emit($response, $request);
```

`AutoEmitter` selects once and reuses that emitter for later calls. This avoids
repeating environment detection in a persistent worker. Create a separate
`AutoEmitter` if one process intentionally serves requests through different
runtime transports.

Known synchronous SAPIs (`apache2handler` and PHP's `cli-server`) select the
default emitter before Webrick probes optional asynchronous runtimes. This
keeps extension and bridge detection out of their normal response path.

Pass a non-empty third argument to select an emitter explicitly for that call:

```php
$emitter->emit($response, $request, 'swoole');
```

The default third argument is `''`, which uses automatic detection. Explicit
selections apply only to that call and do not replace the automatic selection.

| Value | Selected behavior |
| --- | --- |
| `swoole` | `SwooleEmitter` |
| `roadrunner` | `RoadRunnerEmitter` |
| `workerman` | `WorkermanEmitter` |
| `frankenphp` | `DefaultEmitter` with FrankenPHP finishing |
| `lsapi` | `DefaultEmitter` with LiteSpeed finishing |
| `unit` | `DefaultEmitter` with FastCGI finishing |
| `fpm` | `DefaultEmitter` with FastCGI finishing |
| `cli` | `CliEmitter` |
| `default` | Generic `DefaultEmitter` |

Unknown non-empty values also fall back to generic `DefaultEmitter`.

## Swoole

Attach the native response before calling the kernel:

```php
$request = $request->withAttribute('swoole.response', $swooleResponse);
$response = $kernel->handle($request);

(new SwooleEmitter())->emit($response, $request);
```

The attribute must be an instance of `Swoole\Http\Response`. Swoole owns HTTP
framing, so Webrick does not emit a transfer-encoding header.

## RoadRunner

The RoadRunner integration uses an application-provided callable:

```php
$request = $request->withAttribute(
    'roadrunner.respond',
    static function (int $status, array $headers, string|iterable $body) use ($worker): void {
        $worker->respond($status, $headers, $body);
    },
);

$response = $kernel->handle($request);
(new RoadRunnerEmitter())->emit($response, $request);
```

The callable signature is:

```php
function (int $status, array $headers, string|iterable $body): void
```

Webrick sends an empty body for `HEAD`, `204` and `304` responses.

## Workerman

Attach either:

- `workerman.response`: an object supporting `withStatus()`, `withHeader()` and
  `end()`, or
- `workerman.connection`: an object supporting `send()`.

```php
$request = $request->withAttribute('workerman.connection', $connection);
$response = $kernel->handle($request);

(new WorkermanEmitter())->emit($response, $request);
```

The connection fallback builds the HTTP envelope and adds `Content-Length` when
it is absent.

## Embedded frameworks

If Laravel, Symfony, Slim, or another host owns response emission, do not use
these emitters. Convert the Webrick response to the host response type and
return it:

```php
return $responseAdapter->fromWebrick($kernel->handle($webrickRequest));
```

This prevents duplicate headers, duplicate bodies and premature FastCGI or
worker completion.

## Operational rules

- Reuse an emitter for a stable runtime.
- Pass the same request used by the kernel when a runtime emitter needs request
  attributes.
- Do not share a native response object between requests.
- Let only one layer perform compression and final emission.
- Test streaming and `HEAD`, `204`, `304`, file and error responses in the
  actual target runtime.
