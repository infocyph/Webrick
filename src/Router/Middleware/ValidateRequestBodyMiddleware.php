<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Infocyph\Webrick\Router\Attributes\RequestSchema;
use Infocyph\Webrick\Router\Validation\JsonRequestValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;

final class ValidateRequestBodyMiddleware implements MiddlewareInterface
{
    public function __construct(private string $controller, private string $method) {}

    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        $rm = new ReflectionMethod($this->controller, $this->method);
        $attr = $rm->getAttributes(RequestSchema::class)[0] ?? null;

        if ($attr) {
            $dto = $attr->newInstance()->class;
            (new JsonRequestValidator())->assertValid($r, $dto);
        }
        return $h->handle($r);
    }
}
