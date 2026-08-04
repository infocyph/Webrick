<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Support\RouteCache;
use PhpBench\Attributes as Bench;
use Psr\Log\NullLogger;

#[Bench\Groups(['cache', 'boot'])]
#[Bench\Iterations(5)]
#[Bench\Revs(10)]
#[Bench\Warmup(1)]
final class CacheLifecycleBench
{
    private string $directory;

    public function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-bench-' . uniqid('', true);
        mkdir($this->directory);
        foreach (['fused', 'generated', 'sharded'] as $mode) {
            $this->build($mode);
        }
    }

    public function tearDown(): void
    {
        RouteCache::clear([
            'matcher' => 'sharded',
            'cache' => $this->directory,
            'aggressive' => true,
        ]);
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchFusedCacheBuild(): void
    {
        $this->build('fused');
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchFusedCachedBoot(): void
    {
        $this->boot(FusedMatcher::make(), $this->path('fused'));
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchFusedCachedFirstRequest(): void
    {
        $this->firstRequest(FusedMatcher::make(), $this->path('fused'));
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchGeneratedCacheBuild(): void
    {
        $this->build('generated');
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchGeneratedCachedBoot(): void
    {
        $this->boot(GeneratedMatcher::make(), $this->path('generated'));
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchGeneratedCachedFirstRequest(): void
    {
        $this->firstRequest(GeneratedMatcher::make(), $this->path('generated'));
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchShardedCacheBuild(): void
    {
        $this->build('sharded');
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchShardedCachedBoot(): void
    {
        $this->boot(ShardedMatcher::make(), $this->directory);
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\AfterMethods('tearDown')]
    public function benchShardedCachedFirstRequest(): void
    {
        $this->firstRequest(ShardedMatcher::make(), $this->directory);
    }

    private function boot(MatcherInterface $matcher, string $cache): void
    {
        RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $matcher,
            register: static function (): void {},
            routeCache: $cache,
            fallbackAliasesFromRegistrar: false,
            requestScopeEnabled: false,
        );
    }

    private function build(string $mode): void
    {
        RouteCache::build([
            'matcher' => $mode,
            'cache' => $this->path($mode),
            'register' => static function (Registrar $registrar): void {
                $registrar->get('/bench', [KernelDispatchBench::class, 'staticResponse']);
                $registrar->get('/bench/{id}', [KernelDispatchBench::class, 'staticResponse']);
            },
        ]);
    }

    private function firstRequest(MatcherInterface $matcher, string $cache): void
    {
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $matcher,
            register: static function (): void {},
            routeCache: $cache,
            fallbackAliasesFromRegistrar: false,
            requestScopeEnabled: false,
        );
        $response = $kernel->handle(Request::fake(uri: 'http://localhost/bench'));
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Cached first-request benchmark did not match its route.');
        }
    }

    private function path(string $mode): string
    {
        return match ($mode) {
            'fused' => $this->directory . DIRECTORY_SEPARATOR . '__routes.php',
            'generated' => $this->directory . DIRECTORY_SEPARATOR . '__generated.php',
            default => $this->directory,
        };
    }
}
