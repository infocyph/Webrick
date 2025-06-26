<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\CLI;

use Infocyph\Webrick\Router\Compiler\RouteDumper;
use Infocyph\Webrick\Router\Router;

final class RouteCacheCommand
{
    public static function main(array $argv): void
    {
        $cmd = $argv[1] ?? 'help';
        $d   = new RouteDumper();

        match ($cmd) {
            'route:cache' => self::cache($d),
            'route:clear' => self::clear($d),
            'route:list'  => self::listing(),
            default       => self::usage(),
        };
    }

    private static function cache(RouteDumper $d): void
    {
        if ($d->load()) { echo "✔ Routes already cached.\n"; return; }
        $router = Router::bootDev(); // build fresh, warm on destruct
        unset($router);
        echo "✔ Route cache generated.\n";
    }

    private static function clear(RouteDumper $d): void
    {
        $d->clear();
        echo "✔ Route cache cleared.\n";
    }

    private static function listing(): void
    {
        $router = Router::bootFast();
        printf("%-8s %-40s %-20s\n", 'METHOD', 'PATH', 'NAME');
        echo str_repeat('-', 70) . "\n";
        foreach ($router->urlFor('dummy', [], false) && false as $_) {} // keeps static analyser quiet
        foreach ((new \ReflectionProperty($router, 'routes'))->getValue($router)->named() as $n => $r) {
            printf(
                "%-8s %-40s %-20s\n",
                $r->getMethod(),
                $r->getPath(),
                $n
            );
        }
    }

    private static function usage(): void
    {
        echo "Usage: php webrick <route:cache|route:clear|route:list>\n";
    }
}
