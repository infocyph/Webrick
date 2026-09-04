<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Middleware\Maintenance\FileMaintenanceState;
use Infocyph\Webrick\Middleware\Maintenance\MaintenanceResponsePolicy;
use Infocyph\Webrick\Middleware\Maintenance\MaintenanceStateInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/** Maintenance gate backed by worker-local or explicit control-plane state. */
final readonly class MaintenanceModeMiddleware
{
    private MaintenanceResponsePolicy $responses;

    private MaintenanceStateInterface $state;

    public function __construct(
        string $file = __DIR__ . '/../../storage/framework/down',
        int $retryAfter = 3600,
        string $contentType = MediaTypeEnum::PLAIN->value,
        int $refreshMilliseconds = 1000,
        ?MaintenanceStateInterface $state = null,
    ) {
        $this->state = $state ?? new FileMaintenanceState($file, $refreshMilliseconds);
        $this->responses = new MaintenanceResponsePolicy($retryAfter, $contentType);
    }

    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $message = $this->state->message();
        if ($message === null) {
            return $next($req);
        }

        throw $this->responses->exception($message);
    }
}
