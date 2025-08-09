<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\{MatcherInterface, UnifiedMatcher};
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Psr\Log\LoggerInterface;

/**
 * Glues together compiler → matcher → dispatcher.
 *
 * Supports both UnifiedMatcher (dir cache) and MergedMatcher (single-file cache)
 * transparently via duck-typed `enableCache()` / `finalize()`.
 */
final class RouterKernel
{
    /** @var Closure():list<CompiledRoute> */
    private Closure $compiler;

    public function __construct(
        private MatcherInterface $matcher,
        private readonly Dispatcher $dispatcher,
        Closure $compiler,
        private readonly LoggerInterface $log,
    ) {
        $this->compiler = $compiler;
        $this->warm();
    }

    /*──────────────── bootstrap helper ────────────────*/

    /**
     * @param string|null $routeCache Dir for UnifiedMatcher **or** file for MergedMatcher
     */
    public static function boot(
        LoggerInterface $log,
        Closure $compiler,
        MatcherInterface $matcher,
        ?string $routeCache = null,
    ): self {
        /* 2) enable cache if requested & matcher supports it ----------- */
        if ($routeCache) {
            $matcher->enableCache($routeCache);
        }

        /* 3) dispatcher ------------------------------------------------ */
        $dispatcher = new Dispatcher(Invoker::shared());

        return new self($matcher, $dispatcher, $compiler, $log);
    }

    /*──────────────── request entry ───────────────────*/

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
            $this->log->error('[router] uncaught exception', ['exception' => $e]);
            return Response::json(['error' => 'Server Error'], 500);
        }
    }

    /*──────────────── warm-up / cache prime ──────────*/

    private function warm(): void
    {
        /* 1) compile --------------------------------------------------- */
        $routes = ($this->compiler)();
        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        /* 2) feed matcher --------------------------------------------- */
        foreach ($routes as $r) {
            $this->matcher->add($r);
        }

        /* 3) finalize (if matcher supports it) ------------------------ */
        if (\method_exists($this->matcher, 'finalize')) {
            $this->matcher->finalize();
        }

        /* 4) telemetry ------------------------------------------------ */
        $this->log->info('[router] route table ready', [
            'count' => count($routes),
            'matcher' => $this->matcher::class,
            'cache' => method_exists($this->matcher, 'enableCache'),
        ]);
    }

    /*──────────────── helpers ────────────────────────*/

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }
        $host = rtrim(strtolower($raw), '.');

        if (function_exists('idn_to_ascii') && !str_contains($host, 'xn--')) {
            $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (!preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException('Host contains non-ASCII bytes.');
        }
        return $host;
    }
}
