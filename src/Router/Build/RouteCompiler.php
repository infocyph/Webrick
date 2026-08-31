<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Closure;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

/**
 * Build-plane route compiler. Registration/reflection/alias parsing stops here.
 */
final readonly class RouteCompiler
{
    public function __construct(private HandlerCompiler $handlers = new HandlerCompiler()) {}

    /**
     * @param array<string,mixed> $registrarOptions
     * @param array<int,mixed> $preGlobal
     * @param array<int,mixed> $postGlobal
     * @param array<int,string> $preGlobalTags
     * @param array<int,string> $postGlobalTags
     * @param Closure $register
     * @param string $environment
     * @param string $configFingerprint
     */
    public function compile(
        Closure $register,
        string $environment,
        string $configFingerprint,
        array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        array $preGlobalTags = ['webrick.middleware.pre'],
        array $postGlobalTags = ['webrick.middleware.post'],
    ): RouterBuildResult {
        if (trim($environment) === '' || trim($configFingerprint) === '') {
            throw new \InvalidArgumentException('Environment and configuration fingerprint must be non-empty.');
        }

        $routes = new Collection();
        $options = $registrarOptions + [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => false,
            'signKey' => null,
            'signedDefaultTtl' => null,
            'signedUrlConfig' => null,
            'urlBaseUri' => '',
        ];

        $signedConfig = $options['signedUrlConfig'] ?? null;
        if (is_array($signedConfig) && $signedConfig !== []) {
            $signedConfig = SignedUrlConfig::fromArray($signedConfig);
        }
        if (!$signedConfig instanceof SignedUrlConfig) {
            $signedConfig = null;
        }

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool) $options['autoSlashRedirect'],
            exposeUrlServices: false,
            signKey: is_string($options['signKey']) && $options['signKey'] !== '' ? $options['signKey'] : null,
            signedDefaultTtl: is_int($options['signedDefaultTtl']) ? $options['signedDefaultTtl'] : null,
            signedUrlConfig: $signedConfig,
            urlBaseUri: is_string($options['urlBaseUri']) ? $options['urlBaseUri'] : '',
        );

        Router::withScopedInstance($registrar, static function (Registrar $active) use ($register): void {
            $register($active);
        });

        $compiled = $routes->compile();
        $plans = [];
        $hasDomainRoutes = false;
        foreach ($compiled as $route) {
            $plan = $this->handlers->compile($route);
            $plans[$plan->routeId] = $plan;
            $hasDomainRoutes = $hasDomainRoutes
                || ($route->getDomain() !== null && $route->getDomain() !== '' && $route->getDomain() !== '*');
        }

        return new RouterBuildResult(
            routes: $compiled,
            plans: $plans,
            aliases: $routes->aliasIndex(),
            preGlobal: $this->handlers->compileMiddlewareList($preGlobal),
            postGlobal: $this->handlers->compileMiddlewareList($postGlobal),
            preGlobalTags: self::normalizeTags($preGlobalTags),
            postGlobalTags: self::normalizeTags($postGlobalTags),
            hasDomainRoutes: $hasDomainRoutes,
            environment: $environment,
            configFingerprint: $configFingerprint,
        );
    }

    /** @param array<int,string> $tags @return list<string> */
    private static function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $normalized[$tag] = $tag;
            }
        }

        return array_values($normalized);
    }
}
