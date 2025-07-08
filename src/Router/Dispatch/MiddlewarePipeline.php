<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Executes a stack of middleware around a final handler.
 *
 * @phpstan-param array<callable(Request,callable):Response> $stack
 * @phpstan-param callable(Request):mixed $last
 */
final class MiddlewarePipeline
{
    /** @var array<callable(Request,callable):Response> */
    private array $stack;

    /** @var callable(Request):mixed */
    private $lastHandler;

    /** @var callable(Request):Response */
    private $pipeline;

    /**
     * @param array<callable(Request,callable):Response> $stack
     * @param callable(Request):mixed $last
     *
     * @throws InvalidArgumentException if any item in $stack is not callable
     */
    public function __construct(array $stack, callable $last)
    {
        foreach ($stack as $mw) {
            if (!is_callable($mw)) {
                throw new InvalidArgumentException(
                    sprintf('Middleware [%s] is not callable', $this->describe($mw)),
                );
            }
        }

        $this->stack = $stack;
        $this->lastHandler = $last;
        $this->pipeline = $this->compose();
    }

    /**
     * Compose and return a single callable(Request):Response
     * by nesting each middleware around the final handler.
     */
    private function compose(): callable
    {
        // start with the final handler wrapped to enforce a Response return
        $next = $this->wrapFinalHandler($this->lastHandler);

        // wrap each middleware in reversed order
        foreach (array_reverse($this->stack) as $mw) {
            $next = $this->wrapMiddleware($mw, $next);
        }

        return $next;
    }

    /**
     * Wrap the final handler so it always returns a Response or throws.
     */
    private function wrapFinalHandler(callable $handler): callable
    {
        return function (Request $req) use ($handler): Response {
            $res = $handler($req);
            return $this->assertResponse($res, 'Final handler');
        };
    }

    /**
     * Wrap a single middleware around the next layer.
     *
     * @param callable(Request,callable):Response $mw
     * @param callable(Request):Response $next
     */
    private function wrapMiddleware(callable $mw, callable $next): callable
    {
        return function (Request $req) use ($mw, $next): Response {
            $res = $mw($req, $next);
            return $this->assertResponse($res, $this->describe($mw));
        };
    }

    /**
     * Ensure the result is a Response, or throw if not.
     *
     * @param mixed $res
     * @param string $source Label for error messaging
     * @return Response
     *
     * @throws UnexpectedValueException
     */
    private function assertResponse(mixed $res, string $source): Response
    {
        if (!$res instanceof Response) {
            $type = is_object($res) ? get_class($res) : gettype($res);
            throw new UnexpectedValueException(
                sprintf('%s returned %s; expected %s', $source, $type, Response::class),
            );
        }
        return $res;
    }

    /**
     * Human-readable description of a middleware callable.
     */
    private function describe(mixed $mw): string
    {
        if (is_object($mw)) {
            return $mw::class;
        }
        return gettype($mw);
    }

    /**
     * Run the composed pipeline and return a Response.
     */
    public function handle(Request $req): Response
    {
        return ($this->pipeline)($req);
    }
}
