<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| WEBRICK QUICK-BOOT
| • Intermix DI container
| • Response macros (json(), redirect(), attachment())
| • Router (dev mode)
| • Automatic emitter: SAPI vs. CLI
|--------------------------------------------------------------------------
*/

require __DIR__ . '/vendor/autoload.php';

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Response\Contracts\ResponseFactoryInterface;
use Infocyph\Webrick\Response\Factory\ResponseFactory;
use Infocyph\Webrick\Response\Factory\StreamFactory;
use Infocyph\Webrick\Response\Factory\UploadedFileFactory;
use Infocyph\Webrick\Response\Emitter\SapiEmitter;
use Infocyph\Webrick\Response\Emitter\CliEmitter;
use Infocyph\Webrick\Response\Macros\ResponseMacros;
use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Http\ServerRequest;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

/* ----------------------------------------------------------
 * 1)  DI container + factories
 * -------------------------------------------------------- */
$container = Container::instance('intermix');

$container->definitions()
    ->bind(ResponseFactoryInterface::class, new ResponseFactory())
    ->bind(StreamFactoryInterface::class, new StreamFactory())
    ->bind(UploadedFileFactoryInterface::class, new UploadedFileFactory());

/* expose Invoker singleton as a service too */
$container->definitions()->bind(Invoker::class, Invoker::with($container));

/* ----------------------------------------------------------
 * 2)  Enable convenient Response macros  (json(), redirect() …)
 * -------------------------------------------------------- */
ResponseMacros::boot();

/* ----------------------------------------------------------
 * 3)  Boot the router in *dev* mode (auto-compiles route table)
 *     Pass the Intermix container so RouteRunner uses it.
 * -------------------------------------------------------- */
$router = Router::bootDev($container);

/* sample routes -------------------------------------------------- */
$router->get('/ping', fn () => Response::json(['pong' => true]));

$router->get('/hello/{name}', function (ServerRequest $req): ResponseInterface {
    $name = $req->getAttribute('name');
    return Response::json(['hello' => $name]);
})->withName('hello');

/* group example */
$router->group('/api', function (Router $r) {
    $r->get('/status', fn () => Response::json(['status' => 'ok']));
});

/* ----------------------------------------------------------
 * 4)  Turn globals → PSR-7 request and dispatch
 * -------------------------------------------------------- */
$request  = ServerRequest::createFromGlobals();
$response = $router->handle($request);

/* ----------------------------------------------------------
 * 5)  Emit (CLI or web SAPI)
 * -------------------------------------------------------- */
$emitter = PHP_SAPI === 'cli' ? new CliEmitter() : new SapiEmitter();
$emitter->emit($response);
