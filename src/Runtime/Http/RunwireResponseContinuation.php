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
    private static ?WeakMap $ioSuspensions = null;

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

            if (!$ready && !$cancellation->isCancelled() && $body->bufferedBytes() === 0) {
                self::suspendForIo($fiber);
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
                self::suspendForIo($fiber);
            }
        } finally {
            $subscription->unsubscribe();
        }
    }

    /** @param callable(): void $handler */
    public static function run(callable $handler): void
    {
        $current = Fiber::getCurrent();
        if ($current instanceof Fiber && isset(self::managedFibers()[$current])) {
            $handler();

            return;
        }

        self::runOwnedFiber($handler, $current);
    }

    /** @return WeakMap<object, true> */
    private static function ioSuspensions(): WeakMap
    {
        return self::$ioSuspensions ??= new WeakMap();
    }

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private static function isFiberSuspended(Fiber $fiber): bool
    {
        return $fiber->isSuspended();
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
        if (!$fiber->isTerminated()) {
            return;
        }
        if (self::$ioSuspensions instanceof WeakMap) {
            unset(self::$ioSuspensions[$fiber]);
        }
        if (self::$managedFibers instanceof WeakMap) {
            unset(self::$managedFibers[$fiber]);
        }
    }

    /**
     * Webrick owns only the inner continuation Fiber. I/O suspension remains
     * callback-driven; ordinary handler suspension is handed back to the
     * caller-owned Fiber and resumed only when that caller explicitly resumes.
     *
     * @param callable(): void $handler
     * @param Fiber<mixed, mixed, mixed, mixed>|null $owner
     */
    private static function runOwnedFiber(callable $handler, ?Fiber $owner): void
    {
        /** @var Fiber<mixed, mixed, mixed, mixed> $fiber */
        $fiber = new Fiber($handler);
        self::managedFibers()[$fiber] = true;

        try {
            $suspension = $fiber->start();
            while ($fiber->isSuspended() && !isset(self::ioSuspensions()[$fiber])) {
                if (!$owner instanceof Fiber) {
                    throw new RuntimeException(
                        'Runwire handler suspended without a caller-owned Fiber to resume it.',
                    );
                }

                $resumeValue = Fiber::suspend($suspension);
                $suspension = $fiber->resume($resumeValue);
            }
        } catch (Throwable $error) {
            self::unwindSuspendedHandler($fiber, $owner, $error);
            if (self::$ioSuspensions instanceof WeakMap) {
                unset(self::$ioSuspensions[$fiber]);
            }
            unset(self::managedFibers()[$fiber]);

            throw $error;
        }

        self::releaseIfTerminated($fiber);
    }

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private static function suspendForIo(Fiber $fiber): void
    {
        $ioSuspensions = self::ioSuspensions();
        $ioSuspensions[$fiber] = true;

        try {
            Fiber::suspend();
        } finally {
            unset($ioSuspensions[$fiber]);
        }
    }

    /**
     * Keep Webrick's inner handler attached to the caller-owned task until
     * exception handling and cleanup have completely unwound.
     *
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     * @param Fiber<mixed, mixed, mixed, mixed>|null $owner
     */
    private static function unwindSuspendedHandler(
        Fiber $fiber,
        ?Fiber $owner,
        Throwable $error,
    ): void {
        if (!$fiber->isSuspended() || isset(self::ioSuspensions()[$fiber])) {
            return;
        }

        try {
            $suspension = $fiber->throw($error);
            while (!isset(self::ioSuspensions()[$fiber])) {
                if (!self::isFiberSuspended($fiber)) {
                    break;
                }
                if (!$owner instanceof Fiber) {
                    throw new RuntimeException(
                        'Runwire handler cleanup suspended without a caller-owned Fiber.',
                    );
                }

                try {
                    $resumeValue = Fiber::suspend($suspension);
                    $suspension = $fiber->resume($resumeValue);
                } catch (Throwable $continuationError) {
                    $suspension = $fiber->throw($continuationError);
                }
            }
        } catch (Throwable) {
            // The original caller-owned failure remains authoritative.
        } finally {
            self::releaseIfTerminated($fiber);
        }
    }
}
