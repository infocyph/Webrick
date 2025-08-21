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
 * Executes a stack of middleware around a final handler.
 *
 * @phpstan-type Middleware   callable(Request, callable(Request):Response):Response
 * @phpstan-type FinalHandler callable(Request):mixed
 *
 * @psalm-immutable
 */
final class MiddlewarePipeline
{
    /** @var list<Middleware> */
    private array $stack;

    /** @var FinalHandler */
    private $lastHandler;

    /** @var Closure(Request):Response */
    private Closure $pipeline;

    /** Whether to call via Invoker (DI) or manually. */
    private bool $useInvoker;

    /** DI resolver (singleton by default). */
    private Invoker $invoker;

    /**
     * @param list<Middleware> $stack
     * @param FinalHandler $last
     * @param bool $useInvoker When true, call via Invoker and inject ($req, $next). When false, call manually.
     * @param Invoker|null $invoker Optional custom invoker (defaults to Invoker::shared()).
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $stack, callable $last, bool $useInvoker = true, ?Invoker $invoker = null)
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

    /** Run the composed pipeline. */
    public function handle(Request $req): Response
    {
        return ($this->pipeline)($req);
    }

    /* -------------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------------- */

    /** Build the Closure only once (ctor) – zero allocations per request. */
    private function compose(): Closure
    {
        $next = $this->wrapFinal($this->lastHandler);

        foreach (\array_reverse($this->stack) as $mw) {
            $next = $this->wrap($mw, $next);
        }

        /** @var Closure(Request):Response $next */
        return $next;
    }

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

    private function wrap(callable|string $mw, Closure $next): Closure
    {
        /* ── Closure / "function" / "Class::method" / callable[] ─────── */
        static $memo = [];                          // per-process cache for "Cls::method"/function names
        $invoker = $this->invoker;
        $useInvoker = $this->useInvoker;

        /**
         * Resolve **once**:
         *   • plain `function` → keep as-is (Invoker can still call it)
         *   • `"Cls::method"`  → bind & memoise Closure
         *   • `[Obj,'meth']`   → keep (already bound)
         */
        if (\is_string($mw)) {
            $mw = $memo[$mw] ??= (
                str_contains($mw, '::')
                ? $mw(...)          // bind once
                : $mw               // plain function name
            );
        }

        $tag = self::describe($mw);                 // cheap after memo

        /**
         * Final trampoline for each middleware in the stack.
         * If using DI, inject `$req` and `$next` both positionally and by name.
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
     * @param mixed $res
     * @throws UnexpectedValueException
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

    private static function describe(mixed $mw): string
    {
        return \is_object($mw) ? $mw::class
            : (\is_array($mw) ? 'callable[]' : (string)\gettype($mw));
    }
}
