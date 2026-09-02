<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Psr\Log\LoggerInterface;

/** Allocation-light renderer for ordinary compiled-router misses. */
final readonly class RoutingControlRenderer implements RoutingControlRendererInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function render(RoutingInput $routing, MatchOutcome $outcome): Response
    {
        $status = $outcome->type === MatchOutcomeType::METHOD_NOT_ALLOWED
            ? StatusEnum::METHOD_NOT_ALLOWED
            : StatusEnum::NOT_FOUND;
        $headers = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept',
        ];
        if ($status === StatusEnum::METHOD_NOT_ALLOWED) {
            $headers['Allow'] = implode(', ', $outcome->allowed);
        }

        $this->logger->notice(
            sprintf('[http:%d] routing control outcome', $status->value),
            [
                'status' => $status->value,
                'series' => $status->series(),
                'method' => $routing->method,
                'path' => $routing->path,
            ],
        );

        $reason = $status->reason();

        return Response::plaintext(
            "{$status->value} {$reason}\n{$reason}",
            $status->value,
            $headers,
        );
    }
}
