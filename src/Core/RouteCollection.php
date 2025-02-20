<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Supports domain-based routing, advanced placeholders,
 * auto HEAD/OPTIONS, named routes, caching, etc.
 */
class RouteCollection
{
    /**
     * @var array<string, array<string, array<string, RouteInterface>>>
     *  e.g. staticRoutes['']['GET']['/'] => route
     *  staticRoutes['admin.example.com']['POST']['/users'] => route
     */
    private array $staticRoutes = [];

    /**
     * For dynamic routes with placeholders.
     * Each item: [
     *   'domain'     => string,
     *   'method'     => string,
     *   'pattern'    => string,
     *   'paramNames' => string[],
     *   'route'      => RouteInterface
     * ]
     */
    private array $dynamicRoutes = [];

    /**
     * Named routes for URL generation
     *  name => RouteInterface
     */
    private array $namedRoutes = [];

    private ?string $cacheFile = null;

    private bool $isCached = false;

    public function __construct(
        private readonly RouteParser $parser,
        private readonly bool $autoHead = true,
        private readonly bool $autoOptions = true,
    ) {
    }

    public function addRoute(RouteInterface $route): void
    {
        $method = strtoupper($route->getMethod());
        $path = $route->getPath();
        $domain = $route->getDomain() ?? '';
        $name = $route->getName();

        // Store named route
        if ($name) {
            $this->namedRoutes[$name] = $route;
        }

        if (str_contains($path, '{')) {
            // dynamic
            $parsed = $this->parser->parse($path);
            $this->dynamicRoutes[] = [
                'domain' => $domain,
                'method' => $method,
                'pattern' => $parsed['pattern'],
                'paramNames' => $parsed['paramNames'],
                'route' => $route,
            ];
        } else {
            // static
            $this->staticRoutes[$domain][$method][$path] = $route;
        }
    }

    /**
     * Attempt to match the request => [RouteInterface, params].
     *
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function match(ServerRequestInterface $request): array
    {
        $reqMethod = strtoupper($request->getMethod());
        $reqUri = $request->getUri();
        $path = $reqUri->getPath();
        $domain = $reqUri->getHost() ?? '';

        // auto-HEAD => treat HEAD as GET
        $matchMethod = ($this->autoHead && $reqMethod === 'HEAD') ? 'GET' : $reqMethod;

        // 1) check static
        $staticDomain = $this->staticRoutes[$domain] ?? $this->staticRoutes[''] ?? [];
        if (isset($staticDomain[$matchMethod][$path])) {
            return [$staticDomain[$matchMethod][$path], []];
        }

        // 2) check dynamic
        $foundAnyForPath = false;
        $allowedMethods = [];

        foreach ($this->dynamicRoutes as $data) {
            if ($data['domain'] !== '' && $data['domain'] !== $domain) {
                // domain mismatch
                continue;
            }
            if (preg_match($data['pattern'], $path, $matches)) {
                $foundAnyForPath = true;
                if ($data['method'] === $matchMethod) {
                    // success
                    $params = [];
                    foreach ($data['paramNames'] as $p) {
                        $params[$p] = $matches[$p] ?? null;
                    }

                    return [$data['route'], $params];
                }
                $allowedMethods[$data['method']] = true;
            }
        }

        // If request is OPTIONS and we have auto-options
        if ($this->autoOptions && $reqMethod === 'OPTIONS') {
            // gather all methods for this path, both static & dynamic
            $allowed = array_merge(
                $this->findStaticMethodsForPath($domain, $path),
                array_keys($allowedMethods),
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

        // not found => 404
        throw new RouteNotFoundException("No route found for {$reqMethod} {$path} @ {$domain}");
    }

    private function findStaticMethodsForPath(string $domain, string $path): array
    {
        $res = [];
        $staticDomain = $this->staticRoutes[$domain] ?? $this->staticRoutes[''] ?? [];
        foreach ($staticDomain as $method => $paths) {
            if (isset($paths[$path])) {
                $res[] = $method;
            }
        }

        return $res;
    }

    // route naming => URL generation
    public function urlFor(
        string $routeName,
        array $params = [],
        bool $absolute = false,
        string $scheme = 'https',
    ): string {
        if (!isset($this->namedRoutes[$routeName])) {
            throw new \RuntimeException("No named route: {$routeName}");
        }
        $route = $this->namedRoutes[$routeName];
        $domain = $route->getDomain() ?? '';
        $path = $route->getPath();

        // Replace placeholders
        $pattern = '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]+)?\}/';
        $url = preg_replace_callback($pattern, function ($m) use ($params) {
            $key = $m[1];

            return $params[$key] ?? $m[0];
        }, (string)$path);

        if ($absolute && $domain !== '') {
            return $scheme . '://' . $domain . $url;
        }

        return $url;
    }

    // -------------------------------------------------
    // caching
    // -------------------------------------------------
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
                $this->staticRoutes = $data['staticRoutes'] ?? [];
                $this->dynamicRoutes = $data['dynamicRoutes'] ?? [];
                $this->namedRoutes = $data['namedRoutes'] ?? [];
                $this->isCached = true;
            }
        }
    }

    public function storeCache(): void
    {
        if (!$this->cacheFile) {
            return;
        }
        $export = var_export([
            'staticRoutes' => $this->staticRoutes,
            'dynamicRoutes' => $this->dynamicRoutes,
            'namedRoutes' => $this->namedRoutes,
        ], true);

        $php = "<?php\nreturn {$export};\n";
        file_put_contents($this->cacheFile, $php);
    }
}
