<?php
/**
 * index.php – ultra-light Webrick demo
 *
 * Run with:
 *   php -S localhost:8000 index.php
 *
 * No framework, no container – just Composer’s autoloader.
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
 * 1. Build the route table
 * ----------------------------------------------------------------------- */

$routes = new Collection();

/**
 * Pass `true` as 3rd ctor arg to enable automatic trailing-slash
 * redirects for every GET route (e.g. “/foo/” → “/foo”, 308).
 */
$registrar = new Registrar($routes, autoSlashRedirect: true);

/* ---- demo routes ------------------------------------------------------ */

$registrar->get('/', function (): HtmlResponse {
    $links = [
        '/ping'         => 'Static text',
        '/hello/Alice'  => 'Dynamic placeholder',
        '/json'         => 'JSON payload',
        '/download'     => 'Download (attachment)',
        '/redirect'     => 'Redirect 302 → /',
        '/color/ff00ff' => 'Regex-constrained placeholder',
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
    $name = $r->getAttribute('route_params')['name'] ?? 'stranger';
    return Response::json(['hello' => $name]);
});

$registrar->get('/json', fn () => Response::json(['time' => date(DATE_ATOM)]));

$registrar->get('/redirect', fn () => Response::redirect('/', 302));

$registrar->get('/download', fn () => Response::attachment(__FILE__, 'index.php'));

$registrar->get('/color/{hex:hex}', function (Request $r): Response {
    $hex = $r->getAttribute('route_params')['hex'];
    return Response::json(['you sent hex' => $hex]);
});

/* --------------------------------------------------------------------------
 * 2. Compile once – immutable after this call
 * ----------------------------------------------------------------------- */
$compiled = $registrar->compile();

/* --------------------------------------------------------------------------
 * 3. Boot the router kernel (matcher + dispatcher)
 * ----------------------------------------------------------------------- */

$kernel = RouterKernel::boot(
    log       : new NullLogger(),
    cachePool : Cache::file('webrick'),          // simple PSR-6 file cache
    compiler  : fn () => $compiled->all(),       // returns list<CompiledRoute>
    matcher   : new MergedMatcher(),             // blazing-fast in-memory matcher
    // Persist a fast-regex dump so the *next* boot skips per-route adds
    regexDump : __DIR__ . '/.route-table.php',
);

/* --------------------------------------------------------------------------
 * 4. Handle the current HTTP request & emit the response
 * ----------------------------------------------------------------------- */

$request  = Request::fromGlobals();      // PSR-7 request from PHP super-globals
$response = $kernel->handle($request);   // route → middleware → handler

new SapiEmitter()->emit($response);    // send headers & body to the client
