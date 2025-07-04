<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Infocyph\InterMix\DI\Invoker;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Experimental WebSocket router – pairs an upgraded HTTP request with
 * an async handler (promise/fiber).  API kept minimal for now.
 */
final class WebSocketKernel
{
    /** @var array<string,callable>  path => async handler */
    private array $handlers = [];

    public function __construct(private readonly Invoker $inv) {}

    public function on(string $path, callable $handler): self
    {
        $this->handlers[$path] = $handler;
        return $this;
    }

    /**
     * Dispatch the upgraded request to the matching async handler.
     *
     * @return mixed The handler’s resolved value / promise.
     */
    public function handle(ServerRequestInterface $req): mixed
    {
        $path = $req->getUri()->getPath() ?: '/';

        if (!isset($this->handlers[$path])) {
            throw new \RuntimeException("No WebSocket handler for {$path}");
        }

        return $this->inv->invoke($this->handlers[$path], [$req]);
    }
}
