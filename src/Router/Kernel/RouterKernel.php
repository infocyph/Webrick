<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Router\Compile\FastRegexCompiler;
use Infocyph\Webrick\Exceptions\{
    MethodNotAllowedException,
    RouteNotFoundException
};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Cache\RouteCache;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\{DomainMatcher, FastRegexMatcher, MatcherInterface};
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
        ?string $regexDump = null,
        /* DomainMatcher tuning (ignored if custom matcher supplied) */
        bool $useRadix = true,
        int $promoteAfter = 1_024,
        /* cache */
        int $cacheTtl = 86_400,  // 24 h
    ): self {
        if ($matcher === null && $regexDump && \is_file($regexDump)) {
            $matcher = new FastRegexMatcher($regexDump);
        }

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

    /**
     * Load (or compile) the route-table, wire it into the matcher and—when
     * appropriate—emit a Fast-Regex dump for future, ultra-fast boots.
     *
     * @throws \RuntimeException When the compiler returns an empty set.
     */
    private function warm(): void
    {
        /* --------------------------------------------------------------
         * 1. fetch from cache -or- build fresh
         * ------------------------------------------------------------ */
        $routes = $this->cache?->remember($this->compiler)      // PSR-6/16 path
            ?? ($this->compiler)();                             // cache disabled / miss

        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        /* --------------------------------------------------------------
         * 2. prime the in-memory matcher
         *    (FastRegexMatcher is read-only → skip)
         * ------------------------------------------------------------ */
        if (!$this->matcher instanceof FastRegexMatcher) {
            foreach ($routes as $route) {
                $this->matcher->add($route);
            }
        }

        /* --------------------------------------------------------------
         * 3. on-disk dump for next boots
         *    – only when DomainMatcher is in use
         *    – only when a dump path was supplied
         *    – never overwrite an existing, non-empty dump
         * ------------------------------------------------------------ */
        if (
            $this->matcher instanceof \Infocyph\Webrick\Router\Matching\DomainMatcher
            && isset($this->regexDump) && $this->regexDump !== ''
            && (!\is_file($this->regexDump) || \filesize($this->regexDump) === 0)
        ) {
            FastRegexCompiler::dump($routes, $this->regexDump);
            $this->log->info('[router] fast-regex table dumped', ['file' => $this->regexDump]);
        }

        /* --------------------------------------------------------------
         * 4. telemetry
         * ------------------------------------------------------------ */
        $this->log->info(
            '[router] table loaded',
            [
                'count'   => \count($routes),
                'cached'  => $this->cache !== null,
                'matcher' => $this->matcher::class,
            ],
        );
    }

}
