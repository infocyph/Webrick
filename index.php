<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\InterMix\DI\Container;
use Infocyph\Webrick\Http\Request;
use Infocyph\Webrick\Http\ServerRequest;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Router\Router;

/*
 |------------------------------------------------------------------
 | 1. Build (or retrieve) the DI container
 |------------------------------------------------------------------
 |  Register services, configs, etc. here.  For this demo we leave it
 |  empty – the container can still resolve classes via auto-wiring.
 */
$container = new Container();

/*
 |------------------------------------------------------------------
 | 2. Boot the router
 |------------------------------------------------------------------
 |  • bootDev($container)  – rebuilds route table when missing (dev)
 |  • bootFast($container) – requires pre-cached table      (prod)
 */
$router = Router::bootDev($container);

/*
 |------------------------------------------------------------------
 | 3. Register demo routes
 |------------------------------------------------------------------
*/
$router->get('/ping', static fn () => new Response(200, [], new Stream('pong')))
    ->withName('ping');

$router->get('/user/{id:int}', function (Request $request) {
    return new Response(200, [], new Stream(
        'User ID #' . $request->getAttribute('id')
    ));
});

$router->group('/api', function (Router $r) {
    $r->get('/status', static fn () => new Response(200, [], new Stream('OK')))
        ->withName('api.status');
});

/*
 |------------------------------------------------------------------
 | 4. Convert globals → PSR-7 request
 |------------------------------------------------------------------
*/
$request  = ServerRequest::createFromGlobals();

/*
 |------------------------------------------------------------------
 | 5. Dispatch and obtain a PSR-7 response
 |------------------------------------------------------------------
*/
$response = $router->handle($request);

/*
 |------------------------------------------------------------------
 | 6. Emit response
 |------------------------------------------------------------------
*/
http_response_code($response->getStatusCode());

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}

echo (string) $response->getBody();

/*
 |------------------------------------------------------------------
 | 7. (Optional) quick debug
 |------------------------------------------------------------------
*/
// echo $router->urlFor('user.show', ['id' => 42]); // → /user/42
