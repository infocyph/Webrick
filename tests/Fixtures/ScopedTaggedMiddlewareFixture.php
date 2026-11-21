<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Closure;
use Fiber;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

final readonly class ScopedTaggedMiddlewareFixture
{
    public function __construct(private Request $captured, private string $label) {}

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        if ($this->label === 'pre' && $request->getAttribute('pause') === true) {
            if (Fiber::suspend($this->captured->getUri()->getPath()) !== null) {
                throw new RuntimeException('Tagged fixture must resume without a replacement value.');
            }
        }
        if ($request->getAttribute('fail') === true) {
            throw new RuntimeException('Tagged fixture failed.');
        }

        return self::wrap($request, $next, $this->label)
            ->withHeader('X-Tagged-' . $this->label, $this->captured->getUri()->getPath());
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function reply(Request $request): Response
    {
        return Response::plaintext($request->getUri()->getPath(), 200);
    }

    /**
     * @throws \RuntimeException
     */
    public static function wrap(Request $request, Closure $next, string $label): Response
    {
        $response = $next($request);
        if (!$response instanceof Response) {
            throw new RuntimeException('Tagged fixture requires a Response.');
        }

        return $response->withBody($label . '[' . (string) $response->getBody() . ']');
    }
}
