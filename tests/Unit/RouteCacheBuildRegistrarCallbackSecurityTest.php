<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Support\RouteCacheBuildRegistrarCallback;
use Psr\Log\NullLogger;

test('route-cache builder rejects stream wrappers and NUL before including PHP', function (string $routeFile): void {
    $build = new RouteCacheBuildRegistrarCallback(
        userRegister: null,
        routesFile: $routeFile,
        attributeDirs: [],
        attributeClasses: [],
        logger: new NullLogger(),
        baseDir: sys_get_temp_dir(),
        signKey: null,
    );

    expect(fn () => $build(new Registrar(new Collection())))
        ->toThrow(InvalidArgumentException::class, 'local filesystem path');
})->with([
    'php://filter/resource=/etc/passwd',
    'phar://untrusted.phar/routes.php',
    'https://example.invalid/routes.php',
    'file:///tmp/routes.php',
    '//untrusted.invalid/share/routes.php',
    chr(92) . chr(92) . 'untrusted.invalid' . chr(92) . 'share' . chr(92) . 'routes.php',
    sys_get_temp_dir() . '/routes' . chr(0) . '.php',
]);

test('the build-only route file retains its documented lexical scope', function (): void {
    $routeFile = tempnam(sys_get_temp_dir(), 'webrick-routes-');
    if (!is_string($routeFile)) {
        throw new RuntimeException('Unable to create route-source fixture.');
    }
    file_put_contents($routeFile, <<<'PHP'
<?php
if (!$logger instanceof \Psr\Log\LoggerInterface
    || $baseDir !== dirname(__FILE__)
    || $signKey !== null
) {
    throw new \RuntimeException('Route file bindings were not preserved.');
}
$registrarForFile->get('/from-file', static fn(): string => 'ok', 'fixture.from-file');
PHP);

    try {
        $routes = new Collection();
        $build = new RouteCacheBuildRegistrarCallback(
            userRegister: null,
            routesFile: $routeFile,
            attributeDirs: [],
            attributeClasses: [],
            logger: new NullLogger(),
            baseDir: dirname($routeFile),
            signKey: null,
        );
        $build(new Registrar($routes));
        expect($routes->findByName('fixture.from-file'))->not->toBeNull();
    } finally {
        if (is_file($routeFile)) {
            unlink($routeFile);
        }
    }
});
