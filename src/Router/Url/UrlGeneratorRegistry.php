<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Closure;
use LogicException;

/** Process-local URL service binding, mutable only before production freeze. */
final class UrlGeneratorRegistry
{
    /** @var (Closure():UrlGenerator)|null */
    private static ?Closure $factory = null;

    private static bool $frozen = false;

    private static ?UrlGenerator $generator = null;

    private function __construct() {}

    public static function bind(UrlGenerator $generator): void
    {
        self::assertMutable();
        self::$factory = null;
        self::$generator = $generator;
    }

    /** @param Closure():UrlGenerator $factory */
    public static function bindFactory(Closure $factory): void
    {
        self::assertMutable();
        self::$generator = null;
        self::$factory = $factory;
    }

    public static function freeze(): void
    {
        // Resolve a lazy factory before freeze so first request cannot mutate
        // process-level registry state.
        if (self::$generator === null && self::$factory !== null) {
            $factory = self::$factory;
            self::$generator = $factory();
            self::$factory = null;
        }
        self::$frozen = true;
    }

    public static function frozen(): bool
    {
        return self::$frozen;
    }

    public static function get(): UrlGenerator
    {
        if (self::$generator === null && self::$factory !== null) {
            $factory = self::$factory;
            self::$factory = null;
            self::$generator = $factory();
        }

        return self::$generator
            ?? throw new LogicException('URL services not bound. Enable via Registrar or compiled kernel boot.');
    }

    public static function has(): bool
    {
        return self::$generator !== null || self::$factory !== null;
    }

    public static function reset(): void
    {
        self::assertMutable();
        self::$generator = null;
        self::$factory = null;
    }

    private static function assertMutable(): void
    {
        if (self::$frozen) {
            throw new LogicException('URL generator registry is frozen for production runtime.');
        }
    }
}
