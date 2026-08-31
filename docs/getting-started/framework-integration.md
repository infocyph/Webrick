# Framework Integration

Webrick can run as the application's HTTP kernel or as a routed sub-application inside Foundation/Infbyte, Laravel, Symfony, Slim, a custom framework, or a persistent worker. The host owns container composition, configuration, request adaptation and final response emission.

## Integration contract

An embedding integration has four responsibilities:

1. Own and configure the application's InterMix `ContainerBuilder`.
2. Compile/boot the appropriate Webrick kernel once for the process or worker.
3. Convert the host request to a Webrick `Request` when the native Webrick runtime adapter is not used.
4. Convert/return the Webrick `Response` when the host owns emission.

```php
final class WebrickBridge
{
    public function __construct(
        private CompiledRouterKernel $kernel,
        private HostRequestAdapter $requests,
        private HostResponseAdapter $responses,
    ) {}

    public function handle(object $hostRequest): object
    {
        $request = $this->requests->toWebrick($hostRequest);

        return $this->responses->fromWebrick($this->kernel->handle($request));
    }
}
```

The example adapters are host classes, not Webrick classes.

## One application graph

Webrick depends directly on InterMix but does not own a second graph. Contribute Webrick/application providers to the host builder before selecting development or production runtime:

```php
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Webrick;

$builder = ContainerBuilder::create('application');
Webrick::contributeTo($builder, $webrickProviders);
// Host registrations continue on the same $builder.
```

Do not make Webrick import providers during kernel construction. Do not use a process-global container alias as an implicit integration boundary.

## Development boot

Development/registrar mode receives an explicit application `Invoker`:

```php
use Infocyph\InterMix\DI\Invoker;

$container = $builder->development();
$invoker = Invoker::with($container);

$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: GeneratedMatcher::make(),
    register: $register,
    invoker: $invoker,
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
);
```

`RouterKernel` always creates a request scope and seeds the active `Request` into that scope. There is no option to bind the current request as a singleton.

## Production boot

Build Webrick and InterMix as one release set with `ReleaseCompiler`. At worker/process startup the host selects its InterMix `ProductionContainer` and supplies it to `CompiledRouterKernel`:

```php
$container = $builder->productionPrevalidated(
    $release['intermix']['path'],
    $release['intermix']['sha256'],
);

$kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
    log: $logger,
    matcher: GeneratedMatcher::make(),
    container: $container,
    artifactPath: $release['webrick']['path'],
    trustedSha256: $release['webrick']['sha256'],
    environment: $release['environment'],
    configFingerprint: $release['config_fingerprint'],
);
```

The environment/fingerprint values validate the artifact. They do not make Webrick choose a runtime.

## Middleware ownership

Use host middleware for concerns that surround every host request. Use Webrick route middleware for concerns that apply to Webrick routes.

| Concern | Recommended owner |
| --- | --- |
| Host session/tenant bootstrap | Host |
| Authentication on selected Webrick routes | Webrick route middleware |
| Host-wide request ID | Host, copied/adapted into Webrick context |
| Signed URL verification | Webrick |
| Transport compression already supplied by server | Server/runtime adapter |
| Webrick response negotiation/cache policy | Webrick |

Avoid globally enabling optional middleware merely because a package exists.

## Lazy host middleware aliases

A host can expose alias families during bootstrap:

```php
MiddlewareAliases::registerResolver(
    supports: static fn(string $alias): bool => in_array($alias, ['auth', 'tenant'], true),
    resolve: static fn(string $alias, string ...$parameters) =>
        $hostMiddlewareBridge->resolve($alias, $parameters),
    name: 'host.middleware',
);
```

Compiled production validates the supported descriptors before traffic. Dynamic host integrations should remain explicit dynamic islands rather than silently falling back to a second container/runtime.

## Persistent runtimes

Swoole/OpenSwoole, RoadRunner and Workerman use the classes under `Runtime\Http`. The runtime adapter is chosen once at worker bootstrap and owns native request/response handles and transport capabilities.

Webrick opens an InterMix request scope only when the compiled execution plan needs one. Native request/response state remains request-local and must never be kept in singleton middleware or static current-request/current-response fields.

## Response ownership

Choose exactly one owner:

- synchronous standalone SAPI: Webrick `DefaultEmitter`;
- CLI: `CliEmitter`;
- persistent server: Webrick runtime adapter;
- embedded framework: host response adapter/emitter.

Never emit a response in Webrick and then hand the same response to a host for a second emission.

## Matcher cache vs production artifact

`RouteCache::build()` / `webrick route:cache` builds matcher cache only. It does not boot a kernel or DI runtime. The complete production application artifact is created by `ReleaseCompiler` and includes the compiled route execution plans/global middleware metadata coordinated with InterMix.

See [Matcher](../reference/matcher.md), [Route Cache](../reference/route-cache.md), and [Response Emitters and Runtime Adapters](../reference/emitters.md).
