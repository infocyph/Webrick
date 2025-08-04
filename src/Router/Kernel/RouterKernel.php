<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Exceptions\{
    MethodNotAllowedException,
    RouteNotFoundException
};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Cache\RouteCache;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\{
    MatcherInterface,
    UnifiedMatcher
};
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Cache\CacheItemPoolInterface as Psr6Pool;
use Psr\Log\LoggerInterface;

/**
 * Thin orchestration layer gluing together:
 *   – PSR-6 route-table cache
 *   – the high-speed matcher (default : UnifiedMatcher)
 *   – the dispatcher / IoC invoker
 */
final class RouterKernel
{
    /** @var Closure():list<CompiledRoute> */
    private Closure $compiler;

    public function __construct(
        private MatcherInterface $matcher,
        private readonly Dispatcher $dispatcher,
        private readonly ?RouteCache $cache,
        Closure $compiler,
        private readonly LoggerInterface $log,
        private readonly ?string $routeCacheDir = null,
    ) {
        $this->compiler = $compiler;
        $this->warm();
    }

    /* ───────────────────────── bootstrap helper ───────────────────────── */

    /**
     * Sensible defaults:
     *   – UnifiedMatcher (+ segment-group cache if $routeCacheDir given)
     *   – PSR-6 route-table cache
     */
    public static function boot(
        LoggerInterface $log,
        Psr6Pool $cachePool,
        Closure $compiler,
        MatcherInterface|null $matcher = null,
        ?string $routeCacheDir = null,
        int $cacheTtl = 86_400,
    ): self {
        /* matcher ------------------------------------------------------- */
        if ($matcher === null) {
            $matcher = new UnifiedMatcher();
        }
        if ($routeCacheDir && $matcher instanceof UnifiedMatcher) {
            $matcher->enableCache($routeCacheDir);
        }

        $dispatcher = new Dispatcher(Invoker::shared());
        $cache = new RouteCache($cachePool, ttl: $cacheTtl);

        return new self($matcher, $dispatcher, $cache, $compiler, $log, $routeCacheDir);
    }

    /* ─────────────────────────── request entry ────────────────────────── */

    public function handle(Request $request): Response
    {
        $method = strtoupper($request->getMethod());
        $uri = $request->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';

        try {
            [$route, $vars] = $this->matcher->match($method, $host, $path);
            return $this->dispatcher->dispatch($route, $request, $vars);
        } catch (MethodNotAllowedException $e) {
            return Response::json(
                ['error' => 'Method Not Allowed'],
                405,
                ['Allow' => implode(', ', $e->allowed)],
            );
        } catch (RouteNotFoundException) {
            return Response::json(['error' => 'Not Found'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[router] Uncaught exception during dispatch', ['exception' => $e]);
            return Response::json(['error' => 'Server Error'], 500);
        }
    }

    /* ───────────────────── warm-up / cache prime ─────────────────────── */

    private function warm(): void
    {
        /* 1 ) fetch (or compile) ---------------------------------------- */
        $routes = $this->cache?->remember($this->compiler) ?? ($this->compiler)();
        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        /* 2 ) feed matcher --------------------------------------------- */
        foreach ($routes as $r) {
            $this->matcher->add($r);
        }

        /* 3 ) generate group cache on first boot (UnifiedMatcher only) -- */
        if (
            $this->routeCacheDir &&
            $this->matcher instanceof UnifiedMatcher &&
            !$this->hasGroupFiles($this->routeCacheDir)
        ) {
            $this->matcher->dumpCache($this->routeCacheDir);
            $this->log->info('[router] segment-group cache dumped', ['dir' => $this->routeCacheDir]);
        }

        /* 4 ) telemetry ------------------------------------------------- */
        $this->log->info('[router] route table ready', [
            'count' => \count($routes),
            'cached' => $this->cache !== null,
            'matcher' => $this->matcher::class,
        ]);
    }

    /** crude “does directory already contain any *.php group file?” check */
    private function hasGroupFiles(string $dir): bool
    {
        foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $_) {
            return true;
        }
        return false;
    }

    /* ─────────────────────────── helpers ─────────────────────────────── */

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || \preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }
        $host = \rtrim(\strtolower($raw), '.');

        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = @\idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException('Host contains non-ASCII bytes.');
        }
        return $host;
    }
}
