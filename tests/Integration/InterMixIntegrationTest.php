<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\View\ViewFactoryInterface;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

if (! class_exists('InterMixFreshController', false)) {
    final readonly class InterMixFreshController
    {
        public function hello(string $name): Response
        {
            return Response::json(['hello' => $name]);
        }
    }
}

if (! class_exists('InterMixMiddlewareDependency', false)) {
    final readonly class InterMixMiddlewareDependency
    {
        public function __construct(public string $marker) {}
    }
}

if (! class_exists('InterMixNeedsDiMiddleware', false)) {
    final readonly class InterMixNeedsDiMiddleware
    {
        public function __construct(private InterMixMiddlewareDependency $dep) {}

        public function __invoke(Request $request, Closure $next): Response
        {
            return $next($request)->withHeader('X-DI-Marker', $this->dep->marker);
        }
    }
}

if (! class_exists('InterMixProvidedService', false)) {
    final readonly class InterMixProvidedService
    {
        public function __construct(public string $value) {}
    }
}

if (! class_exists('InterMixScopedMarker', false)) {
    final readonly class InterMixScopedMarker
    {
        public function __construct(public string $id) {}
    }
}

if (! class_exists('InterMixTaggedPreMiddleware', false)) {
    final readonly class InterMixTaggedPreMiddleware
    {
        public function __invoke(Request $request, Closure $next): Response
        {
            return $next($request)->withHeader('X-Tagged-Pre', 'yes');
        }
    }
}

if (! class_exists('InterMixTaggedPostMiddleware', false)) {
    final readonly class InterMixTaggedPostMiddleware
    {
        public function __invoke(Request $request, Closure $next): Response
        {
            return $next($request)->withHeader('X-Tagged-Post', 'yes');
        }
    }
}

if (! class_exists('InterMixTestProvider', false)) {
    final readonly class InterMixTestProvider implements ServiceProviderInterface
    {
        public function register(Container $container): void
        {
            $container->definitions()->bind(
                InterMixProvidedService::class,
                new InterMixProvidedService('from-provider'),
                LifetimeEnum::Singleton,
            );

            $container->definitions()->bind(
                InterMixScopedMarker::class,
                static fn () => new InterMixScopedMarker(\bin2hex(\random_bytes(6))),
                LifetimeEnum::Scoped,
            );

            $container->definitions()->bind(
                'webrick.mw.pre',
                InterMixTaggedPreMiddleware::class,
                LifetimeEnum::Transient,
                ['webrick.middleware.pre'],
            );

            $container->definitions()->bind(
                'webrick.mw.post',
                InterMixTaggedPostMiddleware::class,
                LifetimeEnum::Transient,
                ['webrick.middleware.post'],
            );
        }
    }
}

/**
 * @param  array<string,mixed>  $options
 */
function intermixKernelForTest(Closure $register, array $preGlobal = [], array $options = []): RouterKernel
{
    $defaults = [
        'serviceProviders' => [],
        'preGlobalTags' => ['webrick.middleware.pre'],
        'postGlobalTags' => ['webrick.middleware.post'],
        'requestScopeEnabled' => true,
        'container' => null,
        'invoker' => null,
    ];
    $opts = $options + $defaults;

    return RouterKernel::bootWithRegistrar(
        log: new NullLogger,
        matcher: FusedMatcher::make(),
        register: $register,
        routeCache: null,
        registrarOptions: [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => false,
        ],
        preGlobal: $preGlobal,
        postGlobal: [],
        serviceProviders: $opts['serviceProviders'],
        preGlobalTags: $opts['preGlobalTags'],
        postGlobalTags: $opts['postGlobalTags'],
        requestScopeEnabled: $opts['requestScopeEnabled'],
        container: $opts['container'],
        invoker: $opts['invoker'],
    );
}

