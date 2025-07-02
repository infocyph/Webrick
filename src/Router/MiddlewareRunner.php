<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Light, iterative PSR-15 pipeline (no recursion, no allocations in loop).
 */
final class MiddlewareRunner
{
    /**
     * @param list<object|class-string<MiddlewareInterface>> $stack
     */
    public static function run(
        ServerRequestInterface $req,
        array $stack,
        callable $core
    ): ResponseInterface {
        $handler = new class ($stack, $core) implements RequestHandlerInterface {
            private int $idx = 0;
            public function __construct(
                private array $mw,
                private $core
            ) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                if (!isset($this->mw[$this->idx])) {
                    return ($this->core)($r);
                }
                /** @var MiddlewareInterface|class-string $mid */
                $mid = $this->mw[$this->idx++];
                $obj = \is_object($mid) ? $mid : new $mid();
                return $obj->process($r, $this);
            }
        };

        return $handler->handle($req);
    }
}
