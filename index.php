<?php

declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';

use Infocyph\Webrick\Core\RouteCollection;
use Infocyph\Webrick\Core\RouteParser;
use Infocyph\Webrick\Core\Router;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\ServerRequest;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Http\Uri;
use Infocyph\Webrick\Middleware\ErrorHandlerMiddleware;
use Infocyph\Webrick\Middleware\HttpsEnforceMiddleware;
use Infocyph\Webrick\Middleware\MethodOverrideMiddleware;
use Infocyph\Webrick\Middleware\MiddlewareStack;
use Infocyph\Webrick\Middleware\RouteDispatcher;
use Infocyph\Webrick\Middleware\TrailingSlashRedirectMiddleware;
use Psr\Http\Message\ServerRequestInterface;

$container = container();

// ----------------------------------------------------------------------------
// 3) Build the PSR-7 Request from Superglobals
// ----------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$scheme = (($_SERVER['HTTPS'] ?? 'off') === 'on') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uriStr = $scheme.$host.($_SERVER['REQUEST_URI'] ?? '/');

$uri = new Uri($uriStr);

// Minimal approach to capturing headers
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $name = str_replace('_', '-', substr($key, 5));
        $headers[$name] = $value;
    }
}

// Build a ServerRequest
$request = ServerRequest::createFromGlobals();

// ----------------------------------------------------------------------------
// 4) Create RouteCollection, enable caching if desired
// ----------------------------------------------------------------------------
$routeParser = new RouteParser();
$routeCollection = new RouteCollection($routeParser);

// If you want to load or store routes in a cache file:
// $routeCollection->enableCache(__DIR__ . '/../routeCache.php');

// ----------------------------------------------------------------------------
// 5) Create the Router
// ----------------------------------------------------------------------------
$router = new Router($routeCollection);

// ----------------------------------------------------------------------------
// 6) Define Routes
// ----------------------------------------------------------------------------

// A simple route at root "/"
$router->get('/', function (ServerRequestInterface $req) {
    $body = new Stream('Hello, World from the updated Webrick!');

    return new Response(200, ['Content-Type' => 'text/plain'], $body);
});

// A route with placeholders
$router->get('/user/{id:\d+}', function (ServerRequestInterface $req) {
    $id = $req->getAttribute('id');
    $body = new Stream("User ID is {$id}");

    return new Response(200, ['Content-Type' => 'text/plain'], $body);
})->setName('user.show');
// Example route-level middleware:
//   ->middleware([AuthMiddleware::class]);

// A group with prefix '/admin'
$router->group('/admin', function (Router $r) {
    $r->get('/dashboard', function (ServerRequestInterface $req) {
        dd($req);
        $body = new Stream('Welcome to the Admin Dashboard');

        return new Response(200, ['Content-Type' => 'text/plain'], $body);
    })->setName('admin.dashboard');
    // more admin routes...
})->middleware([
    // e.g., AdminAuthMiddleware::class
]);

// (Optional) store the updated route definitions to cache
// $routeCollection->storeCache();

// ----------------------------------------------------------------------------
// 7) Create the Final Dispatcher (RouteDispatcher) with container (optional)
// ----------------------------------------------------------------------------
$finalDispatcher = new RouteDispatcher(null, $container); // or (null, null) if no DI
$router->setFinalRouteDispatcher($finalDispatcher);

// ----------------------------------------------------------------------------
// 8) Build the Global Middleware Stack
// ----------------------------------------------------------------------------
// You can add or remove any middlewares here:
$errorHandler = new ErrorHandlerMiddleware(devMode: false);  // 'false' => production mode
$httpsEnforcer = new HttpsEnforceMiddleware(productionMode: false); // set true if you want to force https
$slashRedirect = new TrailingSlashRedirectMiddleware();
$methodOverride = new MethodOverrideMiddleware('X-HTTP-Method-Override'); // override if needed

$globalMiddlewares = [
    // Enforce HTTPS in production (set productionMode => true)
    // $httpsEnforcer,

    // Avoid trailing slash duplicates
    $slashRedirect,

    // Let clients override method via header
    $methodOverride,

    // Catch 404 / 405 / 500 errors
    $errorHandler,
];

$stack = new MiddlewareStack($globalMiddlewares, $router);

// ----------------------------------------------------------------------------
// 9) Dispatch the Request
// ----------------------------------------------------------------------------
$response = $stack->handle($request);

// ----------------------------------------------------------------------------
// 10) Emit the PSR-7 Response
// ----------------------------------------------------------------------------
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}

echo (string) $response->getBody();

/**
 * Done!
 *
 * Examples:
 *  - http://localhost:8080/          => "Hello, World from the updated Webrick!"
 *  - http://localhost:8080/user/42   => "User ID is 42"
 *  - http://localhost:8080/admin/dashboard => "Welcome to the Admin Dashboard"
 *
 * With trailing slash middleware, requests to e.g. /user/42/ automatically 301-redirect to /user/42
 * If HttpsEnforceMiddleware is enabled in production, any http:// request 302-redirects to https://
 */
