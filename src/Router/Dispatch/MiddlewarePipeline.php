<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Response as Resp;

final class MiddlewarePipeline
{
    /** @param array<class-string|object> $stack */
    public function __construct(private array $stack, private Closure $last) {}

    public function handle(Request $req): Resp
    {
        $next = array_reduce(
            array_reverse($this->stack),
            fn (Closure $carry, object $mw) => fn (Request $r) => $mw($r, $carry),
            $this->last
        );

        /** @var Response $out */
        $out = $next($req);
        return $out;
    }
}
