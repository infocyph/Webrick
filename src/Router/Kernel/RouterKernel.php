<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\DI\Support\TraceLevel;
use Infocyph\Webrick\Router\Compile\FastRegexCompiler;
use Infocyph\Webrick\Exceptions\{
    MethodNotAllowedException,
    RouteNotFoundException
};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Cache\RouteCache;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\{
    MergedMatcher,
    FastRegexMatcher,
    MatcherInterface
};
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Cache\CacheItemPoolInterface as Psr6Pool;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

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
        private readonly ?string $regexDump = null,
    ) {
        $this->compiler = $compiler;
        $this->doWarm();
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
        int $cacheTtl = 86_400,   // 24 h
    ): self {
        /* ① prefer a pre-generated fast-regex table when provided */
        if ($matcher === null && $regexDump && \is_file($regexDump)) {
            try {
                $matcher = new FastRegexMatcher($regexDump);
            } catch (UnexpectedValueException $e) {
                // CRC mismatch → fall back gracefully; warm() will re-dump
                $log->warning('[router] Stale fast-regex dump – using merged matcher', [
                    'file' => $regexDump,
                    'error' => $e->getMessage(),
                ]);
                $matcher = null;                       // continue to fallback below
            }
        }

        /* ② fallback to the new merged matcher */
        $matcher ??= new MergedMatcher();
        $dispatcher = new Dispatcher(Invoker::shared());
        $cache = new RouteCache($cachePool, ttl: $cacheTtl);

        return new self($matcher, $dispatcher, $cache, $compiler, $log, $regexDump);
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
        } catch (UnexpectedValueException $e) {
            // CRC mismatch at runtime
            $this->log->warning('[router] Stale fast-regex dump detected – regenerating', [
                    'error' => $e->getMessage(),
                ]);

            // purge the bad dump so warm() can recreate it
            if ($this->regexDump && \is_file($this->regexDump)) {
                @unlink($this->regexDump);
            }

            // swap matcher → re-warm → try once more
            $this->matcher = new MergedMatcher();
            $this->doWarm();

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
            // Never leak internal details in production.
            // 1️⃣  Log the full exception for later inspection …
            $this->log->error(
                '[router] Uncaught exception during dispatch',
                ['exception' => $e]        // Monolog will format the stack-trace
            );

            // 2️⃣  …and send a terse, generic JSON response to the client.
            return Response::json(['error' => 'Server Error'], 500);
        }
    }

    /* ─────────────────────── boot-time warm-up ────────────────────────── */

    /**
     * Load (or compile) the route-table, wire it into the matcher and—when
     * appropriate—emit a Fast-Regex dump for future, ultra-fast boots.
     *
     * @throws \RuntimeException When the compiler returns an empty set.
     */
    private function doWarm(): void
    {
        /* 1) fetch from cache or build fresh -------------------------------- */
        $routes = $this->cache?->remember($this->compiler)      // PSR-6/16 path
            ?? ($this->compiler)();                             // cache disabled / miss

        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        /* 2) prime the in-memory matcher (fast-regex is read-only) ---------- */
        if (!$this->matcher instanceof FastRegexMatcher) {
            foreach ($routes as $route) {
                $this->matcher->add($route);
            }
        }

        /* 3) emit fast-regex dump for future boots (first-run only) --------- */
        if (
            $this->matcher instanceof MergedMatcher
            && $this->regexDump !== ''
            && (!\is_file($this->regexDump) || \filesize($this->regexDump) === 0)
        ) {
            FastRegexCompiler::dump($routes, $this->regexDump);
            $this->log->info('[router] fast-regex table dumped', ['file' => $this->regexDump]);
        }

        /* 4) telemetry ------------------------------------------------------ */
        $this->log->info(
            '[router] table loaded',
            [
                'count' => \count($routes),
                'cached' => $this->cache !== null,
                'matcher' => $this->matcher::class,
            ],
        );
    }

    public function getRegexDumpPath(): ?string
    {
        return $this->regexDump;
    }
    public function warm(): void
    {
        $this->doWarm();
    }

    /* ─────────────────────────── helpers ──────────────────────────────── */

    /**
     * Minimal copy of the previous `Utils::normaliseHost()` so we keep the
     * exact same sanity checks without a hard dependency on Utils.
     */
    private static function normaliseHost(string $raw): string
    {
        // ① basic sanity
        if ($raw === '' || \preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }

        // ② trim trailing “.” and lowercase
        $host = \rtrim(\strtolower($raw), '.');

        // ③ IDN → ASCII if intl ext. available
        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = @\idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Invalid IDN host name.');
            }
            $host = $ascii;
        }

        // ④ final ASCII-only guard
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException('Host contains non-ASCII bytes.');
        }

        return $host;
    }
}
