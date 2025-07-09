<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;
use Closure;

/**
 * Executes a stack of middleware around a final handler.
 *
 * @phpstan-type Middleware callable(Request, callable(Request):Response):Response
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
     * @param list<Middleware>   $stack
     * @param FinalHandler       $last
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $stack, callable $last)
    {
        foreach ($stack as $mw) {
            if (!\is_callable($mw)) {
                throw new InvalidArgumentException(
                    sprintf('Middleware [%s] is not callable', self::describe($mw))
                );
            }
        }

        $this->stack       = $stack;
        $this->lastHandler = $last;
        $this->pipeline    = $this->compose();
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
        return function (Request $req) use ($handler): Response {
            $res = $handler($req);
            return self::assertResponse($res, 'Final handler');
        };
    }
    private function wrap(callable $mw, Closure $next): Closure
    {
        // recognise PSR-15 objects
        if ($mw instanceof MiddlewareInterface) {
            return fn (Request $req): Response => self::assertResponse($mw->process(
                $req,
                new class ($next) implements RequestHandlerInterface {
                    public function __construct(private $next)
                    {
                    }
                    public function handle(ServerRequestInterface $request): Response
                    {
                        /** @var callable $n */ $n = $this->next;
                        return $n($request);
                    }
                }
            ), $mw::class);
        }

        // default closure/callable path
        return fn (Request $req): Response => self::assertResponse($mw($req, $next), self::describe($mw));
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    /**
     * @param mixed  $res
     * @throws UnexpectedValueException
     */
    private static function assertResponse(mixed $res, string $source): Response
    {
        if (!$res instanceof Response) {
            $type = \is_object($res) ? $res::class : \gettype($res);
            throw new UnexpectedValueException(
                sprintf('%s returned %s; expected %s', $source, $type, Response::class)
            );
        }
        return $res;
    }

    private static function describe(mixed $mw): string
    {
        return \is_object($mw) ? $mw::class
            : (\is_array($mw) ? 'callable[]' : (string) \gettype($mw));
    }
}
