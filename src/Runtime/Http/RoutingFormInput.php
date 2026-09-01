<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Support\HttpUtils;

final readonly class RoutingFormInput
{
    /**
     * Resolve form data only when routing can observe a `_method` field.
     *
     * @param array<string,mixed> $server
     * @param Closure():array<string,mixed> $resolve
     * @return array<string,mixed>
     */
    public static function resolve(array $server, Closure $resolve): array
    {
        $method = $server['REQUEST_METHOD'] ?? null;
        if (!is_string($method) || HttpMethodEnum::normalize($method) !== HttpMethodEnum::POST->value) {
            return [];
        }
        if (!Request::getMethodParamOverride()) {
            return [];
        }

        $contentType = $server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? null;
        if (!is_string($contentType) || !HttpUtils::isFormContentType($contentType)) {
            return [];
        }

        return $resolve();
    }
}
