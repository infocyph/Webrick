<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\CLI;

use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\OpenApi\OpenApiGenerator;
use Infocyph\Webrick\Http\Uri;

/**
 * Generates an OpenAPI 3.1 document from the annotated route table.
 *
 * Usage:
 *   $ php webrick route:openapi                # -> openapi.json (localhost)
 *   $ php webrick route:openapi spec.yaml https://api.example.com
 */
final class RouteOpenApiCommand
{
    public static function run(array $argv): void
    {
        $cmd = $argv[1] ?? '';
        if ($cmd !== 'route:openapi') {
            self::usage();
            return;
        }

        $file     = $argv[2] ?? 'openapi.json';
        $baseUrl  = $argv[3] ?? 'http://localhost';

        $router   = Router::boot(useCache:true);
        (new OpenApiGenerator($router))->write($file, new Uri($baseUrl));

        echo "✔  OpenAPI spec written to {$file}\n";
    }

    private static function usage(): void
    {
        echo "Usage: php webrick route:openapi [output.(json|yaml)] [baseUrl]\n";
    }
}
