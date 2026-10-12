<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use PhpBench\Attributes as Bench;
use Psr\Log\NullLogger;
use RuntimeException;

/** Repeated complete in-process kernel paths; supporting evidence, not host RPM certification. */
#[Bench\Groups(['kernel', 'middleware', 'security'])]
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
#[Bench\Warmup(1)]
final class SecurityMiddlewareKernelBench
{
    private Request $cached;

    private Request $conditional;

    private RouterKernel $kernel;

    private Request $proxy;

    public function setUp(): void
    {
        $pool = Cache::memory('webrick.benchmark.security');
        $pool->clear();
        $this->kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $registrar): void {
                $registrar->get('/bench', static fn(): Response => Response::json(['ok' => true])
                    ->withHeader('Cache-Control', 'public, max-age=3600'));
            },
            invoker: ContainerBuilder::create('webrick.benchmark.security')->input(Request::class)->build(),
            preGlobal: [
                new GatewayHardeningMiddleware(trustedProxyCidrs: ['10.0.0.0/8'], trustedHosts: ['example.test']),
                new RequestLimitsMiddleware(),
                new ResponseCacheMiddleware($pool, ttlSeconds: 3600, defaultVary: ['Origin', 'Accept', 'Accept-Encoding', 'Accept-Language']),
                new CorsMiddleware(origins: ['https://app.example.test']),
            ],
            registrarOptions: ['autoSlashRedirect' => false, 'exposeUrlServices' => false],
        );
        $this->cached = Request::fake(uri: 'https://example.test/bench', headers: ['Origin' => 'https://app.example.test']);
        $this->conditional = $this->cached->withHeader('If-None-Match', '"other-version"');
        $server = [
            'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/bench', 'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '10.0.0.1', 'HTTP_FORWARDED' => 'for=203.0.113.9;host=example.test;proto=https',
        ];
        $this->proxy = new Request('GET', Uri::fromServerParams($server, trustedProxyFlags: 0), $server, $this->cached->getHeaders());
        foreach ([$this->cached, $this->conditional, $this->proxy] as $request) {
            $response = $this->kernel->handle($request);
            if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== '{"ok":true}'
                || $response->getHeaderLine('Access-Control-Allow-Origin') !== 'https://app.example.test'
            ) {
                throw new RuntimeException('Security middleware kernel benchmark returned an invalid response.');
            }
        }
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchAnonymousCacheHit(): void
    {
        $this->kernel->handle($this->cached);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchConditionalRequest(): void
    {
        $this->kernel->handle($this->conditional);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchTrustedProxyRequest(): void
    {
        $this->kernel->handle($this->proxy);
    }
}
