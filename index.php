<?php

/**
 * index.php – ultra-light Webrick demo
 * Run: php -S localhost:8000 index.php
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
 * 1.  Build the route table (no pre-compiling!)
 * ----------------------------------------------------------------------- */
$routes    = new Collection();
$registrar = new Registrar($routes, autoSlashRedirect: false);

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
});

$registrar->get('/ping', fn () => 'pong');

$registrar->get(
    '/hello/{name}',
    fn (Request $r) =>
Response::json(['hello' => $r->getAttribute('route_params')['name'] ?? 'stranger'])
);

$registrar->get('/json', fn () => Response::json(['time' => date(DATE_ATOM)]));
$registrar->get('/redirect', fn () => Response::redirect('/', 302));
$registrar->get('/download', fn () => Response::attachment(__FILE__, 'index.php'));
$registrar->get(
    '/color/{hex:hex}',
    fn (Request $r) =>
Response::json(['you sent hex' => $r->getAttribute('route_params')['hex']])
);

/* --------------------------------------------------------------------------
 * 2.  Prepare a *lazy* compiler – only invoked when the dump is missing
 * ----------------------------------------------------------------------- */
$routeDumpPath = __DIR__ . '/.route-table.php';

$compiler = static function () use ($registrar, $routeDumpPath) {
    return file_exists($routeDumpPath)
        ? []                             // routes already persisted – skip
        : $registrar->compile()->all();  // first boot or cache flush
};

/* --------------------------------------------------------------------------
 * 3.  Boot the router kernel
 * ----------------------------------------------------------------------- */
$kernel = RouterKernel::boot(
    log       : new NullLogger(),
    cachePool : Cache::file('webrick.c'),
    compiler  : $compiler,
    matcher   : new MergedMatcher(),
    regexDump : $routeDumpPath,          // on first run it’s created, thereafter loaded
);

/* --------------------------------------------------------------------------
 * 4.  Handle & emit
 * ----------------------------------------------------------------------- */
$request  = Request::fromGlobals();
$response = $kernel->handle($request);

new SapiEmitter()->emit($response);
