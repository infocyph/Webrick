<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
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

        $this->closureKernel = $this->kernel(
            ContainerBuilder::create('webrick.benchmark.closure')->input(Request::class)->build(),
            static fn(): Response => Response::json(['ok' => true]),
        );

        $this->staticControllerKernel = $this->kernel(
            ContainerBuilder::create('webrick.benchmark.static')->input(Request::class)->build(),
            [self::class, 'staticResponse'],
        );

        $factoryBuilder = ContainerBuilder::create('webrick.benchmark.factory')
            ->input(Request::class);
        $factoryBuilder->factory(
            'benchmark.middleware',
            static fn(): Closure => static fn(
                Request $request,
                Closure $next,
            ): Response => $next($request),
            LifetimeEnum::Singleton,
            ['webrick.middleware.pre'],
        );
        $factoryContainer = $factoryBuilder->build();
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

    private function kernel(RuntimeContainerInterface $container, callable|array $handler): RouterKernel
    {
        return RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: static function (Registrar $registrar) use ($handler): void {
                $registrar->get('/bench', $handler);
            },
            invoker: $container,
            registrarOptions: [
                'autoSlashRedirect' => false,
                'exposeUrlServices' => false,
            ],
        );
    }
}
