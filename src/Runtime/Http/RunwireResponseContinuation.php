<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Fiber;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use RuntimeException;
use Throwable;
use WeakMap;

/** @internal Runwire-only I/O suspension; not a general scheduler. */
final class RunwireResponseContinuation
{
    /** @var WeakMap<object, true>|null */
    private static ?WeakMap $managedFibers = null;

    public static function awaitBodyReadable(RequestBodyInterface $body, CancellationToken $cancellation): void
    {
        $cancellation->throwIfCancelled();
        if ($body->bufferedBytes() > 0 || $body->eof()) {
            return;
        }

        $fiber = self::managedFiber(
            'Runwire request body requires continuation through the Webrick Runwire application boundary.',
        );
        $ready = false;
        $resume = static function () use (&$ready, $fiber): void {
            if ($ready) {
                return;
            }

            $ready = true;
            if (!$fiber->isSuspended()) {
                return;
            }

            try {
                $fiber->resume();
            } finally {
                self::releaseIfTerminated($fiber);
            }
        };
        $subscription = $cancellation->onCancel(static function () use ($resume): void {
            $resume();
        });

        try {
            $body->onData(static function (RequestBodyInterface $readyBody) use ($resume): void {
                if ($readyBody->bufferedBytes() > 0 || $readyBody->eof()) {
                    $resume();
                }
            });
            $body->onEnd(static function (RequestBodyInterface $endedBody) use ($resume): void {
                unset($endedBody);
                $resume();
            });

            if (
                !$ready
                && !$cancellation->isCancelled()
                && $body->bufferedBytes() === 0
                && !$body->eof()
            ) {
                Fiber::suspend();
            }
        } finally {
            $subscription->unsubscribe();
            $body->onData(static function (RequestBodyInterface $readyBody): void {
                unset($readyBody);
            });
            $body->onEnd(static function (RequestBodyInterface $endedBody): void {
                unset($endedBody);
            });
        }

        $cancellation->throwIfCancelled();
    }

    public static function awaitDrain(ResponseWriterInterface $writer, CancellationToken $cancellation): void
    {
        $cancellation->throwIfCancelled();
        $fiber = self::managedFiber(
            'Runwire response requires drain continuation through the Webrick Runwire application boundary.',
        );
        $drained = false;
        $resume = static function () use (&$drained, $fiber): void {
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
        };
        $subscription = $cancellation->onCancel(static function () use ($resume): void {
            $resume();
        });

        try {
            $writer->onDrain(static function (ResponseWriterInterface $drainedWriter) use ($resume): void {
                unset($drainedWriter);
                $resume();
            });

            if (!$drained && !$cancellation->isCancelled()) {
                Fiber::suspend();
            }
        } finally {
            $subscription->unsubscribe();
        }

        $cancellation->throwIfCancelled();
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

    /** @return Fiber<mixed, mixed, mixed, mixed> */
    private static function managedFiber(string $message): Fiber
    {
        $fiber = Fiber::getCurrent();
        if (!$fiber instanceof Fiber || !isset(self::managedFibers()[$fiber])) {
            throw new RuntimeException($message);
        }

        return $fiber;
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
