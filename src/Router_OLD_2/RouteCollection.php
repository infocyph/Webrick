<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router_OLD\Constraints\ParamConstraint;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Static-hash + ordered-dynamic matcher.
 *
 * Hot path is split into helpers (matchStatic / matchDynamic / throw…)
 * so complexity stays readable.
 */
final class RouteCollection
{
    /* ---------------------------------------------------------------------
     |  Storage
     |---------------------------------------------------------------------*/
    /** [domain][verb][path] => Route */
    private array $static  = [];

    /** dynamic route descriptors */
    private array $dynamic = [];

    /** name => Route */
    private array $named   = [];

    /** has anything been added since instantiation? */
    private bool  $dirty   = false;               //  << NEW

    public function __construct(
        private readonly RouteParser $parser      = new RouteParser(),
        private readonly bool        $autoHead    = true,
        private readonly bool        $autoOptions = true,
    ) {
    }

    /* ---------------------------------------------------------------------
     |  Registration
     |---------------------------------------------------------------------*/
    public function add(Route $route): void
    {
        $verb = strtoupper($route->getMethod());
        $dom  = $route->getDomain() ?? '';
        $path = rtrim($route->getPath(), '/') ?: '/';

        if ($route->getName()) {
            $this->named[$route->getName()] = $route;
        }

        /* fast-path for static URIs */
        if (!str_contains($path, '{')) {
            $this->static[$dom][$verb][$path] = $route;
            $this->dirty = true;                  //  << mark dirty
            return;
        }

        /* dynamic = store descriptor */
        $p = $this->parser->parse($path);
        $this->dynamic[] = [
            'domain'     => $dom,
            'method'     => $verb,
            'regex'      => $p['regex'],
            'paramNames' => $p['paramNames'],
            'validators' => $p['validators'],
            'route'      => $route,
        ];
        $this->sortDynamic();
        $this->dirty = true;                      //  << mark dirty
    }

    /** Exposed so the dumper knows whether warming is necessary. */
    public function isDirty(): bool               //  << NEW
    {
        return $this->dirty;
    }

    /** @return array{Route,array<string,string>} */
    public function match(ServerRequestInterface $request): array
    {
        /* ------------------------------------------------------------
         * 1.  Normalise request bits
         * ---------------------------------------------------------- */
        $verb = strtoupper($request->getMethod());
        if ($this->autoHead && $verb === 'HEAD') {
            $verb = 'GET';
        }
        $domain = $request->getUri()->getHost() ?? '';
        $path   = rtrim($request->getUri()->getPath(), '/') ?: '/';

        /* ------------------------------------------------------------
         * 2.  Exact-path lookup
         * ---------------------------------------------------------- */
        if ($hit = $this->matchStatic($domain, $verb, $path)) {
            return $hit;
        }

        /* ------------------------------------------------------------
         * 3.  Dynamic pattern matching
         * ---------------------------------------------------------- */
        [$route, $params, $allowed, $seen] = $this->matchDynamic($domain, $verb, $path);
        if ($route) {
            return [$route, $params];
        }

        /* ------------------------------------------------------------
         * 4.  Fallback errors (405 / 404)
         * ---------------------------------------------------------- */
        $this->throwForVerbOrPath($verb, $path, $allowed, $seen);
    }

    /* ---------------------------------------------------------------------
     |  Public helpers
     |---------------------------------------------------------------------*/
    /** @return array<string,Route> */
    public function named(): array
    {
        return $this->named;
    }

    /* =====================================================================
     |  Internal helpers – unchanged
     |=====================================================================*/
    private function matchStatic(string $domain, string $verb, string $path): ?array
    {
        $bucket = $this->static[$domain] ?? $this->static[''] ?? [];
        return $bucket[$verb][$path] ?? null ? [$bucket[$verb][$path], []] : null;
    }

    private function matchDynamic(string $domain, string $verb, string $path): array
    {
        $allowed  = [];
        $seenPath = false;

        foreach ($this->dynamic as $d) {
            if ($d['domain'] !== '' && $d['domain'] !== $domain) {
                continue;
            }
            if (!preg_match($d['regex'], $path, $m)) {
                continue;
            }

            $seenPath = true;

            foreach ($d['validators'] as $name => $fn) {
                if (!ParamConstraint::$fn($m[$name])) {
                    continue 2;
                }
            }

            if ($d['method'] !== $verb) {
                $allowed[$d['method']] = true;
                continue;
            }

            $params = array_intersect_key($m, array_flip($d['paramNames']));
            return [$d['route'], $params, [], true];
        }

        return [null, [], array_keys($allowed), $seenPath];
    }

    private function throwForVerbOrPath(string $verb, string $path, array $allowed, bool $seenPath): never
    {
        if ($this->autoOptions && $verb === 'OPTIONS') {
            $allowed = array_merge($allowed, $this->findStaticVerbsForPath($path));
            sort($allowed);
            throw new MethodNotAllowedException($verb, $path, $allowed);
        }

        if ($seenPath) {
            $allowed = array_merge($allowed, $this->findStaticVerbsForPath($path));
            sort($allowed);
            throw new MethodNotAllowedException($verb, $path, $allowed);
        }

        throw new RouteNotFoundException($verb, $path);
    }

    private function findStaticVerbsForPath(string $path): array
    {
        $verbs = [];
        foreach ($this->static as $byVerb) {
            foreach ($byVerb as $v => $paths) {
                if (isset($paths[$path])) {
                    $verbs[] = $v;
                }
            }
        }
        return $verbs;
    }

    private function sortDynamic(): void
    {
        usort(
            $this->dynamic,
            fn ($a, $b) =>
            ($c = count($a['paramNames']) <=> count($b['paramNames']))
                ?: strlen($b['regex']) <=> strlen($a['regex'])
        );
    }
}
