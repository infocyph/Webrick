<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Psr\Log\LoggerInterface;

final readonly class RouteCacheBuildRegistrarCallback
{
    /**
     * @param array<string, string> $attributeDirs
     * @param list<class-string> $attributeClasses
     */
    public function __construct(
        private mixed $userRegister,
        private string $routesFile,
        private array $attributeDirs,
        private array $attributeClasses,
        private LoggerInterface $logger,
        private string $baseDir,
        private ?string $signKey,
    ) {}

    public function __invoke(Registrar $registrar): void
    {
        $signUrlSecret = $this->signKey;
        $cwd = getcwd();
        if ($this->baseDir !== '' && \chdir($this->baseDir) === false) {
            $this->logger->warning('[routecache] failed to chdir to baseDir; continuing', ['baseDir' => $this->baseDir]);
        }

        try {
            if ($this->userRegister) {
                if (!\is_callable($this->userRegister)) {
                    throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
                }
                ($this->userRegister)($registrar);
            } else {
                /** @psalm-suppress UnresolvableInclude */
                require $this->routesFile;
            }

            if ($this->attributeDirs !== []) {
                AttributeRouteLoader::registerFromDirs($registrar, $this->attributeDirs);
            }
            if ($this->attributeClasses !== []) {
                AttributeRouteLoader::register($registrar, $this->attributeClasses);
            }
        } finally {
            if ($cwd !== false) {
                \chdir($cwd);
            }
        }
    }
}
