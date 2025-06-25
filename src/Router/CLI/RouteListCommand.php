<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\CLI;

use Infocyph\Webrick\Router\Router;

/**
 * Pretty-prints the current route table.
 *
 * Usage examples
 *  ┌─$ php webrick route:list
 *  ├─$ php webrick route:list --method=POST
 *  └─$ php webrick route:list --domain=api.example.com
 */
final class RouteListCommand
{
    /* ---------- colour helpers (ANSI) ------------------------- */
    private const C = [
        'reset'  => "\033[0m",
        'cyan'   => "\033[36m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
    ];

    public static function run(array $argv): void
    {
        $cmd = $argv[1] ?? '';
        if ($cmd !== 'route:list') {
            self::usage();
            return;
        }

        [$filterMethod, $filterDomain] = self::parseOptions(array_slice($argv, 2));

        $router   = Router::boot(useCache:true);
        $refl     = new \ReflectionProperty($router, 'routes');
        $routes   = $refl->getValue($router)->named();

        /* gather rows */
        $rows = [];
        foreach ($routes as $name => $route) {
            if ($filterMethod && $route->getMethod() !== $filterMethod) {
                continue;
            }
            if ($filterDomain !== null && $route->getDomain() !== $filterDomain) {
                continue;
            }
            $rows[] = [
                strtoupper($route->getMethod()),
                $route->getPath(),
                $route->getDomain() ?: '—',
                $name ?: '—',
            ];
        }

        self::render($rows);
    }

    /* ----- option parsing -------------------------------------- */
    private static function parseOptions(array $args): array
    {
        $method = null;
        $domain = null;
        foreach ($args as $a) {
            if (str_starts_with($a, '--method=')) {
                $method = strtoupper(substr($a, 9));
            } elseif (str_starts_with($a, '--domain=')) {
                $domain = substr($a, 9);
            }
        }
        return [$method, $domain];
    }

    /* ----- pretty output --------------------------------------- */
    /** @param list<array{string,string,string,string}> $rows */
    private static function render(array $rows): void
    {
        [$c, $g, $y, $r] = [self::C['cyan'], self::C['green'], self::C['yellow'], self::C['reset']];

        $w = [8, 40, 25, 30];                            // col widths
        foreach ($rows as $row) {
            $w[1] = max($w[1], strlen($row[1]));
            $w[2] = max($w[2], strlen($row[2]));
            $w[3] = max($w[3], strlen($row[3]));
        }

        $fmtH = "%-{$w[0]}s  %-{$w[1]}s  %-{$w[2]}s  %-{$w[3]}s\n";
        printf($fmtH, "{$c}METHOD{$r}", "{$c}PATH{$r}", "{$c}DOMAIN{$r}", "{$c}NAME{$r}");
        echo str_repeat('-', array_sum($w) + 6) . "\n";

        foreach ($rows as [$m, $p, $d, $n]) {
            $mColour = match ($m) {
                'GET' => $g.$m.$r, 'POST' => $y.$m.$r, default => $m
            };
            printf("%-{$w[0]}s  %-{$w[1]}s  %-{$w[2]}s  %-{$w[3]}s\n", $mColour, $p, $d, $n);
        }

        if ($rows === []) {
            echo "No routes match the given filter.\n";
        }
    }

    private static function usage(): void
    {
        echo "Usage: php webrick route:list [--method=VERB] [--domain=example.com]\n";
    }
}
