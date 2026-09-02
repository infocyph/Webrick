<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use UnexpectedValueException;

/** Production middleware pipeline fully prepared once at worker/process boot. */
final readonly class CompiledMiddlewarePipeline
{
    /** @var Closure(Request):Response */
    private Closure $pipeline;

    private bool $requiresScope;

    /**
     * @param list<mixed> $middleware @param Closure(Request):Response $terminal
     */
    public function __construct(array $middleware, Closure $terminal, InterMixRuntime $runtime)
    {
        $invokers = [];
        $requiresScope = false;
        foreach ($middleware as $descriptor) {
            [$invoke, $runtimeBacked] = self::compileInvoker($runtime, $descriptor);
            $invokers[] = $invoke;
            $requiresScope = $requiresScope || $runtimeBacked;
        }

        $next = $terminal;
        foreach (array_reverse($invokers) as $invoke) {
            $following = $next;
            $next = static function (Request $request) use ($invoke, $following): Response {
                $result = $invoke($request, $following);
                if (!$result instanceof Response) {
                    throw new UnexpectedValueException('Compiled middleware must return ' . Response::class . '.');
                }

                return $result;
            };
        }
        $this->pipeline = $next;
        $this->requiresScope = $requiresScope;
    }

    public function handle(Request $request): Response
    {
        $response = ($this->pipeline)($request);
        if (!$response instanceof Response) {
            throw new UnexpectedValueException('Compiled middleware pipeline must return ' . Response::class . '.');
        }

        return $response;
    }

    public function requiresScope(): bool
    {
        return $this->requiresScope;
    }

    /**
     * @return array{0:Closure(Request,Closure):mixed,1:bool}
     */
    private static function compileInvoker(InterMixRuntime $runtime, mixed $descriptor): array
    {
        if ($descriptor instanceof RuntimeMiddlewareDescriptor) {
            return [
                static function (Request $request, Closure $next) use ($descriptor, $runtime): mixed {
                    $middleware = $runtime->resolveNow($descriptor->resolver, $descriptor->parameters);

                    return self::invokeResolvedMiddleware($runtime, $middleware, $request, $next);
                },
                true,
            ];
        }

        if (is_callable($descriptor) && (!is_string($descriptor) || function_exists($descriptor))) {
            $callable = $descriptor;

            return [
                static fn(Request $request, Closure $next): mixed => $callable($request, $next),
                false,
            ];
        }

        if (!is_string($descriptor) && !is_array($descriptor)) {
            throw new UnexpectedValueException('Compiled middleware descriptor is not invokable.');
        }

        return [
            static fn(Request $request, Closure $next): mixed => $runtime->resolveNow(
                $descriptor,
                ['request' => $request, 'next' => $next],
            ),
            true,
        ];
    }

    private static function invokeResolvedMiddleware(
        InterMixRuntime $runtime,
        mixed $middleware,
        Request $request,
        Closure $next,
    ): mixed {
        $parameters = ['request' => $request, 'next' => $next];
        if (is_string($middleware) && class_exists($middleware) && method_exists($middleware, '__invoke')) {
            return $runtime->resolveNow([$middleware, '__invoke'], $parameters);
        }
        if (is_callable($middleware)) {
            return $runtime->resolveNow($middleware, $parameters);
        }

        throw new UnexpectedValueException(
            'Runtime middleware resolver returned unsupported type ' . get_debug_type($middleware) . '.',
        );
    }
}
