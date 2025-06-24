<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dev;

use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Compiler\RouteDumper;

/**
 * Cross-platform, polling-based cache watcher.
 *
 * • Monitors a list of *globs* (e.g.  routes/*.php , app/Http/Controllers)
 * • When any file mtime changes ⇒ clears + rewrites the RouteDumper cache.
 * • Intended for dev environments where inotify is unavailable.
 */
final class RouteCacheWatcher
{
    /** @var array<string,int>  last seen mtime */
    private array  $last     = [];
    private int    $interval = 1;   // seconds between scans
    private RouteDumper $dumper;

    /**
     * @param list<string> $globs  file globs to monitor
     * @param int          $interval seconds between scans
     */
    public function __construct(
        private array $globs,
        int $interval = 1,
        ?RouteDumper $dumper = null
    ) {
        $this->interval = max(1, $interval);
        $this->dumper   = $dumper ?? new RouteDumper();
    }

    /** Blocking loop – call from a CLI dev-server */
    public function run(callable $compiler): never
    {
        $router = Router::boot(useCache:false);   // boot fresh each loop

        $this->dumper->load($router, $compiler);  // warm at start

        while (true) {
            if ($this->changed()) {
                echo "🔄  Route files changed – rebuilding cache…\n";
                $this->dumper->clear();
                $router = Router::boot(useCache:false);
                $this->dumper->load($router, $compiler);
            }
            sleep($this->interval);
        }
    }

    /* ------------------------------------------------------------ */

    private function changed(): bool
    {
        $dirty = false;

        foreach ($this->globs as $glob) {
            foreach (glob($glob) ?: [] as $file) {
                $t = filemtime($file) ?: 0;
                if (!isset($this->last[$file]) || $t > $this->last[$file]) {
                    $dirty = true;
                }
                $this->last[$file] = $t;
            }
        }
        return $dirty;
    }
}
