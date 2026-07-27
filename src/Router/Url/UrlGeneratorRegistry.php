<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Closure;

/**
 * Process-local lazy URL generator binding shared by the router facade and
 * cached kernels. Request-specific state must not be captured by factories.
 */
final class UrlGeneratorRegistry
{
    /** @var (Closure():UrlGenerator)|null */
    private static ?Closure $factory = null;

    private static ?UrlGenerator $generator = null;

    private function __construct() {}

    public static function bind(UrlGenerator $generator): void
    {
        self::$factory = null;
        self::$generator = $generator;
    }

    /**
     * @param Closure():UrlGenerator $factory
     */
    public static function bindFactory(Closure $factory): void
    {
        self::$generator = null;
        self::$factory = $factory;
    }

    public static function get(): UrlGenerator
    {
        if (self::$generator === null && self::$factory !== null) {
            $factory = self::$factory;
            self::$factory = null;
            self::$generator = $factory();
        }

        return self::$generator
            ?? throw new \LogicException('URL services not bound. Enable via Registrar constructor.');
    }

    public static function has(): bool
    {
        return self::$generator !== null || self::$factory !== null;
    }

    public static function reset(): void
    {
        self::$generator = null;
        self::$factory = null;
    }
}
