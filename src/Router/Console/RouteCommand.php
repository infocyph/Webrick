<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Console;

use Infocyph\Webrick\Router\Cache\RouteCache;
use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Compile\CompiledRoute;

/**
 * Native, dependency-free CLI handler for route introspection
 * and cache operations (`route:list`, `route:cache`, `route:clear`).
 */
final class RouteCommand
{
    public function __construct(
        private readonly RouteCache $cache  = new RouteCache(),
        private readonly Router     $router = new Router(),
    ) {}

    /* ---------------------------------------------------------------------
       Entry point – invoked from the root `webrick` script
       --------------------------------------------------------------------*/
    /**
     * @param list<string> $argv  raw CLI arguments
     */
    public static function run(array $argv): int
    {
        // pattern:  webrick  route[:action]
        $verb = $argv[1] ?? 'route:list';

        [$cmd, $action] = array_pad(explode(':', $verb, 2), 2, 'list');

        if ($cmd !== 'route') {
            self::out("Unknown command '{$cmd}'.\n", true);
            return 1;
        }

        return (new self())->dispatch($action);
    }

    /* ---------------------------------------------------------------------
       Dispatcher
       --------------------------------------------------------------------*/
    private function dispatch(string $action): int
    {
        return match (strtolower($action)) {
            'cache' => $this->warm(),
            'clear' => $this->clear(),
            'list', '' => $this->dump(),
            default => $this->invalid($action),
        };
    }

    /* ---------------------------------------------------------------------
       Actions
       --------------------------------------------------------------------*/
    private function dump(): int
    {
        $routes = $this->cache->load() ?? $this->router->routes();

        // simple text table (no external libs)
        self::out(str_pad('METHOD', 8)
            . str_pad('URI', 35)
            . str_pad('NAME', 28)
            . "MIDDLEWARE\n");
        self::out(str_repeat('-', 100) . "\n");

        /** @var CompiledRoute $r */
        foreach ($routes as $r) {
            self::out(
                str_pad(implode(',', $r->verbs), 8)
                . str_pad($r->path, 35)
                . str_pad($r->name ?? '—', 28)
                . ($r->middleware ? implode(',', $r->middleware) : '—')
                . "\n"
            );
        }
        return 0;
    }

    private function warm(): int
    {
        self::out("Compiling routes …\n");
        $routes = $this->router->routes();

        self::out("Writing cache …\n");
        $this->cache->store($routes);

        self::out("✓ Route cache warmed.\n");
        return 0;
    }

    private function clear(): int
    {
        self::out("Clearing route cache …\n");
        $this->cache->clear();
        self::out("✓ Cache cleared.\n");
        return 0;
    }

    private function invalid(string $action): int
    {
        self::out("Unknown action '{$action}'. Use route:list, route:cache or route:clear.\n", true);
        return 1;
    }

    /* ---------------------------------------------------------------------
       Helpers
       --------------------------------------------------------------------*/
    private static function out(string $msg, bool $err = false): void
    {
        fwrite($err ? STDERR : STDOUT, $msg);
    }
}
