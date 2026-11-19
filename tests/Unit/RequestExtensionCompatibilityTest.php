<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Tests\Fixture\LegacyProxyFlagRequest;

test('request accepts the untyped public proxy flag overrides supported in 6.0.4', function (string $name, int $value): void {
    expect(constant(LegacyProxyFlagRequest::class . '::' . $name))->toBe($value)
        ->and(constant(Request::class . '::' . $name))->toBe($value);
})->with([
    ['HEADER_FORWARDED', 16],
    ['HEADER_X_FORWARDED_FOR', 1],
    ['HEADER_X_FORWARDED_HOST', 2],
    ['HEADER_X_FORWARDED_PORT', 8],
    ['HEADER_X_FORWARDED_PROTO', 4],
]);
