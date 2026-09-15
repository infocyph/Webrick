<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Fiber;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use RuntimeException;
use Throwable;
use WeakMap;

/** @internal Runwire-only response-production suspension; not a general scheduler. */
final class RunwireResponseContinuation
{
    /** @var WeakMap<object, true>|null */
    private static ?WeakMap $managedFibers = null;

    public static function awaitDrain(ResponseWriterInterface $writer): void
    {
        $fiber = Fiber::getCurrent();
        if (!$fiber instanceof Fiber || !isset(self::managedFibers()[$fiber])) {
            throw new RuntimeException(
                'Runwire response requires drain continuation through the Webrick Runwire application boundary.',
            );
        }

        $drained = false;
        $writer->onDrain(static function (ResponseWriterInterface $drainedWriter) use (&$drained, $fiber): void {
            unset($drainedWriter);
            if ($drained) {
                return;
            }

            $drained = true;
            if (!$fiber->isSuspended()) {
                return;
            }

            try {
                $fiber->resume();
            } finally {
                self::releaseIfTerminated($fiber);
            }
        });

        if (!$drained) {
            Fiber::suspend();
        }
    }

    /** @param callable(): void $handler */
    public static function run(callable $handler): void
    {
        $current = Fiber::getCurrent();
        if ($current instanceof Fiber) {
            $managedFibers = self::managedFibers();
            $ownsRegistration = !isset($managedFibers[$current]);
            if ($ownsRegistration) {
                $managedFibers[$current] = true;
            }

            try {
                $handler();
            } finally {
                if ($ownsRegistration) {
                    unset($managedFibers[$current]);
                }
            }

            return;
        }

        /** @var Fiber<mixed, mixed, mixed, mixed> $fiber */
        $fiber = new Fiber($handler);
        self::managedFibers()[$fiber] = true;

        try {
            $fiber->start();
        } catch (Throwable $error) {
            unset(self::managedFibers()[$fiber]);

            throw $error;
        }

        self::releaseIfTerminated($fiber);
    }

    /** @return WeakMap<object, true> */
    private static function managedFibers(): WeakMap
    {
        return self::$managedFibers ??= new WeakMap();
    }

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private static function releaseIfTerminated(Fiber $fiber): void
    {
        if ($fiber->isTerminated() && self::$managedFibers instanceof WeakMap) {
            unset(self::$managedFibers[$fiber]);
        }
    }
}
