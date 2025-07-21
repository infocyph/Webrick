<?php
/**
 * index.php  – minimal boot-strap + demo routes
 *
 *  ✔ no framework glue or container required
 *  ✔ registers a few static, dynamic, JSON, redirect, download … routes
 *  ✔ renders an index page with clickable links to test them
 *
 *  Run:  php -S localhost:8000 index.php
 *  Docs: see each “use” line for the class’ namespace.
 */

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\SapiEmitter;
use Infocyph\Webrick\Response\Payloads\HtmlResponse;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\MergedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

/* --------------------------------------------------------------------------
 * 1.  Build a Route collection
 * ----------------------------------------------------------------------- */
$routes     = new Collection();
$registrar  = new Registrar($routes);     // root scope, no prefix

/* ---- demo routes ------------------------------------------------------ */

// landing page with links to all other examples
$registrar->get('/', function (): HtmlResponse {
    $links = [
        '/ping'                 => 'Static text',
        '/hello/Alice'          => 'Dynamic placeholder',
        '/json'                 => 'JSON payload',
        '/download'             => 'Download (attachment)',
        '/redirect'             => 'Redirect 302 → /',
        '/color/ff00ff'         => 'Regex-constrained placeholder',
    ];

    $html  = "<h1>Webrick demo</h1><ul>";
    foreach ($links as $href => $title) {
        $html .= "<li><a href=\"{$href}\">{$title}</a></li>";
    }
    $html .= '</ul>';

    return new HtmlResponse($html);
})->withName('home');

$registrar->get('/ping', fn () => 'pong');

$registrar->get('/hello/{name}', function (Request $r): Response {
    $params = $r->getAttribute('route_params');
    return Response::json(['hello' => $params['name']]);
});

$registrar->get('/json', fn () => Response::json(['time' => date(DATE_ATOM)]));

$registrar->get('/redirect', fn () => Response::redirect('/', 302));

$registrar->get('/download', fn () => Response::attachment(__FILE__, 'index.php'));

$registrar->get('/color/{hex:hex}', function (Request $r): Response {
    $hex = $r->getAttribute('route_params')['hex'];
    return Response::json(['you sent hex' => $hex]);
});

/* --------------------------------------------------------------------------
 * 2.  Freeze route table → compiled collection
 * ----------------------------------------------------------------------- */
$compiled = $registrar->compile();

/* --------------------------------------------------------------------------
 * 3.  Spin up the Router kernel (Matcher + Dispatcher)
 * ----------------------------------------------------------------------- */
$kernel = RouterKernel::boot(
    log      : new NullLogger(),
    cachePool: Cache::file('webrick'),            // throw-away PSR-6 cache
    compiler : fn () => $compiled->all(),     // returns list<CompiledRoute>
    matcher  : new MergedMatcher(),           // fast in-memory matcher
    regexDump: ''
);

/* --------------------------------------------------------------------------
 * 4.  Handle the current HTTP request & emit the response
 * ----------------------------------------------------------------------- */
$request  = Request::fromGlobals();           // PSR-7 request from PHP globals
$response = $kernel->handle($request);        // route → middleware → handler
new SapiEmitter()->emit($response);         // send headers + body
