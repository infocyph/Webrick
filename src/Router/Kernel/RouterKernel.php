<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\{MatcherInterface};
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
        if ($routeCache) {
            $matcher->enableCache($routeCache);
        }

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
        // If matcher advertises a hot cache, skip compiling & adding entirely.
        $canBootFromCache = \method_exists($this->matcher, 'canBootFromCache')
            && (bool) $this->matcher->canBootFromCache();

        if ($canBootFromCache) {
            if (\method_exists($this->matcher, 'finalize')) {
                // Harmless no-op if finalize only dumps when cache is missing.
                $this->matcher->finalize();
            }
            $this->log->info('[router] route table ready (hot cache)', [
                'count'   => null,
                'matcher' => $this->matcher::class,
                'cache'   => true,
                'mode'    => 'cache',
            ]);
            return;
        }

        // Cold start: compile, feed matcher, then finalize (possibly writing cache).
        $routes = ($this->compiler)();
        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        foreach ($routes as $r) {
            $this->matcher->add($r);
        }

        if (\method_exists($this->matcher, 'finalize')) {
            $this->matcher->finalize();
        }

        $this->log->info('[router] route table ready', [
            'count'   => count($routes),
            'matcher' => $this->matcher::class,
            'cache'   => \method_exists($this->matcher, 'enableCache'),
            'mode'    => 'compiled',
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
