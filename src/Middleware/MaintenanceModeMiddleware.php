<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\Maintenance\FileMaintenanceState;
use Infocyph\Webrick\Middleware\Maintenance\MaintenanceStateInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/** Maintenance gate backed by worker-local or explicit control-plane state. */
final readonly class MaintenanceModeMiddleware
{
    private MaintenanceStateInterface $state;

    public function __construct(
        string $file = __DIR__ . '/../../storage/framework/down',
        private int $retryAfter = 3600,
        private string $contentType = MediaTypeEnum::PLAIN->value,
        int $refreshMilliseconds = 1000,
        ?MaintenanceStateInterface $state = null,
    ) {
        if ($this->retryAfter < 0) {
            throw new \InvalidArgumentException('Maintenance Retry-After must be >= 0.');
        }
        if ($this->contentType === '') {
            throw new \InvalidArgumentException('Maintenance content type must be non-empty.');
        }

        $this->state = $state ?? new FileMaintenanceState($file, $refreshMilliseconds);
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

        throw HttpException::serviceUnavailable(
            $message,
            [
                'Retry-After' => (string) $this->retryAfter,
                'Content-Type' => $this->contentType,
            ],
        );
    }
}
