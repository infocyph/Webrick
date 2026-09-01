<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware\Maintenance;

/** Explicit worker/control-plane maintenance state with no request-time filesystem I/O. */
final class MemoryMaintenanceState implements MaintenanceStateInterface
{
    public function __construct(private ?string $message = null) {}

    public function disable(): void
    {
        $this->message = null;
    }

    public function enable(string $message = 'Service is down for maintenance.'): void
    {
        $this->message = $message === '' ? 'Service is down for maintenance.' : $message;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
