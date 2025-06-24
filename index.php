<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\ServerRequest;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Router\RouteCollection;
use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Middleware\{
    ErrorHandlerMiddleware,
    TrailingSlashRedirectMiddleware
};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

/* -----------------------------------------------------------------
   1) Build PSR-7 request from super-globals
   ----------------------------------------------------------------- */
$request = ServerRequest::createFromGlobals();

/* -----------------------------------------------------------------
   2) Create routing engine
   ----------------------------------------------------------------- */
$routes  = new RouteCollection();
$router  = new Router($routes);          // no cache warm-up here

/* -----------------------------------------------------------------
   3) Define routes (immutable style)
   ----------------------------------------------------------------- */
$router->get('/', function (ServerRequestInterface $req): ResponseInterface {
    return new Response(
        200,
        ['Content-Type' => 'text/plain'],
        new Stream('Hello, World from Webrick!')
    );
});

$router->get('/user/{id:int}', function (ServerRequestInterface $req): ResponseInterface {
    $id = $req->getAttribute('id');
    return new Response(
        200,
        ['Content-Type' => 'text/plain'],
        new Stream("User ID is {$id}")
    );
});

$router->get('/admin/dashboard', function (): ResponseInterface {
    return new Response(
        200,
        ['Content-Type' => 'text/plain'],
        new Stream('Welcome to the Admin Dashboard')
    );
});

/* -----------------------------------------------------------------
   4) Global (application-wide) middleware stack
   ----------------------------------------------------------------- */

/** @var list<MiddlewareInterface> $global */
$global = [
    new TrailingSlashRedirectMiddleware(),
    new ErrorHandlerMiddleware(devMode: false),
];

/**
 * Simple PSR-15 middleware stack wrapper.
 * (Avoids pulling the older MiddlewareStack helper into the new tree.)
 */
final class Stack implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $mws */
    public function __construct(private array $mws, private RequestHandlerInterface $core) {}

    public function handle(ServerRequestInterface $r): ResponseInterface
    {
        $handler = array_reduce(
            array_reverse($this->mws),
            /** @return RequestHandlerInterface */
            static fn (RequestHandlerInterface $next, MiddlewareInterface $mw)
                => new class ($mw, $next) implements RequestHandlerInterface {
                public function __construct(
                    private MiddlewareInterface   $mw,
                    private RequestHandlerInterface $next
                ) {}
                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    return $this->mw->process($r, $this->next);
                }
            },
            $this->core
        );

        return $handler->handle($r);
    }
}

/* -----------------------------------------------------------------
   5) Dispatch
   ----------------------------------------------------------------- */
$response = (new Stack($global, $router))->handle($request);

/* -----------------------------------------------------------------
   6) Emit response
   ----------------------------------------------------------------- */
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $v) {
        header("{$name}: {$v}", false);
    }
}
echo (string) $response->getBody();
