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
use Infocyph\Webrick\Router\Matching\{DomainMatcher, MatcherInterface};
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Cache\CacheItemPoolInterface as Psr6Pool;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RouterKernel
{
    /** @var Closure():list<CompiledRoute> */
    private Closure $compiler;

    public function __construct(
        private readonly MatcherInterface $matcher,
        private readonly Dispatcher $dispatcher,
        private readonly ?RouteCache $cache,
        Closure $compiler,
        private readonly LoggerInterface $log,
    ) {
        $this->compiler = $compiler;
        $this->warm();
    }

    /* ───────────────────────── bootstrap helper ───────────────────────── */

    /**
     * Convenience factory that wires sensible defaults while still allowing
     * you to swap the matcher for a `FastRegexMatcher` (or any other) later.
     *
     * @param Closure():list<CompiledRoute> $compiler Callback that returns the
     *        *current* compiled route table (e.g. `fn() => $builder->compile()`).
     */
    public static function boot(
        LoggerInterface $log,
        Psr6Pool $cachePool,
        Closure $compiler,
        MatcherInterface|null $matcher = null,
        /* DomainMatcher tuning (ignored if custom matcher supplied) */
        bool $useRadix = true,
        int $promoteAfter = 2_048,
        /* cache */
        int $cacheTtl = 86_400,  // 24 h
    ): self {
        // 1️⃣ matcher
        $matcher ??= new DomainMatcher($useRadix, $promoteAfter);

        // 2️⃣ dispatcher (shared Invoker ⟶ global single-instance)
        $dispatcher = new Dispatcher(Invoker::shared());

        // 3️⃣ route-cache (optional, but cheap)
        $cache = new RouteCache($cachePool, ttl: $cacheTtl);

        return new self($matcher, $dispatcher, $cache, $compiler, $log);
    }

    /* ─────────────────────────── request entry ────────────────────────── */

    public function handle(Request $request): Response
    {
        $method = strtoupper($request->getMethod());
        $uri = $request->getUri();

        $host = strtolower($uri->getHost());
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
        }
    }

    /* ─────────────────────── boot-time warm-up ────────────────────────── */

    private function warm(): void
    {
        // 1. fetch from cache or (re)compile
        $routes = $this->cache?->remember($this->compiler)
            ?? ($this->compiler)();                 // cache disabled

        if ($routes === []) {
            throw new RuntimeException('Route compiler produced an empty table.');
        }

        // 2. load routes into the matcher (noop for FastRegexMatcher)
        foreach ($routes as $route) {
            $this->matcher->add($route);
        }

        $this->log->info(
            '[router] table loaded',
            [
                'count' => \count($routes),
                'cached' => $this->cache !== null,
                'matcher' => $this->matcher::class,
            ],
        );
    }
}
