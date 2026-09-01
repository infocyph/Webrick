<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Psr\Log\LoggerInterface;

/** Build-only route source loader. The caller owns the scoped Router facade binding. */
final readonly class RouteCacheBuildRegistrarCallback
{
    /**
     * @param array<string,string> $attributeDirs
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
        if (is_callable($this->userRegister)) {
            ($this->userRegister)($registrar);
        } elseif ($this->routesFile !== '') {
            if (!is_file($this->routesFile)) {
                throw new \RuntimeException("Routes file not found: {$this->routesFile}");
            }

            $registrarForFile = $registrar;
            $logger = $this->logger;
            $baseDir = $this->baseDir;
            $signKey = $this->signKey;
            require $this->routesFile;
        }

        if ($this->attributeDirs !== []) {
            AttributeRouteLoader::registerFromDirs($registrar, $this->attributeDirs);
        }
        if ($this->attributeClasses !== []) {
            AttributeRouteLoader::register($registrar, $this->attributeClasses);
        }
    }
}