describe('InterMix integration', function () {
    beforeEach(function () {
        MiddlewareAliases::reset();
        Container::instance('intermix')->unset();
    });

    afterEach(function () {
        MiddlewareAliases::reset();
    });

    it('does not reuse class-method route responses across requests', function () {
        $kernel = intermixKernelForTest(static function (Registrar $r): void {
            $r->get('/class/rest/{name}', [InterMixFreshController::class, 'hello']);
        });

        $r1 = $kernel->handle(mockRequest('GET', '/class/rest/Alice'));
        $r2 = $kernel->handle(mockRequest('GET', '/class/rest/Bob'));

        $b1 = json_decode((string) $r1->getBody(), true);
        $b2 = json_decode((string) $r2->getBody(), true);

        expect($r1)->not->toBe($r2)
            ->and($b1['hello'] ?? null)->toBe('Alice')
            ->and($b2['hello'] ?? null)->toBe('Bob');
    });

    it('resolves middleware class-strings through InterMix DI', function () {
        Container::instance('intermix')
            ->definitions()
            ->bind(
                InterMixMiddlewareDependency::class,
                new InterMixMiddlewareDependency('wired-class'),
                LifetimeEnum::Singleton,
            );

        $kernel = intermixKernelForTest(
            static function (Registrar $r): void {
                $r->get('/di/class', static fn () => Response::json(['ok' => true]));
            },
            preGlobal: [InterMixNeedsDiMiddleware::class],
        );

        $response = $kernel->handle(mockRequest('GET', '/di/class'));

        expect($response)
            ->toHaveStatus(200)
            ->and($response->getHeaderLine('X-DI-Marker'))->toBe('wired-class');
    });

    it('resolves alias class-strings through InterMix DI', function () {
        Container::instance('intermix')
            ->definitions()
            ->bind(
                InterMixMiddlewareDependency::class,
                new InterMixMiddlewareDependency('wired-alias'),
                LifetimeEnum::Singleton,
            );

        MiddlewareAliases::register('di_alias', InterMixNeedsDiMiddleware::class);

        $kernel = intermixKernelForTest(static function (Registrar $r): void {
            $r->get('/di/alias', static fn () => Response::json(['ok' => true]), [
                'middleware' => ['di_alias'],
            ]);
        });

        $response = $kernel->handle(mockRequest('GET', '/di/alias'));

        expect($response)
            ->toHaveStatus(200)
            ->and($response->getHeaderLine('X-DI-Marker'))->toBe('wired-alias');
    });

    it('uses the same container for route DI and Response::view', function () {
        $kernel = intermixKernelForTest(static function (Registrar $r): void {
            $r->get('/view', static function (ContainerInterface $container): Response {
                if (! $container instanceof Container) {
                    throw new RuntimeException('Expected InterMix container instance.');
                }

                $container->definitions()->bind(
                    ViewFactoryInterface::class,
                    new class implements ViewFactoryInterface
                    {
                        public function render(string $view, array $data = []): string
                        {
                            return "<h1>{$view}: ".($data['name'] ?? 'n/a').'</h1>';
                        }
                    },
                    LifetimeEnum::Singleton,
                );

                return Response::view('hello', ['name' => 'Ada']);
            });
        });

        $response = $kernel->handle(mockRequest('GET', '/view'));

        expect($response)
            ->toHaveStatus(200)
            ->and((string) $response->getBody())->toContain('hello: Ada');
    });

    it('imports service providers during kernel boot', function () {
        $kernel = intermixKernelForTest(
            static function (Registrar $r): void {
                $r->get('/provider', static function (InterMixProvidedService $service): Response {
                    return Response::json(['value' => $service->value]);
                });
            },
            options: [
                'serviceProviders' => [InterMixTestProvider::class],
            ],
        );

        $response = $kernel->handle(mockRequest('GET', '/provider'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response)
            ->toHaveStatus(200)
            ->and($body['value'] ?? null)->toBe('from-provider');
    });

    it('auto-composes tagged global middleware from InterMix container', function () {
        $kernel = intermixKernelForTest(
            static function (Registrar $r): void {
                $r->get('/tagged', static fn () => Response::json(['ok' => true]));
            },
            options: [
                'serviceProviders' => [InterMixTestProvider::class],
            ],
        );

        $response = $kernel->handle(mockRequest('GET', '/tagged'));

        expect($response)
            ->toHaveStatus(200)
            ->and($response->getHeaderLine('X-Tagged-Pre'))->toBe('yes')
            ->and($response->getHeaderLine('X-Tagged-Post'))->toBe('yes');
    });

    it('creates a fresh scoped service instance per request', function () {
        $kernel = intermixKernelForTest(
            static function (Registrar $r): void {
                $r->get('/scope', static function (InterMixScopedMarker $marker): Response {
                    return Response::json(['scope_id' => $marker->id]);
                });
            },
            options: [
                'serviceProviders' => [InterMixTestProvider::class],
                'preGlobalTags' => [],
                'postGlobalTags' => [],
            ],
        );

        $r1 = $kernel->handle(mockRequest('GET', '/scope'));
        $r2 = $kernel->handle(mockRequest('GET', '/scope'));

        $b1 = json_decode((string) $r1->getBody(), true);
        $b2 = json_decode((string) $r2->getBody(), true);

        expect($r1)->toHaveStatus(200)
            ->and($r2)->toHaveStatus(200)
            ->and($b1['scope_id'] ?? null)->not->toBeNull()
            ->and($b2['scope_id'] ?? null)->not->toBeNull()
            ->and($b1['scope_id'] ?? null)->not->toBe($b2['scope_id'] ?? null);
    });
});
