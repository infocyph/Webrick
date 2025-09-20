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
 * Notes:
 *  - Middleware callables must follow the shape:
 *      function(Request $req, callable $next): Response
 *  - The final handler (last) is a callable that accepts a Request and returns
 *    any value which will be normalized to Response (or an exception thrown).
 *
 * @phpstan-type Middleware   callable(Request, callable(Request):Response):Response
 * @phpstan-type FinalHandler callable(Request):mixed
 *
 * @psalm-immutable
 *
 * @package Infocyph\Webrick\Router\Dispatch
 * @author Infocyph
 */
final class MiddlewarePipeline
{
    /**
     * Middleware stack in execution order (first executed -> first element).
     *
     * @var list<Middleware>
     */
    private array $stack;

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
     * Composed pipeline closure that accepts a Request and returns a Response.
     *
     * Built once in the constructor for per-request reuse.
     *
     * @var Closure(Request):Response
     */
    private Closure $pipeline;

    /**
     * Whether to dispatch middleware/handler via the Invoker (DI/autowiring).
     *
     * @var bool
     */
    private bool $useInvoker;

    /**
     * DI invoker used to call middleware and handlers when $useInvoker is true.
     *
     * @var Invoker
     */
    private Invoker $invoker;

    /**
     * Construct the pipeline executor.
     *
     * Validates that each $stack element is callable and composes the pipeline
     * Closure once for reuse.
     *
     * @param list<Middleware> $stack Ordered list of middleware callables.
     * @param FinalHandler $last Terminal handler callable executed after middleware.
     * @param Invoker $invoker DI invoker used when $useInvoker is enabled.
     * @param bool $useInvoker When true, use $invoker to invoke middleware and final handler.
     *
     * @throws InvalidArgumentException If any entry in $stack is not callable.
     */
    public function __construct(array $stack, callable $last, Invoker $invoker, bool $useInvoker = true)
    {
        foreach ($stack as $mw) {
            if (!\is_callable($mw)) {
                throw new InvalidArgumentException(
                    sprintf('Middleware [%s] is not callable', self::describe($mw)),
                );
            }
        }

        $this->stack = $stack;
        $this->lastHandler = $last;
        $this->useInvoker = $useInvoker;
        $this->invoker = $invoker;
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

    /**
     * Wrap the final handler into a Closure(Request):Response.
     *
     * When $useInvoker is true the Invoker is used to invoke the handler so
     * parameter autowiring is available. The handler result is normalized to
     * a Response instance via assertResponse().
     *
     * @param callable $handler Terminal handler callable.
     * @return Closure(Request):Response Closure that executes the handler and returns a Response.
     *
     * @throws UnexpectedValueException If the handler returns a non-Response value.
     */
    private function wrapFinal(callable $handler): Closure
    {
        $useInvoker = $this->useInvoker;
        $invoker = $this->invoker;

        return static function (Request $req) use ($handler, $useInvoker, $invoker): Response {
            $res = $useInvoker
                ? $invoker->invoke($handler)
                : $handler($req);

            return self::assertResponse($res, 'Final handler');
        };
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
     * The returned Closure conforms to:
     *   function(Request $req): Response
     *
     * @param callable|string $mw Middleware callable or string descriptor.
     * @param Closure(Request):Response $next Next handler in the pipeline.
     * @return Closure(Request):Response Middleware wrapper closure.
     *
     * @throws UnexpectedValueException If the middleware or the downstream handler
     *                                  returns a non-Response value.
     */
    private function wrap(callable|string $mw, Closure $next): Closure
    {
        /* ── Closure / "function" / "Class::method" / callable[] ─────── */
        // Per-process memoization for string descriptors (e.g. "Cls::method").
        static $memo = [];                          // per-process cache for "Cls::method"/function names
        $invoker = $this->invoker;
        $useInvoker = $this->useInvoker;

        /**
         * Resolve **once**:
         *   • plain function name -> keep as-is (Invoker can still call it)
         *   • "Cls::method"        -> bind & memoise Closure via callable bound expansion
         *   • [Obj,'meth']         -> already bound; keep directly
         */
        if (\is_string($mw)) {
            $mw = $memo[$mw] ??= (
                str_contains($mw, '::')
                ? $mw(...)          // bind once via first-class callable expression
                : $mw               // plain function name
            );
        }

        $tag = self::describe($mw);                 // cheap after memo

        /**
         * Final trampoline for this middleware.
         *
         * If $useInvoker is true the Invoker is used to call $mw with positional
         * and named parameters (named 'request' and 'next') to support DI/autowiring.
         * Otherwise the middleware is called directly with ($req, $next).
         */
        return static function (Request $req) use ($invoker, $mw, $next, $tag, $useInvoker): Response {
            $res = $useInvoker
                ? $invoker->invoke($mw, [
                    'request' => $req,
                    'next' => $next, // named for autowiring
                ])
                : $mw($req, $next);

            return self::assertResponse($res, $tag);
        };
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
            $type = \is_object($res) ? $res::class : \gettype($res);
            throw new UnexpectedValueException(
                sprintf('%s returned %s; expected %s', $source, $type, Response::class),
            );
        }
        return $res;
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
            : (\is_array($mw) ? 'callable[]' : (string)\gettype($mw));
    }
}
