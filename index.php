<?php
declare(strict_types=1);

/**
 * public/index.php
 *
 * Run with:
 *   php -S localhost:8000 public/index.php
 *
 * End-points to try:
 *   GET /hello
 *   GET /user/42
 *   GET /post/super-fast-router
 *   GET /api/v1/ping
 */

require_once __DIR__.'/vendor/autoload.php';

use Infocyph\InterMix\DI\Container;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Registrar;
use Infocyph\Webrick\Router\Attributes\Route   as RouteAttr;
use Infocyph\Webrick\Router\Attributes\Middleware as MwAttr;

/* ---------------------------------------------------------------------
   1.   Container & common services
   --------------------------------------------------------------------*/
$container = Container::instance('intermix');
$container->definitions()->bind(
    DateTimeImmutable::class,
    static fn () => new DateTimeImmutable('now', new DateTimeZone('UTC')),
);

/* ---------------------------------------------------------------------
   2.   Boot the (singleton) router
   --------------------------------------------------------------------*/
$router = Router::instance($container);

/* ---------------------------------------------------------------------
   3.   Example middleware alias + global attach
   --------------------------------------------------------------------*/
class AuthMiddleware implements Psr\Http\Server\MiddlewareInterface
{
    public function process(
        Psr\Http\Message\ServerRequestInterface $request,
        Psr\Http\Server\RequestHandlerInterface $handler
    ): Psr\Http\Message\ResponseInterface {
        // (dummy - just continue)
        return $handler->handle($request);
    }
}

$router->middlewareAlias('auth', AuthMiddleware::class)
    ->globalMiddleware(before: ['auth']);

/* ---------------------------------------------------------------------
   4.   Attribute-based controllers
   --------------------------------------------------------------------*/
#[MwAttr('auth')]        // class-level middleware
class UserController
{
    #[RouteAttr('GET', '/user/{id:int}', name: 'user.show')]
    public function show(DateTimeImmutable $now, int $id): string
    {
        return "User #{$id} @ ".$now->format(DateTimeInterface::ATOM);
    }
}

class ApiController
{
    #[RouteAttr(['GET','HEAD'], '/api/v1/ping', name: 'api.ping')]
    public function ping(): string { return 'pong'; }
}

/* --- Register attribute routes ------------------------------------ */
Infocyph\Webrick\Router\AttributeScanner::scan($router, [__DIR__]);

/* ---------------------------------------------------------------------
   5.   Fluent / “Laravel-style” routes
   --------------------------------------------------------------------*/
$root = $router->scope();                         // Registrar shortcut

// simple named route --------------------------------------------------
$root->get('/hello', fn () => 'Hello world!')
    ->withName('hello');

// group with prefix + slug constraint --------------------------------
$root->group(['prefix' => 'post'], function (Registrar $r) {
    $r->get('/{slug:slug}', function (string $slug) {
        return "Post slug = {$slug}";
    });
});

/* nested group + DI injection --------------------------------------- */
$root->group(['prefix' => 'tools'], function (Registrar $tools) {
    $tools->group(['prefix' => 'strings'], function (Registrar $str) {
        $str->post('/upper', function (Request $req) {
            $txt = (string) ($req->getParsedBody()['txt'] ?? '');
            return strtoupper($txt);
        });
    });
});

/* ---------------------------------------------------------------------
   6.   Dispatch current request
   --------------------------------------------------------------------*/
$request  = Request::fromGlobals();       // your concrete PSR-7 Request
$response = $router->handle($request);

/* Emit (dependency-free) ------------------------------------------- */
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $v) { header("$name: $v", false); }
}
echo (string) $response->getBody();
