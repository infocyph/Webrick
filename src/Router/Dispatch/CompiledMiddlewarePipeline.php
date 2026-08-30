<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use UnexpectedValueException;

/** Production middleware pipeline fully prepared once at worker/process boot. */
final readonly class CompiledMiddlewarePipeline
{
    /** @var Closure(Request):Response */
    private Closure $pipeline;

    /** @param list<mixed> $middleware @param Closure(Request):Response $terminal */
    public function __construct(array $middleware, Closure $terminal, InterMixRuntime $runtime)
    {
        $invokers = [];
        foreach ($middleware as $descriptor) {
            $invokers[] = self::compileInvoker($runtime, $descriptor);
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
    }

    public function handle(Request $request): Response
    {
        return ($this->pipeline)($request);
    }

    /** @return Closure(Request,Closure):mixed */
    private static function compileInvoker(InterMixRuntime $runtime, mixed $descriptor): Closure
    {
        if (is_callable($descriptor) && (!is_string($descriptor) || function_exists($descriptor))) {
            $callable = $descriptor;

            return static fn(Request $request, Closure $next): mixed => $callable($request, $next);
        }

        if (!is_string($descriptor) && !is_array($descriptor)) {
            throw new UnexpectedValueException('Compiled middleware descriptor is not invokable.');
        }

        return static fn(Request $request, Closure $next): mixed => $runtime->resolveNow(
            $descriptor,
            ['request' => $request, 'next' => $next],
        );
    }
}
