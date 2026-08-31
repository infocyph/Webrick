<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * MiddlewarePipeline
 *
 * Build and execute a middleware pipeline around a terminal handler.
 *
 * Responsibilities:
 *  - Validate and store a list of middleware callables.
 *  - Compose middleware into a single reusable Closure that accepts a Request
 *    and returns a Response, invoking middleware in the configured order.
 *  - Support two invocation modes:
 *      • direct call: call middleware/handler as callables with ($req, $next) / ($req)
 *      • DI invoker: let an Invoker perform parameter resolution/autowiring
 *        for middleware and the final handler (useInvoker=true).
 *  - Provide stable per-process memoization for certain resolved callables
 *    to avoid repeated binding/lookup overhead.
 *
 * Example:
 *     function(Request $req, callable $next): Response
 *
 * Notes:
 *  - The final handler (last) is a callable that accepts a Request and returns
 *    any value which will be normalized to Response (or an exception thrown).
 *
 * @phpstan-type Middleware   callable(Request, callable(Request):Response):Response
 * @phpstan-type FinalHandler callable(Request):mixed
 *
 * @psalm-immutable
 *
 * @author Infocyph
 */
final class MiddlewarePipeline
{
    private const int CALLABLE_MEMO_LIMIT = 128;

    /**
     * Composed pipeline closure that accepts a Request and returns a Response.
     *
     * Built once in the constructor for per-request reuse.
     *
     * @var Closure(Request):Response
     */
    private readonly Closure $pipeline;

    /**
     * Middleware stack in execution order (first executed -> first element).
     *
     * @var list<Middleware>
     */
    private readonly array $stack;

    /**
     * Final handler callable executed after all middleware.
     *
     * This callable conforms to FinalHandler and may return any value that will
     * be normalized into a Response by assertResponse().
     *
     * @var FinalHandler
     */
    private $lastHandler;

    /**
     * Construct the pipeline executor.
     *
     * Validates that each $stack element is callable and composes the pipeline
     * Closure once for reuse.
     *
     * @param list<mixed> $stack Ordered list of middleware callables.
     * @param FinalHandler $last Terminal handler callable executed after middleware.
     * @param Invoker $invoker DI invoker used when $useInvoker is enabled.
     * @param bool $useInvoker Whether middleware calls use the DI invoker.
     * @param bool $invokeFinalWithInvoker Whether the terminal handler uses the DI invoker.
     *
     * @throws InvalidArgumentException If any entry in $stack is not callable.
     */
    public function __construct(
        array $stack,
        callable $last, /**
     * DI invoker used to call middleware and handlers when $useInvoker is true.
     */
        private readonly Invoker $invoker, /**
     * Whether to dispatch middleware/handler via the Invoker (DI/autowiring).
     */
        private readonly bool $useInvoker = true,
        private readonly bool $invokeFinalWithInvoker = true,
    ) {
        foreach ($stack as $mw) {
            if (!\is_callable($mw)) {
                throw new InvalidArgumentException(
                    sprintf('Middleware [%s] is not callable', self::describe($mw)),
                );
            }
        }

        /** @var list<Middleware> $validated */
        $validated = $stack;
        $this->stack = $validated;
        $this->lastHandler = $last;
        $this->pipeline = $this->compose();
    }

