<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use PhpBench\Attributes as Bench;
use Psr\Log\NullLogger;
use RuntimeException;

#[Bench\Groups(['kernel', 'intermix'])]
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
#[Bench\Warmup(1)]
final class KernelDispatchBench
{
    private RouterKernel $closureKernel;

    private RouterKernel $factoryMiddlewareKernel;

    private Request $request;

    private RouterKernel $staticControllerKernel;

    public function setUp(): void
    {
        $this->request = Request::fake(uri: 'http://localhost/bench');
        $closureContainer = Container::instance('webrick.benchmark.closure');
        $closureContainer->unset();
        $this->closureKernel = $this->kernel(
            $closureContainer,
            static fn(): Response => Response::json(['ok' => true]),
        );

        $staticContainer = Container::instance('webrick.benchmark.static');
        $staticContainer->unset();
        $this->staticControllerKernel = $this->kernel(
            $staticContainer,
            [self::class, 'staticResponse'],
        );

        $factoryContainer = Container::instance('webrick.benchmark.factory');
        $factoryContainer->unset();
        $factoryContainer->bindFactory(
            'benchmark.middleware',
            static fn(): Closure => static fn(
                Request $request,
                Closure $next,
            ): Response => $next($request),
            LifetimeEnum::Singleton,
            ['webrick.middleware.pre'],
        );
        $this->factoryMiddlewareKernel = $this->kernel(
            $factoryContainer,
            static fn(): Response => Response::json(['ok' => true]),
        );

        $this->assertSuccessful($this->closureKernel->handle($this->request));
        $this->assertSuccessful($this->staticControllerKernel->handle($this->request));
        $this->assertSuccessful($this->factoryMiddlewareKernel->handle($this->request));
    }

    public static function staticResponse(): Response
    {
        return Response::json(['ok' => true]);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchClosureHandler(): void
    {
        $this->closureKernel->handle($this->request);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchStaticController(): void
    {
        $this->staticControllerKernel->handle($this->request);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchTaggedDirectFactoryMiddleware(): void
    {
        $this->factoryMiddlewareKernel->handle($this->request);
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== '{"ok":true}') {
            throw new RuntimeException('Kernel benchmark fixture returned an invalid response.');
        }
    }

    private function kernel(Container $container, callable|array $handler): RouterKernel
    {
        return RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $registrar) use ($handler): void {
                $registrar->get('/bench', $handler);
            },
            registrarOptions: [
                'autoSlashRedirect' => false,
                'exposeUrlServices' => false,
            ],
            container: $container,
        );
    }
}
