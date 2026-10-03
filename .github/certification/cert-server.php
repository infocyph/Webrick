<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplicationFactory;
use Infocyph\Webrick\Runtime\Http\RuntimeServer;
use Infocyph\Webrick\Runtime\Http\SapiRuntimeAdapter;
use Psr\Log\NullLogger;

const CERT_ENVIRONMENT = 'certification';
const CERT_FINGERPRINT = 'webrick-runtime-release-certification-v1';

/** @return array<string,string|bool|int> */
function certification_arguments(array $argv): array
{
    $arguments = [];
    foreach (array_slice($argv, 2) as $entry) {
        if (!str_starts_with($entry, '--')) {
            continue;
        }
        $pair = explode('=', substr($entry, 2), 2);
        $arguments[$pair[0]] = $pair[1] ?? true;
    }

    return $arguments;
}

/** @return array{intermix:string,router:string} */
function certification_artifacts(string $root): array
{
    $directory = $root . DIRECTORY_SEPARATOR . '.runtime-certification';
    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create runtime certification artifact directory.');
    }

    return [
        'intermix' => $directory . DIRECTORY_SEPARATOR . 'intermix.php',
        'router' => $directory . DIRECTORY_SEPARATOR . 'router.php',
    ];
}

function certification_builder(): ContainerBuilder
{
    $builder = ContainerBuilder::create('webrick.runtime.certification');
    if (method_exists($builder, 'input')) {
        $builder->input(Request::class);
    }
    if (method_exists($builder, 'releaseIdentity')) {
        $builder->releaseIdentity(CERT_FINGERPRINT);
    }

    return $builder;
}

function certification_cleanup_artifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $candidate) {
        if (is_link($candidate) || is_file($candidate)) {
            unlink($candidate);
        }
    }
}

function certification_prepare(string $root): void
{
    $paths = certification_artifacts($root);
    certification_cleanup_artifact($paths['intermix']);
    certification_cleanup_artifact($paths['router']);

    $filePath = dirname($paths['router']) . DIRECTORY_SEPARATOR . 'range-fixture.txt';
    file_put_contents($filePath, str_repeat('0123456789abcdef', 256));

    $builder = certification_builder();
    $build = new RouteCompiler()->compile(
        register: static function (Registrar $registrar): void {
            $registrar->get('/cert/static', static fn(): Response => Response::plaintext('webrick-cert-ok', 200));
            $registrar->get(
                '/cert/dynamic/{id}',
                static fn(string $id): Response => Response::json(['id' => $id]),
            );
            $registrar->get('/cert/json', static function (Request $request): Response {
                return Response::json([
                    'ok' => true,
                    'pid' => getmypid(),
                    'protocol' => $request->getProtocolVersion(),
                    'path' => $request->getUri()->getPath(),
                ]);
            });
            $registrar->get(
                '/cert/stream',
                static fn(): Response => Response::stream(
                    static function (): iterable {
                        yield str_repeat('a', 1024);
                        yield str_repeat('b', 1024);
                        yield str_repeat('c', 1024);
                    },
                ),
            );
            $registrar->get('/cert/file', static function (Request $request) use ($filePath): Response {
                return Response::rangedDownload(
                    $request,
                    $filePath,
                    name: 'range-fixture.txt',
                    mime: 'text/plain',
                );
            });
            $registrar->get('/cert/slow/{ms}', static function (string $ms): Response {
                $milliseconds = max(0, min(2_000, (int) $ms));
                usleep($milliseconds * 1_000);

                return Response::json(['slept_ms' => $milliseconds, 'pid' => getmypid()]);
            });
            $registrar->post('/cert/upload', static function (Request $request): Response {
                return Response::json([
                    'bytes' => strlen((string) $request->getBody()),
                    'pid' => getmypid(),
                ]);
            });
            $registrar->get('/cert/error', static function (): Response {
                throw new RuntimeException('certification error fixture');
            });
        },
        environment: CERT_ENVIRONMENT,
        configFingerprint: CERT_FINGERPRINT,
        preGlobalTags: [],
        postGlobalTags: [],
    );

    $builder->compile($paths['intermix']);
    new RouterArtifactCompiler()->compile($build, $paths['router']);
}

