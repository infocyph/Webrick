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
 * 1.  Build the (runtime) route table
 * ----------------------------------------------------------------------- */
$routes    = new Collection();
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
});

$registrar->get('/ping', fn () => 'pong');

$registrar->get(
    '/hello/{name}',
    fn (Request $r) =>
    Response::json(['hello' => $r->getAttribute('route_params')['name'] ?? 'stranger'])
);

$registrar->get('/json', fn () => Response::json(['memory' => memory_get_usage(true)]));
$registrar->get('/redirect', fn () => Response::redirect('/', 302));
$registrar->get('/download', fn () => Response::attachment(__FILE__, 'index.php'));
$registrar->get(
    '/color/{hex:hex}',
    fn (Request $r) =>
    Response::json(['you sent hex' => $r->getAttribute('route_params')['hex']])
);

/* --------------------------------------------------------------------------
 * 2.  Compiler callback (executed only when route-table cache is cold)
 * ----------------------------------------------------------------------- */
$compiler = static fn () => $registrar->compile()->all();

/* --------------------------------------------------------------------------
 * 3.  Boot the router kernel
 *      – UnifiedMatcher is chosen automatically
 *      – segment-group cache dropped into ./.route-cache/
 * ----------------------------------------------------------------------- */
$routeCacheDir = __DIR__ . '/.route-cache';

$kernel = RouterKernel::boot(
    log          : new NullLogger(),
    compiler     : $compiler,
    matcher      : null,             // use default UnifiedMatcher
//    routeCacheDir: $routeCacheDir,   // enables lazy on-disk cache
);

/* --------------------------------------------------------------------------
 * 4.  Handle & emit
 * ----------------------------------------------------------------------- */
$request  = Request::fromGlobals();
$response = $kernel->handle($request);

new SapiEmitter()->emit($response);
