<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Read-only request execution metadata and cancellation observation.
 *
 * The selected lower runtime remains authoritative for cancellation and
 * deadlines. Webrick only exposes a request-local view and never owns a second
 * cancellation source, deadline clock, or lifecycle state machine.
 */
final readonly class RuntimeRequestExecution
{
    public const string ATTRIBUTE = 'webrick.runtime_execution';

    private ?Closure $cancellationReason;

    private ?Closure $cancelled;

    /**
     * @param null|callable():bool $cancelled
     * @param null|callable():?string $cancellationReason
     */
    public function __construct(
        public ?string $requestId = null,
        public ?int $startMonotonicNanoseconds = null,
        public ?int $deadlineMonotonicNanoseconds = null,
        ?callable $cancelled = null,
        ?callable $cancellationReason = null,
    ) {
        if ($requestId !== null && ($requestId === '' || preg_match('/[\x00-\x1F\x7F]/', $requestId) === 1)) {
            throw new InvalidArgumentException('Runtime request ID must be non-empty and contain no control characters.');
        }
        if ($startMonotonicNanoseconds !== null && $startMonotonicNanoseconds < 0) {
            throw new InvalidArgumentException('Runtime request start time must be non-negative.');
        }
        if ($deadlineMonotonicNanoseconds !== null && $deadlineMonotonicNanoseconds < 0) {
            throw new InvalidArgumentException('Runtime request deadline must be non-negative.');
        }
        if (
            $startMonotonicNanoseconds !== null
            && $deadlineMonotonicNanoseconds !== null
            && $deadlineMonotonicNanoseconds < $startMonotonicNanoseconds
        ) {
            throw new InvalidArgumentException('Runtime request deadline cannot precede its start time.');
        }

        $this->cancelled = $cancelled === null ? null : Closure::fromCallable($cancelled);
        $this->cancellationReason = $cancellationReason === null ? null : Closure::fromCallable($cancellationReason);
    }

    public function cancellationReason(): ?string
    {
        if ($this->cancellationReason === null) {
            return null;
        }

        $reason = ($this->cancellationReason)();
        if ($reason === '') {
            throw new UnexpectedValueException('Runtime cancellation reason callback must return null or a non-empty string.');
        }

        return $reason;
    }

    public function cancellationVisible(): bool
    {
        return $this->cancelled !== null;
    }

    public function cancelled(): bool
    {
        if ($this->cancelled === null) {
            return false;
        }

        return ($this->cancelled)();
    }
}
