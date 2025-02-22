<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Enhanced RouteCollection with:
 * - Hash-based indexing for static routes
 * - Sorting dynamic routes by specificity
 * - Existing caching approach (dump to .php)
 */
class RouteCollection
{
    /**
     * For exact, static routes: we store them in a hash of
     * [domain][method][path] => RouteInterface
     */
    private array $staticRoutes = [];

    /**
     * For dynamic routes: an array of:
     * [
     *   'domain' => string,
     *   'method' => string,
     *   'pattern'=> string,
     *   'paramNames' => string[],
     *   'route' => RouteInterface
     * ]
     */
    private array $dynamicRoutes = [];

    /**
     * Named routes => route
     */
    private array $namedRoutes = [];

    private ?string $cacheFile = null;
    private bool $isCached = false;

    public function __construct(
        private readonly RouteParser $parser,
        private readonly bool $autoHead    = true,
        private readonly bool $autoOptions = true
    ) {
    }

    public function addRoute(RouteInterface $route): void
    {
        $method = strtoupper($route->getMethod());
        $path   = $route->getPath();
        $domain = $route->getDomain() ?? '';
        $name   = $route->getName();

        // Named routes
        if ($name) {
            $this->namedRoutes[$name] = $route;
        }

        // If placeholders exist => dynamic
        if (str_contains($path, '{')) {
            $parsed = $this->parser->parse($path);
            $this->dynamicRoutes[] = [
                'domain'     => $domain,
                'method'     => $method,
                'pattern'    => $parsed['pattern'],
                'paramNames' => $parsed['paramNames'],
                'route'      => $route,
            ];
            $this->sortDynamicRoutes();
        } else {
            // static => store in a hash
            $this->staticRoutes[$domain][$method][$path] = $route;
        }
    }

    public function match(ServerRequestInterface $request): array
    {
        $reqMethod = strtoupper($request->getMethod());
        $reqUri    = $request->getUri();
        $path      = $reqUri->getPath();
        $domain    = $reqUri->getHost() ?? '';

        // auto-HEAD => treat HEAD as GET
        $matchMethod = ($this->autoHead && $reqMethod === 'HEAD') ? 'GET' : $reqMethod;

        // 1) Check static
        // domain fallback: if domain not found, fallback to ''
        $staticDomain = $this->staticRoutes[$domain] ?? $this->staticRoutes[''] ?? [];
        // direct hash lookup
        if (isset($staticDomain[$matchMethod][$path])) {
            return [$staticDomain[$matchMethod][$path], []];
        }

        // 2) Check dynamic
        $foundAnyForPath = false;
        $allowedMethods  = [];

        foreach ($this->dynamicRoutes as $data) {
            if ($data['domain'] !== '' && $data['domain'] !== $domain) {
                // mismatch
                continue;
            }
            if (preg_match($data['pattern'], $path, $matches)) {
                $foundAnyForPath = true;
                if ($data['method'] === $matchMethod) {
                    $params = [];
                    foreach ($data['paramNames'] as $p) {
                        $params[$p] = $matches[$p] ?? null;
                    }
                    return [$data['route'], $params];
                }
                // else track allowed
                $allowedMethods[$data['method']] = true;
            }
        }

        // 3) OPTIONS => list allowed
        if ($this->autoOptions && $reqMethod === 'OPTIONS') {
            $allowed = array_merge(
                $this->findStaticMethodsForPath($domain, $path),
                array_keys($allowedMethods)
            );
            $allowed = array_unique($allowed);
            sort($allowed);
            throw new MethodNotAllowedException('OPTIONS: Allowed Methods: ' . implode(', ', $allowed));
        }

        if ($foundAnyForPath) {
            // 405
            $m = array_merge($this->findStaticMethodsForPath($domain, $path), array_keys($allowedMethods));
            $m = array_unique($m);
            sort($m);
            throw new MethodNotAllowedException('Allowed Methods: ' . implode(', ', $m));
        }

        // 404
        throw new RouteNotFoundException("No route found for {$reqMethod} {$path} @ {$domain}");
    }

    private function findStaticMethodsForPath(string $domain, string $path): array
    {
        $res = [];
        $staticDomain = $this->staticRoutes[$domain] ?? $this->staticRoutes[''] ?? [];
        foreach ($staticDomain as $m => $paths) {
            if (isset($paths[$path])) {
                $res[] = $m;
            }
        }
        return $res;
    }

    /**
     * Sort dynamic routes by specificity:
     *  - Fewer placeholders => more specific => earlier in the array
     *  - Tie-breaker => longer pattern => earlier
     */
    private function sortDynamicRoutes(): void
    {
        usort($this->dynamicRoutes, function ($a, $b) {
            $aCount = count($a['paramNames']);
            $bCount = count($b['paramNames']);
            if ($aCount === $bCount) {
                return strlen((string) $b['pattern']) <=> strlen((string) $a['pattern']);
            }
            return $aCount <=> $bCount;
        });
    }

    /**
     * More robust URL generation:
     *  - Check that all placeholders are provided
     *  - If not, throw exception
     *  - Possibly add query string support
     */
    public function urlFor(
        string $routeName,
        array $params = [],
        bool $absolute = false,
        string $scheme = 'https',
        array $query = []
    ): string {
        if (!isset($this->namedRoutes[$routeName])) {
            throw new \RuntimeException("No named route: {$routeName}");
        }
        $route  = $this->namedRoutes[$routeName];
        $domain = $route->getDomain() ?? '';
        $rawPath = $route->getPath();

        // Replace placeholders
        $pattern = '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]+)?\}/';
        $unused  = [];
        $replacedPath = preg_replace_callback($pattern, function ($m) use (&$params, &$unused) {
            $key = $m[1];
            if (!isset($params[$key])) {
                // Missing param => error
                throw new \RuntimeException("Missing parameter '{$key}' for route");
            }
            $val = $params[$key];
            unset($params[$key]);
            return $val;
        }, (string) $rawPath);

        // If any leftover required placeholders => error
        if (preg_match_all($pattern, (string) $replacedPath, $stillMatches) && !empty($stillMatches[1])) {
            throw new \RuntimeException("Not all placeholders replaced in routeName={$routeName}");
        }

        // Build final URL
        $url = $replacedPath;
        if (!empty($query)) {
            $qs = http_build_query($query);
            $url .= '?' . $qs;
        }
        if ($absolute && $domain !== '') {
            $url = $scheme . '://' . $domain . $url;
        }
        return $url;
    }

    // -------------------------------------
    // Enhanced caching for high traffic
    // -------------------------------------
    public function enableCache(string $cacheFile): void
    {
        $this->cacheFile = $cacheFile;
        $this->loadCache();
    }

    private function loadCache(): void
    {
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            $data = include $this->cacheFile;
            if (is_array($data)) {
                $this->staticRoutes  = $data['staticRoutes'] ?? [];
                $this->dynamicRoutes = $data['dynamicRoutes'] ?? [];
                $this->namedRoutes   = $data['namedRoutes'] ?? [];
                $this->isCached      = true;
            }
        }
    }

    public function storeCache(): void
    {
        if (!$this->cacheFile) {
            return;
        }
        $export = var_export([
            'staticRoutes'  => $this->staticRoutes,
            'dynamicRoutes' => $this->dynamicRoutes,
            'namedRoutes'   => $this->namedRoutes,
        ], true);

        $php = "<?php\nreturn {$export};\n";
        file_put_contents($this->cacheFile, $php);
    }
}
