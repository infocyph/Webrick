<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Allocation-free PSR-15 pipeline (iterative, not recursive).
 */
final class MiddlewareRunner
{
    /**
     * @param list<object|class-string<MiddlewareInterface>> $stack
     * @param callable(ServerRequestInterface):ResponseInterface $core
     */
    public static function run(
        ServerRequestInterface $req,
        array $stack,
        callable $core
    ): ResponseInterface {
        $handler = new class ($stack, $core) implements RequestHandlerInterface {
            private int $idx = 0;

            /**
             * @param list<object|class-string<MiddlewareInterface>> $mw
             * @param callable(ServerRequestInterface):ResponseInterface $core
             */
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
