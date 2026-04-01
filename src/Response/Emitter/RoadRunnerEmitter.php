<?php

// src/Response/Emitter/RoadRunnerEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Minimal adapter for RoadRunner.
 * Requires Request attribute 'roadrunner.respond' (callable):
 *   function (int $status, array $headers, string|iterable $body): void
 */
final class RoadRunnerEmitter implements EmitterInterface
{
    /**
     * Emits a response to the current IO target.
     *
     * Requires Request attribute 'roadrunner.respond' (callable):
     *   function (int $status, array $headers, string|iterable $body): void
     *
     * @param Response $response
     * @param null|Request $request
     * @throws \RuntimeException
     */
    public function emit(Response $response, ?Request $request = null): void
    {
        $respond = $request?->getAttribute('roadrunner.respond');
        if (!\is_callable($respond)) {
            throw new \RuntimeException('RoadRunnerEmitter requires Request attribute "roadrunner.respond" callable.');
        }

        $status = $response->getStatusCode();
        $headers = $response->getHeaders();
        $method = HttpMethodEnum::normalize((string)($request?->getMethod() ?? HttpMethodEnum::GET->value));
        $noBody = \in_array($status, [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)
            || $method === HttpMethodEnum::HEAD->value;

        if ($response->isStreaming()) {
            $fn = $response->getProducer();
            $out = $fn ? $fn() : [];
            // Respect HEAD / no-body statuses by responding with an empty payload
            $respond($status, $headers, $noBody ? '' : $out);
            return;
        }

        $respond($status, $headers, $noBody ? '' : (string)$response->getBody());
    }
}