function certification_kernel(string $root): CompiledRouterKernel
{
    $paths = certification_artifacts($root);
    if (!is_file($paths['router']) || (!is_file($paths['intermix']) && !is_link($paths['intermix']))) {
        throw new RuntimeException('Certification artifacts are missing. Run the prepare command first.');
    }

    $builder = certification_builder();
    $container = $builder->production($paths['intermix']);

    return CompiledRouterKernel::fromCompiledArtifact(
        log: new NullLogger(),
        matcher: FusedMatcher::make(),
        container: $container,
        artifactPath: $paths['router'],
        environment: CERT_ENVIRONMENT,
        configFingerprint: CERT_FINGERPRINT,
    );
}

/** @return array<string,mixed> */
function certification_runtime_metrics(HttpRequest $request): array
{
    $runtime = $request->context->runtime();
    $snapshot = $runtime->snapshot();

    return [
        ...$snapshot->toArray(),
        'pid' => getmypid(),
    ];
}

function certification_write_json(ResponseWriterInterface $writer, array $payload): void
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $writer->start(200, Headers::fromArray([
        'Content-Type' => 'application/json',
        'Content-Length' => (string) strlen($json),
    ]));
    $writer->end($json);
}

function certification_runwire(
    string $root,
    string $address,
    ?string $tlsCertificate,
    ?string $tlsKey,
    bool $http3,
    int $recycleRequests,
): void {
    $kernel = certification_kernel($root);
    $server = new RuntimeServer($kernel, new RunwireRuntimeAdapter());
    $factory = new RunwireRuntimeApplicationFactory(
        handler: static function (
            HttpRequest $request,
            ResponseWriterInterface $writer,
        ) use ($server): void {
            $path = parse_url($request->target, PHP_URL_PATH);
            if ($path === '/__cert/metrics') {
                certification_write_json($writer, certification_runtime_metrics($request));

                return;
            }

            $server->handle($request, $writer);
        },
    );

    $definition = Server::httpApplicationFactory($address, $factory, 'webrick-cert')
        ->withWorkers(1);

    if ($tlsCertificate !== null) {
        $definition = $definition->withTls(new TlsOptions(
            localCertificate: $tlsCertificate,
            privateKey: $tlsKey,
            alpnProtocols: ['h2', 'http/1.1'],
        ));
    }
    if ($http3) {
        if ($tlsCertificate === null) {
            throw new RuntimeException('HTTP/3 certification requires TLS certificate and key paths.');
        }
        $definition = $definition->withHttp3(new Http3Options());
    }

    $runtime = Runtime::create(new RuntimeOptions(
        driver: RuntimeDriver::NATIVE,
        workerRecycle: new WorkerRecyclePolicy(
            maxRequests: $recycleRequests,
            gracefulTimeoutSeconds: 15.0,
        ),
    ));
    $runtime->listen($definition)->run();
}

function certification_sapi(string $root): void
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($path === '/__cert/metrics') {
        header('Content-Type: application/json');
        echo json_encode([
            'pid' => getmypid(),
            'memory_current_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'requests_active' => 0,
            'queued_bytes_current' => 0,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return;
    }

    new RuntimeServer(
        certification_kernel($root),
        SapiRuntimeAdapter::current(),
    )->handle();
}

$root = realpath((string) (getenv('WEBRICK_CERT_ROOT') ?: ''));
if (PHP_SAPI === 'cli-server') {
    if ($root === false) {
        throw new RuntimeException('WEBRICK_CERT_ROOT must reference a checkout.');
    }
    require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    certification_sapi($root);

    return;
}

$command = $argv[1] ?? '';
$arguments = certification_arguments($argv);
$rootArgument = (string) ($arguments['root'] ?? getcwd());
$root = realpath($rootArgument);
if ($root === false) {
    throw new RuntimeException('Certification root does not exist.');
}

require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if ($command === 'prepare') {
    certification_prepare($root);
    fwrite(STDOUT, "prepared\n");

    return;
}

if ($command !== 'runwire') {
    throw new RuntimeException('Expected certification command: prepare or runwire.');
}

$address = (string) ($arguments['address'] ?? '127.0.0.1:18081');
$tlsCertificate = isset($arguments['tls-cert']) ? realpath((string) $arguments['tls-cert']) : null;
$tlsKey = isset($arguments['tls-key']) ? realpath((string) $arguments['tls-key']) : null;
$http3 = filter_var($arguments['http3'] ?? false, FILTER_VALIDATE_BOOL);
$recycleRequests = max(0, (int) ($arguments['recycle'] ?? 0));

certification_runwire(
    $root,
    $address,
    $tlsCertificate === false ? null : $tlsCertificate,
    $tlsKey === false ? null : $tlsKey,
    $http3,
    $recycleRequests,
);
