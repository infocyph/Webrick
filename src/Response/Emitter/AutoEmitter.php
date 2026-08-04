<?php

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
                'frankenphp' => new DefaultEmitter(DefaultEmitter::FINISH_FRANKENPHP),
                'lsapi' => new DefaultEmitter(DefaultEmitter::FINISH_LITESPEED),
                'unit', 'fpm' => new DefaultEmitter(DefaultEmitter::FINISH_FASTCGI, true),
                'cli' => new CliEmitter(),
                default => new DefaultEmitter(),
            };
        }

        // Resolve the common synchronous SAPIs before probing optional async
        // runtimes and request attributes on every short-lived request.
        if (\PHP_SAPI === 'fpm-fcgi') {
            return new DefaultEmitter(DefaultEmitter::FINISH_FASTCGI, true);
        }
        if (\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return new CliEmitter();
        }
        if (\in_array(\PHP_SAPI, ['apache2handler', 'cli-server'], true)) {
            return new DefaultEmitter();
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
            \function_exists('frankenphp_is_worker') && \frankenphp_is_worker() => new DefaultEmitter(
                DefaultEmitter::FINISH_FRANKENPHP,
            ),
            \PHP_SAPI === 'litespeed' || \function_exists('litespeed_finish_request') => new DefaultEmitter(
                DefaultEmitter::FINISH_LITESPEED,
            ),
            \function_exists('fastcgi_finish_request') && $serverSoftware !== ''
            && \str_contains($serverSoftware, 'unit') => new DefaultEmitter(DefaultEmitter::FINISH_FASTCGI, true),
            \function_exists('fastcgi_finish_request') => new DefaultEmitter(
                DefaultEmitter::FINISH_FASTCGI,
                true,
            ),

            default => new DefaultEmitter(),
        };
    }
}
