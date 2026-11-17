<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Benchmarks\Fixture\ParameterizedRuntimeMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Dispatch\CompiledMiddlewarePipeline;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use PhpBench\Attributes as Bench;
use RuntimeException;

#[Bench\Groups(['kernel', 'intermix', 'middleware', 'foundation-bridge'])]
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
#[Bench\Warmup(1)]
final class RuntimeMiddlewareDescriptorBench
{
    /** Initialize typed fixtures before the benchmark harness invokes them. */
    public function __construct()
    {
        $this->setUp();
    }
    private CompiledMiddlewarePipeline $directPipeline;

    private CompiledMiddlewarePipeline $parameterizedPipeline;

    private Request $request;

    private InterMixRuntime $runtime;

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function setUp(): void
    {
        $this->request = Request::fake(uri: 'http://localhost/bench');
        $this->runtime = new InterMixRuntime(
            ContainerBuilder::create('webrick.benchmark.runtime-middleware')
                ->input(Request::class)
                ->build(),
        );
        $terminal = static fn(Request $request): Response => Response::plaintext($request->getMethod(), 200);

        $this->directPipeline = new CompiledMiddlewarePipeline(
            [static function (Request $request, Closure $next): Response {
                /** @var Closure(Request):Response $next */
                return $next($request);
            }],
            $terminal,
            $this->runtime,
        );
        $this->parameterizedPipeline = new CompiledMiddlewarePipeline(
            [new RuntimeMiddlewareDescriptor(ParameterizedRuntimeMiddleware::class, ['30', '60'])],
            $terminal,
            $this->runtime,
        );

        if ($this->directPipeline->requiresScope() || !$this->parameterizedPipeline->requiresScope()) {
            throw new RuntimeException('Runtime middleware benchmark fixture has an invalid scope contract.');
        }

        $this->assertSuccessful($this->handle($this->directPipeline));
        $this->assertSuccessful($this->handle($this->parameterizedPipeline));
    }

    /**
     * @throws \RuntimeException
     */
    #[Bench\BeforeMethods('setUp')]
    public function benchDirectCallableMiddleware(): void
    {
        $this->handle($this->directPipeline);
    }

    /**
     * @throws \RuntimeException
     */
    #[Bench\BeforeMethods('setUp')]
    public function benchParameterizedRuntimeDescriptor(): void
    {
        $this->handle($this->parameterizedPipeline);
    }

    /**
     * @throws \RuntimeException
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== 'GET') {
            throw new RuntimeException('Runtime middleware benchmark fixture returned an invalid response.');
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function handle(CompiledMiddlewarePipeline $pipeline): Response
    {
        $request = $this->request;
        $response = $this->runtime->withinScope(
            RuntimeRequestContext::REQUEST_SCOPE,
            static fn(): Response => $pipeline->handle($request),
            [Request::class => $request],
        );

        if (!$response instanceof Response) {
            throw new RuntimeException('Runtime middleware benchmark scope returned an invalid response.');
        }

        return $response;
    }
}
