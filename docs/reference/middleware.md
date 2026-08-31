# Middleware API Reference

Webrick middleware is callable request/response policy. Development accepts ergonomic descriptors; compiled production prepares and validates the supported graph before traffic.

## Contract

```php
function (Request $request, Closure $next): Response
```

A middleware may return a `Response` to short-circuit normal application flow. Framework-owned rejection paths should prefer a typed `HttpExceptionInterface` so the kernel's single error boundary renders the final HTTP response.

## Development registration

The host owns InterMix and supplies the `Invoker`:

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: GeneratedMatcher::make(),
    register: $register,
    invoker: $applicationInvoker,
    preGlobal: [
        GatewayHardeningMiddleware::class,
        RequestLimitsMiddleware::class,
        NegotiationMiddleware::class,
    ],
    postGlobal: [
        CompressionMiddleware::class,
        CorsAndPoliciesMiddleware::class,
        VaryAccumulatorMiddleware::class,
    ],
);
```

`RouterKernel` does not accept a container fallback or service-provider list. Register application services/providers on the application-owned InterMix builder before constructing its development `Container`/`Invoker`.

## Per-route middleware

```php
Route::get('/private', [PrivateController::class, 'show'], [
    'middleware' => ['auth', 'throttle:30,60'],
]);
```

Groups can add middleware too:

```php
Route::group(middleware: ['auth'], callback: static function (): void {
    Route::get('/profile', [ProfileController::class, 'show']);
});
```

## Aliases

Register direct aliases during bootstrap:

```php
MiddlewareAliases::register('auth', static fn() => new AuthMiddleware());
MiddlewareAliases::register(
    'throttle',
    static fn(...$parameters) => new ThrottleMiddleware(
        (int) ($parameters[0] ?? 60),
        (int) ($parameters[1] ?? 60),
    ),
);
```

A framework can expose a lazy alias family:

```php
MiddlewareAliases::registerResolver(
    supports: static fn(string $alias): bool => in_array($alias, ['auth', 'tenant'], true),
    resolve: static fn(string $alias, string ...$parameters) =>
        $hostBridge->resolve($alias, $parameters),
    name: 'host.middleware',
);
```

Freeze/prepare alias state before serving persistent workers. `reset()` is for isolated development/test reconfiguration, not per-request use.

## Tagged global middleware

Development can append application-owned InterMix tags through `preGlobalTags` / `postGlobalTags`. Tagged direct factories remain lazy and resolve inside the request scope.

```php
$builder->bindFactory(
    AuthMiddleware::class,
    static fn(Container $container): AuthMiddleware => new AuthMiddleware(
        $container->get(AuthService::class),
    ),
    LifetimeEnum::Scoped,
    ['webrick.middleware.pre'],
);
```

The host owns that definition. Webrick only consumes the selected InterMix runtime.

## Compiled production

`RouteCompiler` converts route/global descriptors into execution-plan metadata. `CompiledRouterKernel` / `RuntimeDispatcher` then provide:

- validated alias/descriptors before traffic;
- prepared invocation modes;
- route pipelines built at worker/process boot;
- direct zero-pipeline dispatch when no middleware applies;
- no `is_callable()`/descriptor-shape discovery on the compiled request path;
- InterMix scopes only when a compiled handler/pipeline requires scoped or injected context.

Production should not rebuild or mutate the application graph during traffic.

## Request-local state

Reusable middleware must not keep current-request state in object/static properties. Keep these request-local:

- request/trace IDs;
- native transport handles;
- route parameters;
- decoded cookie values;
- `Vary` state;
- EndUser/IP state;
- request-scoped DI services.

`VaryAccumulatorMiddleware` uses a single request-local context rather than cloning the request for each token.

## Runtime-owned capabilities

Persistent runtime adapters expose capabilities so portable middleware can avoid duplicate work. If a transport owns response compression or request-size enforcement, the corresponding portable middleware bypasses that work.

## Recommended policy

- `GatewayHardeningMiddleware` — early trusted-request checks.
- `RequestLimitsMiddleware` — portable fallback only when transport limits are absent.
- `ThrottleMiddleware` — production requires an atomic counter backend; non-atomic cache fallback is development/approximate behavior.
- `MaintenanceModeMiddleware` — explicit/cached state, not per-request filesystem polling.
- `NegotiationMiddleware` — canonical media/charset/locale selection.
- `ResponseCacheMiddleware` / `CacheValidatorsMiddleware` — cache-policy/conditional handling on native representations.
- `CompressionMiddleware` — native string bodies only when transport compression is absent.
- `CookieEncryptionMiddleware` — optional and worker-safe.
- `InputSanitizerMiddleware` — explicit transformation only; not blanket security.
- `ResponseLinterMiddleware` — development/test only.

There is no `NormalizeMethodMiddleware` in Webrick 5. HTTP method normalization is performed once at request/runtime boundaries.

## Error handling

```php
final class AuthMiddleware
{
    public function __invoke(Request $request, Closure $next): Response
    {
        if (!$this->authorized($request)) {
            throw HttpException::forbidden('Forbidden');
        }

        return $next($request);
    }
}
```

The final error boundary recognizes `HttpExceptionInterface` plus explicit exception maps; it does not infer status codes from arbitrary exception properties/methods.

See the individual middleware guides for configuration details.
