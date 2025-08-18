<?php

// src/Response/Emitter/AutoEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class AutoEmitter implements EmitterInterface
{
    private ?EmitterInterface $chosen = null;

    public function emit(Response $response, ?Request $request = null): void
    {
        $this->chosen ??= $this->pick($request);
        $this->chosen->emit($response, $request);
    }

    /** Try async/event-loop engines first, then concrete SAPIs. */
    private function pick(?Request $request): EmitterInterface
    {
        return $this->pickAsync($request) ?? $this->pickConcrete();
    }

    /** Async / long-running engines; null when none match. */
    private function pickAsync(?Request $request): ?EmitterInterface
    {
        $hasSwooleResp = \extension_loaded('swoole')
            && $request?->getAttribute('swoole.response') instanceof \Swoole\Http\Response;

        $hasRR = (\getenv('RR_MODE') || \class_exists('\\Spiral\\RoadRunner\\Environment'))
            && \is_callable($request?->getAttribute('roadrunner.respond'));

        $hasWorkerman = \class_exists('\\Workerman\\Worker')
            && ($request?->getAttribute('workerman.response') || $request?->getAttribute('workerman.connection'));

        return match (true) {
            $hasSwooleResp => new SwooleEmitter(),
            $hasRR => new RoadRunnerEmitter(),
            $hasWorkerman => new WorkermanEmitter(),
            default => null,
        };
    }

    /** Concrete SAPI/server integrations (stateless). */
    private function pickConcrete(): EmitterInterface
    {
        $serverSoftware = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));

        $isFranken = \function_exists('frankenphp_is_worker') && \frankenphp_is_worker();
        $isLiteSpeed = \PHP_SAPI === 'litespeed' || \function_exists('litespeed_finish_request');
        $isUnit = \function_exists('fastcgi_finish_request') && $serverSoftware !== '' && str_contains(
                $serverSoftware,
                'unit',
            );
        $isFpmLike = \PHP_SAPI === 'fpm-fcgi' || \function_exists('fastcgi_finish_request');

        return match (true) {
            $isFranken => new FrankenPhpEmitter(),
            $isLiteSpeed => new LsapiEmitter(),
            $isUnit => new UnitEmitter(),
            $isFpmLike => new FpmEmitter(),
            default => new DefaultEmitter(), // Apache & PHP built-in server land here
        };
    }
}
