<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Request\Support\IpCidr;

/** @return array<mixed> */
function staticArrayProperty(string $class, string $property): array
{
    $reflection = new ReflectionProperty($class, $property);
    $value = $reflection->getValue();

    return is_array($value) ? $value : [];
}

function resetStaticArrayProperty(string $class, string $property): void
{
    $reflection = new ReflectionProperty($class, $property);
    $reflection->setValue(null, []);
}

describe('Persistent worker cache bounds', function () {
    afterEach(function () {
        resetStaticArrayProperty(HeaderBag::class, 'normCache');
        resetStaticArrayProperty(IpCidr::class, 'memo');
        resetStaticArrayProperty(Uri::class, 'asciiCache');
        resetStaticArrayProperty(GatewayHardeningMiddleware::class, 'hostRegexCache');
    });

    it('bounds request-derived and configuration caches', function () {
        for ($i = 0; $i < 320; ++$i) {
            new HeaderBag(['X-Audit-' . $i => 'value']);
            new Uri('https://host-' . $i . '.example/path');
            IpCidr::match('10.' . intdiv($i, 256) . '.' . ($i % 256) . '.1', '10.0.0.0/8');
        }

        for ($i = 0; $i < 80; ++$i) {
            new GatewayHardeningMiddleware(trustedHosts: ['host-' . $i . '.example']);
        }

        expect(staticArrayProperty(HeaderBag::class, 'normCache'))->toHaveCount(256)
            ->and(staticArrayProperty(IpCidr::class, 'memo'))->toHaveCount(256)
            ->and(staticArrayProperty(Uri::class, 'asciiCache'))->toHaveCount(256)
            ->and(staticArrayProperty(GatewayHardeningMiddleware::class, 'hostRegexCache'))->toHaveCount(64);
    });
});
