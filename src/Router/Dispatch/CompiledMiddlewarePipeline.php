<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use UnexpectedValueException;

/** Production middleware pipeline built once from compiled descriptors. */
final readonly class CompiledMiddlewarePipeline
{
    /** @var Closure(Request):Response */
    private Closure $pipeline;

    /** @param list<mixed> $middleware @param Closure(Request):Response $terminal */
    public function __construct(array $middleware, Closure $terminal, InterMixRuntime $runtime)
    {
        $next = $terminal;
        foreach (array_reverse($middleware) as $descriptor) {
            $following = $next;
            $next = static function (Request $request) use ($descriptor, $following, $runtime): Response {
                $result = self::invokeMiddleware($runtime, $descriptor, $request, $following);
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

    private static function invokeMiddleware(
        InterMixRuntime $runtime,
        mixed $descriptor,
        Request $request,
        Closure $next,
    ): mixed {
        if (!is_string($descriptor) && is_callable($descriptor)) {
            return $descriptor($request, $next);
        }
        if (!is_string($descriptor) && !is_array($descriptor)) {
            throw new UnexpectedValueException('Compiled middleware descriptor is not invokable.');
        }

        return $runtime->resolveNow($descriptor, ['request' => $request, 'next' => $next]);
    }
}
