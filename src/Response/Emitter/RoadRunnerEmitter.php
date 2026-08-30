<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/** Minimal RoadRunner response bridge. */
final class RoadRunnerEmitter implements EmitterInterface
{
    public function emit(Response $response, ?Request $request = null): void
    {
        if (!$request instanceof Request) {
            throw new \RuntimeException('RoadRunnerEmitter requires a Request instance.');
        }

        $respond = $request->getAttribute('roadrunner.respond');
        if (!is_callable($respond)) {
            throw new \RuntimeException('RoadRunnerEmitter requires Request attribute "roadrunner.respond" callable.');
        }

        $status = $response->getStatusCode();
        $headers = $response->getHeaders();
        $method = HttpMethodEnum::normalize($request->getMethod());
        $noBody = in_array($status, [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)
            || $method === HttpMethodEnum::HEAD->value;

        if ($response->isStreaming()) {
            $producer = $response->getProducer();
            $output = $producer ? $producer() : [];
            $respond($status, $headers, $noBody ? '' : $output);
            return;
        }

        $body = $response->getStringBody();
        if ($body === null) {
            $body = (string) $response->getBody();
        }
        $respond($status, $headers, $noBody ? '' : $body);
    }
}
