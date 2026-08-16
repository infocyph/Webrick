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
     *
     * Pass an emitter name to select a transport explicitly for this call. An
     * empty name preserves automatic detection and its per-instance cache.
     */
    public function emit(Response $response, ?Request $request = null, string $emitter = ''): void
    {
        $selected = $emitter === ''
            ? ($this->chosen ??= $this->pick($request))
            : $this->pick($request, $emitter);

        $selected->emit($response, $request);
    }

    /**
     * Choose the best emitter based on the current environment.
     *
     * A non-empty emitter name explicitly selects a transport. Otherwise the
     * current runtime is detected.
     */
    private function pick(?Request $request, string $emitter = ''): EmitterInterface
    {
        if ($emitter !== '') {
            return match ($emitter) {
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
