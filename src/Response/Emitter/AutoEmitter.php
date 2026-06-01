<?php

// src/Response/Emitter/AutoEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class AutoEmitter implements EmitterInterface
{
    private ?EmitterInterface $chosen = null;

    /**
     * Auto-detect the best emitter for the current environment and emit the response.
     * If an emitter is chosen, it will be cached for future calls.
     * If no emitter matches, null is returned.
     */
    public function emit(Response $response, ?Request $request = null): void
    {
        $this->chosen ??= $this->pick($request);
        $this->chosen->emit($response, $request);
    }

    /**
     * Choose the best emitter based on the current environment.
     *
     * If an emitter is chosen, it will be cached for future calls.
     * If no emitter matches, null is returned.
     */
    private function pick(?Request $request): EmitterInterface
    {
        // Optional explicit override via env var (e.g., WEBRICK_EMITTER=swoole|roadrunner|workerman|frankenphp|lsapi|unit|fpm|cli|default)
        $overrideRaw = \getenv('WEBRICK_EMITTER');
        $override = \is_string($overrideRaw) ? strtolower($overrideRaw) : '';
        if ($override !== '') {
            return match ($override) {
                'swoole' => new SwooleEmitter(),
                'roadrunner' => new RoadRunnerEmitter(),
                'workerman' => new WorkermanEmitter(),
                'frankenphp' => new FrankenPhpEmitter(),
                'lsapi' => new LsapiEmitter(),
                'unit' => new UnitEmitter(),
                'fpm' => new FpmEmitter(),
                'cli' => new CliEmitter(),
                default => new DefaultEmitter(),
            };
        }

        $serverSoftwareRaw = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $serverSoftware = \is_string($serverSoftwareRaw) ? strtolower($serverSoftwareRaw) : '';

        return match (true) {
            // Async servers (prefer explicit per-request handle extraction)
            \extension_loaded('swoole')
            && $request?->getAttribute('swoole.response') instanceof \Swoole\Http\Response => new SwooleEmitter(),
            (\getenv('RR_MODE') || \class_exists('\\Spiral\\RoadRunner\\Environment'))
            && \is_callable($request?->getAttribute('roadrunner.respond')) => new RoadRunnerEmitter(),
            \class_exists('\\Workerman\\Worker')
            && ($request?->getAttribute('workerman.response')
                || $request?->getAttribute('workerman.connection')) => new WorkermanEmitter(),

            // Sync servers / special SAPIs
            \function_exists('frankenphp_is_worker') && \frankenphp_is_worker() => new FrankenPhpEmitter(),
            \PHP_SAPI === 'litespeed' || \function_exists('litespeed_finish_request') => new LsapiEmitter(),
            \function_exists('fastcgi_finish_request') && $serverSoftware !== ''
            && \str_contains($serverSoftware, 'unit') => new UnitEmitter(),
            \PHP_SAPI === 'fpm-fcgi' || \function_exists('fastcgi_finish_request') => new FpmEmitter(),

            // CLI/testing fallback
            \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) => new CliEmitter(),

            default => new DefaultEmitter(),
        };
    }
}
