<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Cache\CompiledRouteCache;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\{MatcherInterface, UnifiedMatcher};
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Cache\CacheItemPoolInterface as Psr6Pool;
use Psr\Log\LoggerInterface;

/**
 * ┌─ kernel glue ──────────────────────────────────────────────────────────┐
 * │  • Compiled-route table cache (PSR-6/16)                               │
 * │  • matcher warm-up + hot-path bucket cache (UnifiedMatcher)            │
 * │  • PSR-7 dispatch via IoC-invoker                                      │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * @psalm-type RouteList = list<CompiledRoute>
 */
final class RouterKernel
{
    /** @var Closure():RouteList */
    private Closure $compiler;

    public function __construct(
        private MatcherInterface $matcher,
        private readonly Dispatcher $dispatcher,
        private readonly ?CompiledRouteCache $cache,
        Closure $compiler,
        private readonly LoggerInterface $log,
        private readonly ?string $routeCacheDir = null,
    ) {
        $this->compiler = $compiler;
        $this->warm();
    }

    /* --------------------------------------------------------------------- *
     * Factory
     * --------------------------------------------------------------------- */
    public static function boot(
        LoggerInterface $log,
        Psr6Pool $cachePool,
        Closure $compiler,
        ?MatcherInterface $matcher = null,
        ?string $routeCacheDir = null,
    ): self {
        return new self(
            matcher: $matcher,
            dispatcher: new Dispatcher(Invoker::shared()),
            cache: new CompiledRouteCache($cachePool),
            compiler: $compiler,
            log: $log,
            routeCacheDir: $routeCacheDir,
        );
    }

    /* --------------------------------------------------------------------- *
     * Request entry-point
     * --------------------------------------------------------------------- */
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

    /* --------------------------------------------------------------------- *
     * Warm-up
     * --------------------------------------------------------------------- */
    private function warm(): void
    {
        /* ① fetch or (re)compile ---------------------------------------- */
        $routes = $this->cache?->remember($this->compiler) ?? ($this->compiler)();
        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        /* ② prime matcher ---------------------------------------------- */
        foreach ($routes as $r) {
            $this->matcher->add($r);
        }

        /* ③ build segment-group cache (first boot only) ---------------- */
        if (
            $this->routeCacheDir &&
            $this->matcher instanceof UnifiedMatcher &&
            !$this->matcher->hasCache()                          // nothing dumped yet
        ) {
            $this->matcher->dumpCache($this->routeCacheDir);     // file or APCu
            $this->log->info('[router] segment-group cache dumped', [
                'mode' => $this->matcher->getCacheMode(),
                'target' => $this->routeCacheDir,
            ]);
        }

        /* ④ telemetry -------------------------------------------------- */
        $this->log->info('[router] route table ready', [
            'count' => \count($routes),
            'cached' => $this->cache !== null,
            'matcher' => $this->matcher::class,
        ]);
    }

    /* --------------------------------------------------------------------- *
     * Helpers
     * --------------------------------------------------------------------- */
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
