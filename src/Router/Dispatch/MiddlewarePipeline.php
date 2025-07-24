<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

    /**
     * @param list<Middleware> $stack
     * @param FinalHandler $last
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $stack, callable $last)
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
        static $factory = null;                 // <── promoted static

        if ($factory === null) {
            $factory = static function (callable $h): Closure {
                $tag = 'Final handler';
                return static function (Request $req) use ($h, $tag): Response {
                    $res = $h($req);
                    return self::assertResponse($res, $tag);
                };
            };
        }
        return $factory($handler);
    }

    private function wrap(callable|string $mw, Closure $next): Closure
    {
        /* ── PSR-15 objects ───────────────────────────────────────────── */
        if ($mw instanceof MiddlewareInterface) {
            $tag = $mw::class;                                         // cached once

            return static fn (Request $req): Response
                => self::assertResponse(
                    $mw->process(
                        $req,
                        new class ($next) implements RequestHandlerInterface {
                            public function __construct(private Closure $next)
                            {
                            }

                            public function handle(ServerRequestInterface $request): Response
                            {
                                $n = $this->next;
                                return $n($request);
                            }
                        },
                    ),
                    $tag,
                );
        }

        /* ── Closure / "function" / "Class::method" / callable[] ─────── */
        static $memo = [];                                             // per-process cache
        $invoker = Invoker::shared();                              // DI resolver (singleton)

        /**
         * Resolve **once**:
         *   • plain `function` → keep as-is (Invoker can still call it)
         *   • `"Cls::method"`  → bind & memoise Closure
         *   • `[Obj,'meth']`   → keep (already bound)
         */
        if (\is_string($mw)) {
            $mw = $memo[$mw] ??= (
                str_contains($mw, '::')
                ? \Closure::fromCallable($mw)                      // bind once
                : $mw                                              // plain function name
            );
        }

        $tag = self::describe($mw);                                    // cheap after memo

        /**
         * Final trampoline: let Invoker inject `$req`, `$next`, plus any other
         * container-resolvable dependencies the middleware may declare.
         */
        return static function (Request $req) use ($invoker, $mw, $next, $tag): Response {
            $res = $invoker->invoke($mw, [
                Request::class => $req,
                'request' => $req,   // common alias
                Closure::class => $next,  // for `Closure $next` type-hints
                'next' => $next,  // …and name-based injection
            ]);

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
