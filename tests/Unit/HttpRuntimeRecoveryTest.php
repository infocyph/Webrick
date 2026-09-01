<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RoutingControlRenderer;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Router\Runtime\RuntimeStageProfiler;
use Psr\Log\NullLogger;

it('records runtime stages only when explicitly used', function (): void {
    $profiler = new RuntimeStageProfiler();
    $profiler->mark('artifact');
    $profiler->mark('dispatch');

    $nanoseconds = $profiler->nanoseconds();
    $milliseconds = $profiler->milliseconds();

    expect(array_keys($nanoseconds))->toBe(['artifact', 'dispatch'])
        ->and($nanoseconds['artifact'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($nanoseconds['dispatch'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($milliseconds['artifact'])->toBeFloat()
        ->and($milliseconds['dispatch'])->toBeFloat();
});

it('derives an opcache runtime manifest path without replacing tooling json semantics', function (): void {
    expect(ReleaseCompiler::runtimeManifestPath('/tmp/release.json'))->toBe('/tmp/release.php')
        ->and(ReleaseCompiler::runtimeManifestPath('/tmp/release.JSON'))->toBe('/tmp/release.php')
        ->and(ReleaseCompiler::runtimeManifestPath('/tmp/release'))->toBe('/tmp/release.php');
});

it('prefers the php runtime manifest and falls back to json', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-runtime-manifest-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0775, true))->toBeTrue();

    $jsonPath = $directory . '/release.json';
    $phpPath = $directory . '/release.php';
    $intermixDigest = str_repeat('a', 32);
    $webrickDigest = str_repeat('b', 32);
    $base = [
        'format' => 2,
        'environment' => 'json',
        'config_fingerprint' => 'cfg',
        'intermix' => ['path' => '/tmp/intermix.php', 'digest' => $intermixDigest],
        'webrick' => [
            'path' => '/tmp/webrick.php',
            'digest' => $webrickDigest,
            'fingerprint' => $webrickDigest,
        ],
    ];
    $runtime = $base;
    $runtime['environment'] = 'php';

    file_put_contents($jsonPath, json_encode($base, JSON_THROW_ON_ERROR));
    file_put_contents($phpPath, "<?php\nreturn " . var_export($runtime, true) . ";\n");

    try {
        $loader = new ReleaseManifestLoader();
        expect($loader->load($jsonPath)['environment'])->toBe('php');

        unlink($phpPath);
        expect($loader->load($jsonPath)['environment'])->toBe('json');
    } finally {
        if (is_file($phpPath)) {
            unlink($phpPath);
        }
        if (is_file($jsonPath)) {
            unlink($jsonPath);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('rejects the legacy InterMix sha256 release contract', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-legacy-intermix-manifest-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0775, true))->toBeTrue();

    $jsonPath = $directory . '/release.json';
    $digest = str_repeat('b', 32);
    file_put_contents($jsonPath, json_encode([
        'format' => 2,
        'environment' => 'production',
        'config_fingerprint' => 'cfg',
        'intermix' => ['path' => '/tmp/intermix.php', 'sha256' => str_repeat('a', 64)],
        'webrick' => [
            'path' => '/tmp/webrick.php',
            'digest' => $digest,
            'fingerprint' => $digest,
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        expect(fn() => new ReleaseManifestLoader()->load($jsonPath))
            ->toThrow(UnexpectedValueException::class, 'Malformed InterMix release metadata');
    } finally {
        if (is_file($jsonPath)) {
            unlink($jsonPath);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('renders default routing misses without a request or throwable', function (): void {
    $renderer = new RoutingControlRenderer(new NullLogger());
    $routing = new RoutingInput('POST', '/known');
    $response = $renderer->render($routing, MatchOutcome::methodNotAllowed(['GET']));

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(405)
        ->and($response->getHeaderLine('Allow'))->toBe('GET');
});

it('renders a known routing throwable without requiring a throw catch trampoline', function (): void {
    $handler = new ErrorHandler();
    $request = Request::fake(method: 'POST', uri: '/known');
    $error = new MethodNotAllowedException('POST', '/known', ['GET']);

    $response = $handler->renderThrowable($request, $error);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(405)
        ->and($response->getHeaderLine('Allow'))->toContain('GET');
});
