<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware\Maintenance;

/** Worker-local cached view of a portable filesystem maintenance sentinel. */
final class FileMaintenanceState implements MaintenanceStateInterface
{
    private ?string $cachedMessage = null;

    private int $nextRefreshNs = 0;

    public function __construct(
        private readonly string $file,
        private readonly int $refreshMilliseconds = 1000,
    ) {
        if ($file === '') {
            throw new \InvalidArgumentException('Maintenance sentinel path must be non-empty.');
        }
        if ($refreshMilliseconds < 0) {
            throw new \InvalidArgumentException('Maintenance refresh interval must be >= 0.');
        }
    }

    public function message(): ?string
    {
        $now = hrtime(true);
        if ($now < $this->nextRefreshNs) {
            return $this->cachedMessage;
        }

        $this->nextRefreshNs = $now + ($this->refreshMilliseconds * 1_000_000);
        if (!is_file($this->file)) {
            return $this->cachedMessage = null;
        }

        $payload = file_get_contents($this->file);

        return $this->cachedMessage = is_string($payload) && $payload !== ''
            ? $payload
            : 'Service is down for maintenance.';
    }
}
