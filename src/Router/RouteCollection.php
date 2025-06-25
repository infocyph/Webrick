<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Constraints\ParamConstraint;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Static-hash + ordered-dynamic matcher.
 */
final class RouteCollection
{
    /** [domain][verb][path] => Route */
    private array $static = [];

    /** dynamic route records */
    private array $dynamic = [];

    /** name => Route */
    private array $named = [];

    public function __construct(
        private readonly RouteParser $parser = new RouteParser(),
        private readonly bool $autoHead = true,
        private readonly bool $autoOptions = true,
    ) {
    }

    public function add(Route $route): void
    {
        $v   = strtoupper($route->getMethod());
        $d   = $route->getDomain() ?? '';
        $p   = rtrim($route->getPath(), '/') ?: '/';

        if ($route->getName()) {
            $this->named[$route->getName()] = $route;
        }

        if (!str_contains($p, '{')) {
            $this->static[$d][$v][$p] = $route;
            return;
        }

        $parsed = $this->parser->parse($p);
        $this->dynamic[] = [
            'domain'     => $d,
            'method'     => $v,
            'regex'      => $parsed['regex'],
            'paramNames' => $parsed['paramNames'],
            'validators' => $parsed['validators'],
            'route'      => $route,
        ];
        $this->sortDynamic();
    }

    /** @return array{Route,array<string,string>} */
    public function match(ServerRequestInterface $req): array
    {
        $verb   = strtoupper($req->getMethod());
        $verb   = ($this->autoHead && $verb === 'HEAD') ? 'GET' : $verb;
        $uri    = $req->getUri();
        $d      = $uri->getHost() ?? '';
        $path   = rtrim($uri->getPath(), '/') ?: '/';

        /* ---- static hash ---- */
        $bucket = $this->static[$d] ?? $this->static[''] ?? [];
        if (isset($bucket[$verb][$path])) {
            return [$bucket[$verb][$path], []];
        }

        /* ---- dynamic list ---- */
        $seenPath = false;
        $allowed = [];

        foreach ($this->dynamic as $rec) {
            if ($rec['domain'] !== '' && $rec['domain'] !== $d) {
                continue;
            }
            if (!preg_match($rec['regex'], $path, $m)) {
                continue;
            }

            $seenPath = true;

            /* validator loop */
            foreach ($rec['validators'] as $name => $fn) {
                if (!ParamConstraint::$fn($m[$name])) {
                    continue 2;           // try next route
                }
            }

            if ($rec['method'] !== $verb) {
                $allowed[$rec['method']] = true;
                continue;
            }

            $params = array_intersect_key($m, array_flip($rec['paramNames']));
            return [$rec['route'], $params];
        }

        if ($this->autoOptions && $verb === 'OPTIONS') {
            $verbs = array_merge($allowed, array_flip($this->findStaticVerbs($d, $path)));
            throw new MethodNotAllowedException(implode(', ', array_keys($verbs)));
        }

        if ($seenPath) {
            $verbs = array_merge($allowed, array_flip($this->findStaticVerbs($d, $path)));
            throw new MethodNotAllowedException(implode(', ', array_keys($verbs)));
        }

        throw new RouteNotFoundException("No route for {$verb} {$path}");
    }

    /** @return array<string,Route> */
    public function named(): array
    {
        return $this->named;
    }

    /* ------------------------------ helpers ------------------------------- */

    private function findStaticVerbs(string $d, string $p): array
    {
        $verbs = [];
        $bucket = $this->static[$d] ?? $this->static[''] ?? [];
        foreach ($bucket as $v => $paths) {
            if (isset($paths[$p])) {
                $verbs[] = $v;
            }
        }
        return $verbs;
    }

    private function sortDynamic(): void
    {
        usort(
            $this->dynamic,
            fn ($a, $b) =>
            ($cmp = count($a['paramNames']) <=> count($b['paramNames']))
                ?: strlen($b['regex']) <=> strlen($a['regex'])
        );
    }
}
