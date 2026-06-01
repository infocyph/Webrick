<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

final class MatcherCacheSupport
{
    public static function shouldWarmOpcache(): bool
    {
        if (!\function_exists('opcache_compile_file')) {
            return false;
        }

        if (\filter_var((string) \ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL) !== true) {
            return false;
        }

        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            if (\filter_var((string) \ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL) !== true) {
                return false;
            }
        }

        if (\function_exists('opcache_get_status') && \opcache_get_status(false) === false) {
            return false;
        }

        return true;
    }
}
