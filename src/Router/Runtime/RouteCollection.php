<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;

/**
 * High-performance container for compiled routes.
 *
 *  • Static routes → O(1) array lookup.
 *  • Dynamic routes → pre-compiled regex list ordered by specificity.
 *  • Domain & sub-domain support.
 *  • Freezes itself after boot for thread-safety.
 */
final class RouteCollection
{
    /** [method][host][path] => Route (static) */
    private array $static = [];

    /** [method][] = ['regex'=>string,'vars'=>string[],'route'=>Route] */
    private array $dynamic = [];

    /** name => Route */
    private array $names = [];

    private bool $frozen = false;

    /* ------------------------------------------------------------------ */
    /** @internal called by Registrar / AttributeScanner */
    public function add(Route $route, array $compiled): void
    {
        $this->assertMutable();

        $host   = $route->getDomain() ?? '';
        $method = $route->getMethod();

        if ($route->getName()) {
            $this->names[$route->getName()] = $route;
        }

        if ($compiled['kind'] === 'static') {
            $this->static[$method][$host][$compiled['path']] = $route;
            return;
        }

        // dynamic
        $this->dynamic[$method][] = [
            'regex' => $compiled['regex'],
            'vars'  => $compiled['vars'],
            'route' => $route,
        ];
    }

    /** Sort & lock for runtime */
    public function freeze(): void
    {
        if ($this->frozen) { return; }
        foreach ($this->dynamic as &$list) {
            usort(
                $list,
                fn ($a, $b) =>
                (count($a['vars']) <=> count($b['vars']))
                    ?: strlen($b['regex']) <=> strlen($a['regex'])
            );
        }
        $this->frozen = true;
    }

    /* ------------------------------------------------------------------ */
    /** @return array{Route,array<string,string>} Route + path params */
    public function match(string $method, string $host, string $path): array
    {
        /* 1️⃣  Static lookup (host-specific, then global) */
        $bucket = ($this->static[$method][$host] ?? [])
            + ($this->static[$method][''] ?? []);

        if (isset($bucket[$path])) {
            return [$bucket[$path], []];
        }

        /* 2️⃣  Dynamic regex list */
        $allowed = [];
        $seen    = false;

        foreach ($this->dynamic[$method] ?? [] as $d) {
            if (!preg_match($d['regex'], $path, $m)) { continue; }
            $seen = true;

            $params = array_intersect_key($m, array_flip($d['vars']));
            return [$d['route'], $params];
        }

        /* 3️⃣  Fallbacks (405 / 404) */
        foreach ($this->dynamic + $this->static as $verb => $routes) {
            if ($verb === $method) { continue; }

            // check static
            if (isset($routes[$host][$path]) || isset($routes[''][$path])) {
                $allowed[] = $verb;
                continue;
            }

            // check dynamic
            foreach ($routes as $r) {
                $rx = $r['regex'] ?? null;
                if ($rx && preg_match($rx, $path)) { $allowed[] = $verb; break; }
            }
        }

        if ($allowed) {
            throw new MethodNotAllowedException($method, $path, array_values(array_unique($allowed)));
        }

        if ($seen) {
            throw new MethodNotAllowedException($method, $path, []);
        }

        throw new RouteNotFoundException($method, $path);
    }

    /* ------------------------------------------------------------------ */
    public function named(string $name): Route
    {
        return $this->names[$name]
            ?? throw new \RuntimeException("Unknown route name: {$name}");
    }

    public function all(): array
    {
        /** @var list<Route> */
        $out = [];

        foreach ($this->static as $byHost) {
            foreach ($byHost as $byPath) {
                foreach ($byPath as $route) { $out[] = $route; }
            }
        }
        foreach ($this->dynamic as $dyn) {
            foreach ($dyn as $e) { $out[] = $e['route']; }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('RouteCollection is frozen – no further mutation allowed.');
        }
    }
}
