<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Http\Request;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\ServerRequest;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Router\RouteCollection;
use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Middleware\{Dispatcher, ErrorHandlerMiddleware, TrailingSlashRedirectMiddleware};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface};

/* -----------------------------------------------------------------
   1) Build PSR-7 request from super-globals
   ----------------------------------------------------------------- */
$request = Request::createFromGlobals();

/* -----------------------------------------------------------------
   2) Create routing engine
   ----------------------------------------------------------------- */
$router = new Router(new RouteCollection());

/* -----------------------------------------------------------------
   3) Define routes (immutable style)
   ----------------------------------------------------------------- */
$router->get('/', function (): ResponseInterface {
    return new Response(
        200,
        ['Content-Type' => 'text/plain'],
        new Stream('Hello, World from Webrick!'),
    );
});

$router->get('/user/{id:int}', function (Request $req): ResponseInterface {   // works now
    $id = $req->getAttribute('id');
    return new Response(
        200,
        ['Content-Type' => 'text/plain'],
        new Stream("User ID is {$id}"),
    );
});

$router->get('/admin/dashboard', function (): ResponseInterface {
    return new Response(
        200,
        ['Content-Type' => 'text/plain'],
        new Stream('Welcome to the Admin Dashboard'),
    );
});

$response = new Dispatcher(
    [new TrailingSlashRedirectMiddleware(), new ErrorHandlerMiddleware(devMode: true)],
    $router,
)->handle($request);

/* -----------------------------------------------------------------
   6) Emit response
   ----------------------------------------------------------------- */
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $v) {
        header("{$name}: {$v}", false);
    }
}
echo (string)$response->getBody();
