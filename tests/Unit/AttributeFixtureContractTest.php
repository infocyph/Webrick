<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Definition\Attribute\Route;
use Infocyph\Webrick\Tests\Fixture\AttrDemoController;

test('attribute-directory fixture stays loadable and exposes the expected handler contract', function (): void {
    require_once dirname(__DIR__) . '/Fixture/AttrDemoController.php';

    $reflection = new ReflectionMethod(AttrDemoController::class, 'hello');
    expect($reflection->getAttributes(Route::class))->not->toBeEmpty();

    $response = new AttrDemoController()->hello('probe');
    expect((string) $response->getBody())->toBe('{"hello":"probe"}');
});
