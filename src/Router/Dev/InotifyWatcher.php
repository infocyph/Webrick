<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dev;

use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Compiler\RouteDumper;

/**
 * High-performance watcher using the inotify extension
 * (Linux & macOS fsevents via inotify-compat brew formula).
 *
 * Falls back to RouteCacheWatcher if the extension is absent.
 */
final class InotifyWatcher
{
    /** @param list<string> $paths directories or files to watch (recursive) */
    public static function run(array $paths, callable $compiler): void
    {
        if (!extension_loaded('inotify')) {
            echo "⚠️  inotify not available – falling back to polling watcher.\n";
            (new RouteCacheWatcher($paths))->run($compiler);
            return;
        }

        $fd  = inotify_init();
        stream_set_blocking($fd, false);

        /** @var array<int,string> $map watchDescriptor→path */
        $map = [];
        foreach (self::collect($paths) as $dir) {
            $wd         = inotify_add_watch($fd, $dir, IN_MODIFY | IN_CREATE | IN_DELETE | IN_MOVED_TO | IN_MOVED_FROM);
            $map[$wd]   = $dir;
        }

        $router = Router::boot(useCache:false);
        $dumper = new RouteDumper();
        $dumper->load($router, $compiler);

        echo "👀  Watching routes… Press Ctrl-C to stop.\n";

        while (true) {
            $events = inotify_read($fd);
            if ($events !== false && $events !== []) {
                echo "🔄  Change detected – rebuilding cache…\n";
                $dumper->clear();
                $router = Router::boot(useCache:false);
                $dumper->load($router, $compiler);
            }
            // throttle CPU
            usleep(250_000);
        }
    }

    /** @return list<string> directories (unique) */
    private static function collect(array $paths): array
    {
        $dirs = [];
        foreach ($paths as $p) {
            if (is_dir($p)) {
                $dirs[] = $p;
            } elseif (is_file($p)) {
                $dirs[] = dirname($p);
            } elseif (str_contains($p, '*')) {
                foreach (glob($p) ?: [] as $g) {
                    $dirs[] = is_dir($g) ? $g : dirname($g);
                }
            }
        }
        return array_values(array_unique($dirs));
    }
}
