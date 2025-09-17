<?php

// src/Response/Emitter/RoadRunnerEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Minimal adapter for RoadRunner.
 * Requires Request attribute 'roadrunner.respond' (callable):
 *   function (int $status, array $headers, string|iterable $body): void
 */
final class RoadRunnerEmitter implements EmitterInterface
{
    public function emit(Response $response, ?Request $request = null): void
    {
        $respond = $request?->getAttribute('roadrunner.respond');
        if (!\is_callable($respond)) {
            throw new \RuntimeException('RoadRunnerEmitter requires Request attribute "roadrunner.respond" callable.');
        }

        $status = $response->getStatusCode();
        $headers = $response->getHeaders();

        if ($response->isStreaming()) {
            $fn = $response->getProducer();
            $out = $fn ? $fn() : [];
            $respond($status, $headers, $out);
            return;
        }

        $respond($status, $headers, (string)$response->getBody());
    }
}
