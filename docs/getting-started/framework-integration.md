# Framework Integration

Webrick can run as the application's HTTP kernel or as a routed sub-application
inside Laravel, Symfony, Slim, a custom framework, or a persistent worker. The
integration boundary is explicit so the host keeps control of its own request,
response, middleware and container lifecycle.

## Integration contract

An embedding integration has four jobs:

1. Construct one Webrick `RouterKernel` for the application or worker lifecycle.
2. Convert the host request into `Infocyph\Webrick\Request\Request`.
3. Pass that request to `RouterKernel::handle()`.
4. Convert the returned Webrick `Response` to the host response type.

```php
final class WebrickBridge
{
    public function __construct(
        private RouterKernel $kernel,
        private HostRequestAdapter $requests,
        private HostResponseAdapter $responses,
    ) {}

    public function handle(object $hostRequest): object
    {
        $request = $this->requests->toWebrick($hostRequest);
        $response = $this->kernel->handle($request);

        return $this->responses->fromWebrick($response);
    }
}
```

`HostRequestAdapter` and `HostResponseAdapter` represent application-owned
adapters in this example; they are not Webrick classes. The adapter should
preserve the method, URI, headers, protocol version, body, cookies, query
parameters, uploaded files, server parameters and any runtime response handle
needed by the selected server.

Webrick's request and response classes expose PSR-7-style immutable methods, but
they do not implement the PSR-7 interfaces. Do not rely on interface type
compatibility. Conversion must be explicit.

## Boot once

Kernel construction warms routes, imports service providers, composes global
middleware descriptors and prepares the error boundary. It belongs in
application bootstrap, a service-provider singleton, or worker startup—not in
the per-request adapter:

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        require __DIR__ . '/routes.php';
    },
    routeCache: __DIR__ . '/cache/routes',
    registrarOptions: [
        'signKey' => $config->signedUrlKey,
        'signedDefaultTtl' => 900,
        'urlBaseUri' => $config->applicationUrl,
    ],
);
```

When a valid cache exists, Webrick boots the matcher from the artifact. URL
alias data remains lazy until a URL helper is called.

## Decide which middleware layer owns a concern

Use the host framework's middleware for concerns that must surround every host
request, including requests that never enter Webrick. Use Webrick route
middleware for concerns that apply only to selected Webrick routes.

Examples:

| Concern | Recommended owner |
| --- | --- |
| Host-wide session or tenant setup | Host middleware |
| Authentication only on selected Webrick routes | Webrick route middleware |
| Host request ID needed by every subsystem | Host middleware, copied by the adapter |
| Webrick signed URL verification | Webrick route middleware |
| Compression already performed by the host/server | Host/server; omit Webrick compression |

Avoid registering authentication, database, cache, telemetry, or session
middleware globally merely because the host package provides it. Route-only
middleware stays off unrelated request paths.

## Bridge a host middleware family lazily

When a framework owns aliases such as `auth`, `auth:admin`, or `tenant:paid`,
register one lazy family resolver during application bootstrap:

```php
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

MiddlewareAliases::registerResolver(
    supports: static fn(string $alias): bool => in_array(
        $alias,
        ['auth', 'tenant'],
        true,
    ),
    resolve: static fn(string $alias, string ...$parameters) =>
        $hostMiddlewareBridge->resolve($alias, $parameters),
    name: 'host.middleware',
);
```

The resolver is consulted only for a matching alias. The resolved middleware is
then used when Webrick compiles that route's pipeline. A stable resolver name
allows worker bootstrap to replace the integration without accumulating
duplicate resolvers.

## Container integration

Webrick dispatches through InterMix. Pass an existing InterMix container or
invoker to share registrations:

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: FusedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/cache/routes.php',
    container: $container,
    serviceProviders: [
        RoutingServiceProvider::class,
    ],
    preGlobalTags: ['webrick.middleware.pre'],
    postGlobalTags: ['webrick.middleware.post'],
);
```

For a non-InterMix host container, register small factories or adapter services
in InterMix at bootstrap. Do not copy the host's entire service graph or eagerly
resolve optional services.

Controller behavior:

- `[Controller::class, 'staticMethod']` is called without allocating a controller.
- `[Controller::class, 'instanceMethod']` is constructed through InterMix.
- Invokable classes and class-string middleware are resolved only when their
  route pipeline is needed.

## Request-scope ownership

The default `requestScopeEnabled: true` creates and leaves one InterMix scope
around each `handle()` call. This is the safe default, including for RoadRunner,
Swoole and Workerman.

Use `requestScopeEnabled: false` only when the embedding integration has an
equivalent scope lifecycle and deliberately owns cleanup. Disabling Webrick's
scope without a host replacement can leak request-bound state across requests.

## Response ownership

Choose one response owner:

- Standalone Webrick: emit the returned response with a Webrick emitter.
- Embedded Webrick: convert and return it to the host; the host emits it.
- Native persistent server integration: attach the native response callback or
  object to the request, then use the matching Webrick emitter.

Never emit in Webrick and then return the same response to a host framework for
a second emission.

## Cache and deployment

The route definitions used by the cache build must match runtime registration.
Build the selected artifact in CI or deployment:

```bash
php ./webrick route:cache \
  --matcher=sharded \
  --cache=var/cache/webrick \
  --routes=routes/webrick.php
```

Deploy the generated PHP artifacts with application code and keep them
read-only during normal requests. Rebuild after changing Webrick, routes,
handler classes, middleware descriptors, signing configuration, or cache mode.

See [Matcher](../reference/matcher.md), [Route Cache](../reference/route-cache.md),
and [Response Emitters](../reference/emitters.md).
