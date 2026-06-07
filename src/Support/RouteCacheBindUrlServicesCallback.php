<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

final readonly class RouteCacheBindUrlServicesCallback
{
    public function __construct(
        private ?string $signKey,
        private int $signedDefaultTtl,
        private ?SignedUrlConfig $signedUrlConfig,
        private string $urlBaseUri,
    ) {}

    public function __invoke(Collection $routes): void
    {
        Router::bindUrlServices(
            $routes,
            $this->signKey,
            $this->signedDefaultTtl,
            $this->signedUrlConfig,
            $this->urlBaseUri,
        );
    }
}
