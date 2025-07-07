<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\InterMix\DI\Invoker;

final class Dispatcher
{
    public function __construct(private Invoker $invoker) {}

    /**
     * @param array<string,string> $vars  extracted URI placeholders
     */
    public function __invoke(CompiledRoute $r, Request $req, array $vars): Response
    {
        $req = $req->withAttribute('route_params', $vars);

        $pipeline = new MiddlewarePipeline(
            $r->getMiddlewares(),
            function (Request $rq) use ($r) {
                return $this->invoker->invoke($r->getHandler(), ['request' => $rq]);
            }
        );

        $resp = $pipeline->handle($req);
        return $resp instanceof Response ? $resp : Response::json($resp);
    }
}
