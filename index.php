<?php

/**
 * index.php – ultra-light Webrick demo
 * Run: php -S localhost:8000 index.php
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';


use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\SapiEmitter;
use Infocyph\Webrick\Response\Payloads\HtmlResponse;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

final readonly class DemoController
{
    /**
     * Example class action.
     * Matches GET /class/{name}
     */
    public function hello(Request $request): Response
    {
        $name = $request->getAttribute('route_params')['name'] ?? 'World';

        return Response::json([
            'handler' => 'DemoController::hello',
            'hello' => $name,
            'time' => \date(DATE_ATOM),
        ]);
    }
}

/* --------------------------------------------------------------------------
 * 1.  Build the (runtime) route table
 * ----------------------------------------------------------------------- */
$routes = new Collection();
$registrar = new Registrar($routes, autoSlashRedirect: true);

/* ---- demo routes ------------------------------------------------------ */
$registrar->get('/', function (): HtmlResponse {
    $links = [
        '/ping' => 'Static text',
        '/hello/Alice' => 'Dynamic placeholder',
        '/json' => 'JSON payload',
        '/download' => 'Download (attachment)',
        '/redirect' => 'Redirect 302 → /',
        '/color/ff00ff' => 'Regex-constrained placeholder',
        '/class/Bob' => '🆕 Class-based handler',
    ];

    $html = "<h1>Webrick demo</h1><ul>";
    foreach ($links as $href => $title) {
        $html .= "<li><a href=\"{$href}\">{$title}</a></li>";
    }
    $html .= '</ul>';

    return new HtmlResponse($html);
});

$registrar->get('/ping', fn () => 'pong');

$registrar->get(
    '/hello/{name}',
    fn (Request $r)
        => Response::json(['hello' => $r->getAttribute('route_params')['name'] ?? 'stranger']),
);

$registrar->get('/json', fn () => Response::json(['memory' => memory_get_usage(true)]));
$registrar->get('/redirect', fn () => Response::redirect('/', 302));
$registrar->get('/download', fn () => Response::attachment(__FILE__, 'index.php'));
$registrar->get(
    '/color/{hex:hex}',
    fn (Request $r)
        => Response::json(['you sent hex' => $r->getAttribute('route_params')['hex']]),
);

/* ---- NEW: class-based route ------------------------------------------ */
$registrar->get('/class/test/{name}', [DemoController::class, 'hello']);
$registrar->get('/class/rest/{name}', [DemoController::class, 'hello']);
$registrar->get('/class/pest', [DemoController::class, 'hello']);

/* --------------------------------------------------------------------------
 * 2.  Compiler callback (executed only when route-table cache is cold)
 * ----------------------------------------------------------------------- */
$compiler = static fn () => $registrar->compile()->all();

/* --------------------------------------------------------------------------
 * 3.  Boot the router kernel – UnifiedMatcher behind the scenes
 * ----------------------------------------------------------------------- */
$logger = new NullLogger();

// A) UnifiedMatcher with segment-dir cache
$kernel = RouterKernel::boot(
    $logger,
    compiler: $compiler,
    matcher:  Infocyph\Webrick\Router\Matching\UnifiedMatcher::make(),                     // default = UnifiedMatcher
//    matcher:  Infocyph\Webrick\Router\Matching\UnifiedMatcherX::make(),                     // default = UnifiedMatcher
    routeCache: __DIR__ . '/.route-cache' // DIRECTORY
);

// B) MergedMatcher with single-file cache
//$kernel = RouterKernel::boot(
//    $logger,
//    compiler: $compiler,
//    matcher:  Infocyph\Webrick\Router\Matching\MergedMatcher::make(),
//    routeCache: __DIR__ . '/.route-cache/__routes.php' // FILE
//);


/* --------------------------------------------------------------------------
 * 4.  Handle & emit
 * ----------------------------------------------------------------------- */
$request = Request::fromGlobals();
$response = $kernel->handle($request);

new SapiEmitter()->emit($response);