    /**
     * Execute the composed pipeline for a given request.
     *
     * @param Request $req Incoming HTTP request provided to the pipeline.
     * @return Response Response produced by middleware/final handler.
     *
     * @throws UnexpectedValueException If the final handler or any middleware
     *                                  returns a non-Response value.
     */
    public function handle(Request $req): Response
    {
        return ($this->pipeline)($req);
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    /**
     * Ensure the returned value is a Response instance.
     *
     * Throws UnexpectedValueException when a middleware or handler returns a
     * non-Response value which indicates an implementation error upstream.
     *
     * @param mixed $res Value returned by middleware or final handler.
     * @param string $source Human-readable tag used in exception messages.
     * @return Response The validated Response instance.
     *
     * @throws UnexpectedValueException When $res is not an instance of Response.
     */
    private static function assertResponse(mixed $res, string $source): Response
    {
        if (!$res instanceof Response) {
            $type = get_debug_type($res);

            throw new UnexpectedValueException(
                sprintf('%s returned %s; expected %s', $source, $type, Response::class),
            );
        }

        return $res;
    }

    private static function callMiddleware(
        Invoker $invoker,
        bool $useInvoker,
        callable $mw,
        Request $req,
        Closure $next,
    ): mixed {
        if ($useInvoker) {
            return $invoker->invoke($mw, [
                'request' => $req,
                'next' => $next,
            ]);
        }

        return $mw($req, $next);
    }

    /**
     * Describe a middleware item for diagnostics.
     *
     * Produces a short string identifying the middleware by class name or type.
     *
     * @param mixed $mw Middleware descriptor (callable/object/array/string).
     * @return string Readable description for use in error messages.
     */
    private static function describe(mixed $mw): string
    {
        return \is_object($mw) ? $mw::class
            : (\is_array($mw) ? 'callable[]' : (string) \gettype($mw));
    }

    /* -------------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------------- */

    /**
     * Compose the middleware stack into a single Closure(Request):Response.
     *
     * The composition wraps the terminal handler, then wraps each middleware
     * around the previously composed "next" Closure in reverse order so that
     * the first element of $stack is executed first.
     *
     * @return Closure(Request):Response Composed pipeline closure.
     */
    private function compose(): Closure
    {
        // The "next" callable starts as the wrapped final handler.
        $next = $this->wrapFinal($this->lastHandler);

        // Wrap each middleware around the current "next" in reverse order to
        // preserve the specified execution order.
        foreach (\array_reverse($this->stack) as $mw) {
            $next = $this->wrap($mw, $next);
        }

        /** @var Closure(Request):Response $next */
        return $next;
    }

    private function resolveMiddlewareString(string $mw): callable
    {
        if (\str_contains($mw, '::')) {
            if (!\is_callable($mw)) {
                throw new InvalidArgumentException(\sprintf('Middleware [%s] is not callable', $mw));
            }

            return \Closure::fromCallable($mw);
        }

        if (!\function_exists($mw)) {
            throw new InvalidArgumentException(\sprintf('Middleware function [%s] not found', $mw));
        }

        return \Closure::fromCallable($mw);
    }

    private function resolveMiddlewareTarget(callable|string $mw): callable
    {
        /** @var array<string, callable> $memo */
        static $memo = [];

        if (!\is_string($mw)) {
            return $mw;
        }

        if (isset($memo[$mw])) {
            return $memo[$mw];
        }

        $resolved = $this->resolveMiddlewareString($mw);
        if (count($memo) < self::CALLABLE_MEMO_LIMIT) {
            $memo[$mw] = $resolved;
        }

        return $resolved;
    }

    /**
     * Wrap a single middleware item around the provided $next Closure.
     *
     * Supported shapes for $mw:
     *  - callable (closure, [obj,'method'], 'function_name', 'Class::method' string)
     *  - string representing a function name or "Class::method" binding expression
     *
     * The wrapper resolves certain string forms once and memoizes them in a
     * per-process static cache to avoid repeated binding overhead.
     *
     * Example:
     *   function(Request $req): Response
     *
     * @param callable|string $mw Middleware callable or string descriptor.
     * @param Closure(Request):Response $next Next handler in the pipeline.
     * @return Closure(Request):Response Middleware wrapper closure.
     */
    private function wrap(callable|string $mw, Closure $next): Closure
    {
        $mw = $this->resolveMiddlewareTarget($mw);
        $tag = self::describe($mw);
        $invoker = $this->invoker;
        $useInvoker = $this->useInvoker;

        /**
         * Final trampoline for this middleware.
         *
         * If $useInvoker is true the Invoker is used to call $mw with positional
         * and named parameters (named 'request' and 'next') to support DI/autowiring.
         * Otherwise the middleware is called directly with ($req, $next).
         */
        return static function (Request $req) use ($invoker, $mw, $next, $tag, $useInvoker): Response {
            $res = self::callMiddleware($invoker, $useInvoker, $mw, $req, $next);

            return self::assertResponse($res, $tag);
        };
    }

    /**
     * Wrap the final handler into a Closure(Request):Response.
     *
     * When terminal invocation is enabled the Invoker invokes the handler so
     * parameter autowiring is available. The handler result is normalized to
     * a Response instance via assertResponse().
     *
     * @param callable $handler Terminal handler callable.
     * @return Closure(Request):Response Closure that executes the handler and returns a Response.
     */
    private function wrapFinal(callable $handler): Closure
    {
        $invoker = $this->invoker;
        $invoke = $this->invokeFinalWithInvoker;

        return static function (Request $req) use ($handler, $invoke, $invoker): Response {
            $res = $invoke
                ? $invoker->invoke($handler, ['request' => $req])
                : $handler($req);

            return self::assertResponse($res, 'Final handler');
        };
    }
}
