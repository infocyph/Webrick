<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use BackedEnum;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Router\OpenApi\DTORegistry;
use Infocyph\Webrick\Router\OpenApi\SchemaBuilder;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AutoJsonResponseMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        $out = $h->handle($r);

        /* If already a ResponseInterface, leave untouched */
        if ($out instanceof ResponseInterface) {
            return $out;
        }

        /* Collect DTO schema for OpenAPI if needed */
        if (is_object($out) && !$out instanceof JsonSerializable && !$out instanceof BackedEnum) {
            $name = (new \ReflectionClass($out))->getShortName();
            $schema = (new SchemaBuilder())->build($out::class);
            DTORegistry::add($name, $schema);
        }

        /* Translate Enums / JsonSerializable */
        if ($out instanceof BackedEnum) {
            $out = $out->value;
        }
        if ($out instanceof JsonSerializable) {
            $out = $out->jsonSerialize();
        }

        $json = json_encode($out, JSON_UNESCAPED_SLASHES);

        return (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new Stream($json ?: 'null'));
    }
}
