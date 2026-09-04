<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware\Maintenance;

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\PreRoutingGateInterface;
use Infocyph\Webrick\Router\Runtime\RoutingInput;

/** Requestless maintenance gate for compiled/persistent runtime composition. */
final readonly class MaintenancePreRoutingGate implements PreRoutingGateInterface
{
    private const int MAX_BYPASS_PATHS = 32;

    /** @var array<non-empty-string,true> */
    private array $bypassPaths;

    private MaintenanceResponsePolicy $responses;

    private MaintenanceStateInterface $state;

    /**
     * @param list<string> $bypassPaths Exact paths that may bypass maintenance.
     */
    public function __construct(
        string $file = __DIR__ . '/../../../storage/framework/down',
        int $retryAfter = 3600,
        string $contentType = MediaTypeEnum::PLAIN->value,
        int $refreshMilliseconds = 1000,
        ?MaintenanceStateInterface $state = null,
        array $bypassPaths = [],
    ) {
        $this->state = $state ?? new FileMaintenanceState($file, $refreshMilliseconds);
        $this->responses = new MaintenanceResponsePolicy($retryAfter, $contentType);
        $this->bypassPaths = self::normalizeBypassPaths($bypassPaths);
    }

    public function evaluate(RoutingInput $routing): ?Response
    {
        if (isset($this->bypassPaths[$routing->path])) {
            return null;
        }

        $message = $this->state->message();

        return $message === null ? null : $this->responses->response($message);
    }

    /**
     * @param list<string> $paths
     * @return array<non-empty-string,true>
     */
    private static function normalizeBypassPaths(array $paths): array
    {
        if (count($paths) > self::MAX_BYPASS_PATHS) {
            throw new \InvalidArgumentException(sprintf(
                'Maintenance bypass paths are limited to %d entries.',
                self::MAX_BYPASS_PATHS,
            ));
        }

        $normalized = [];
        foreach ($paths as $path) {
            if ($path === '' || $path[0] !== '/') {
                throw new \InvalidArgumentException('Maintenance bypass paths must be non-empty absolute paths.');
            }
            if (str_contains($path, '?') || str_contains($path, '#')) {
                throw new \InvalidArgumentException('Maintenance bypass paths must not contain a query string or fragment.');
            }

            $canonical = Uri::normalizePath($path);
            if ($canonical === '' || $canonical[0] !== '/') {
                throw new \InvalidArgumentException('Maintenance bypass paths must normalize to an absolute path.');
            }
            $normalized[$canonical] = true;
        }

        return $normalized;
    }
}
