<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\InterMixRuntime;

/**
 * @internal Runtime-only tag binding; resolved middleware stays in its request scope.
 */
final readonly class RuntimeTaggedMiddleware
{
    public function __construct(private string $tag, private InterMixRuntime $runtime) {}

    /**
     * @param Closure(Request):mixed $next
     * @throws \UnexpectedValueException
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        $middleware = [];
        foreach ($this->runtime->tagged($this->tag) as $entry) {
            $middleware[] = $entry;
        }

        return new CompiledMiddlewarePipeline($middleware, $next, $this->runtime)->handle($request);
    }
}
