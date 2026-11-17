<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Response\Response;

final readonly class IntegrationDemoController
{
    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function hello(string $name): Response
    {
        return Response::json([
            'handler' => 'DemoController::hello',
            'hello' => $name,
            'time' => \date(DATE_ATOM),
        ]);
    }
}
