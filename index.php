<?php

declare(strict_types=1);


require __DIR__ . '/vendor/autoload.php';



$kernel = RouterKernel::boot(
    log      : $container->get(LoggerInterface::class),
    cachePool: $container->get(CacheItemPoolInterface::class),

    // 👇 your compiler closure
    compiler : function (): array {
        // 1. Build Route\Collection, 2. compile(), 3. return CompiledRoute[]
        return $myCompiler->compile($routeCollection);
    },
);

$response = $kernel->handle(Request::fromGlobals());
$response->send();
