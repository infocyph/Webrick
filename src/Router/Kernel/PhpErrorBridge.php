<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use ErrorException;

/**
 * Process-level PHP error conversion bridge.
 *
 * Install this once during worker/process bootstrap when warnings/notices should
 * become ErrorException instances. It must never be pushed/popped per request.
 */
final class PhpErrorBridge
{
    private bool $installed = false;

    public function install(): void
    {
        if ($this->installed) {
            return;
        }

        set_error_handler(
            static function (int $severity, string $message, ?string $file = null, ?int $line = null): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }

                throw new ErrorException($message, 0, $severity, $file ?? 'unknown', $line ?? 0);
            },
        );
        $this->installed = true;
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function restore(): void
    {
        if (!$this->installed) {
            return;
        }

        restore_error_handler();
        $this->installed = false;
    }
}
