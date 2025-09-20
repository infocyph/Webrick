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
     *
     * @param Response $response
     * @param Request|null $request
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
     *
     * @return null|EmitterInterface
     */
    private function pick(?Request $request): ?EmitterInterface
    {
        $serverSoftware = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        return match (true) {
            \extension_loaded('swoole')
            && $request?->getAttribute('swoole.response') instanceof \Swoole\Http\Response => new SwooleEmitter(),
            (\getenv('RR_MODE') || \class_exists('\\Spiral\\RoadRunner\\Environment'))
            && \is_callable($request?->getAttribute('roadrunner.respond')) => new RoadRunnerEmitter(),
            \class_exists('\\Workerman\\Worker')
            && ($request?->getAttribute('workerman.response')
                || $request?->getAttribute('workerman.connection')) => new WorkermanEmitter(),

            \function_exists('frankenphp_is_worker') && \frankenphp_is_worker() => new FrankenPhpEmitter(),
            \PHP_SAPI === 'litespeed' || \function_exists('litespeed_finish_request') => new LsapiEmitter(),
            \function_exists('fastcgi_finish_request') && $serverSoftware !== '' &&
            \str_contains($serverSoftware, 'unit') => new UnitEmitter(),
            \PHP_SAPI === 'fpm-fcgi' || \function_exists('fastcgi_finish_request') => new FpmEmitter(),

            default => new DefaultEmitter(),
        };
    }
}
