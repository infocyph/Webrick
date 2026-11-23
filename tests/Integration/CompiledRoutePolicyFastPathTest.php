<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\RuntimeDispatcher;
use Infocyph\Webrick\Runtime\InterMixRuntime;

test('compiled dispatcher hydrates route policy metadata only for routes that declare it', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-compiled-policies-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create route-policy fixture.');
    }
    $builder = ContainerBuilder::create('webrick.policy.' . bin2hex(random_bytes(4)));
    $routerPath = $directory . '/router.php';
    $intermixPath = $directory . '/intermix.php';
    $cors = new Cors(origins: ['https://client.example.test']);
    $produces = new Produces(types: ['application/json']);

    try {
        $built = new RouteCompiler()->compile(
            register: static function (Registrar $routes) use ($cors, $produces): void {
                $routes->get('/bare', static fn(Request $request): Response => Response::plaintext(
                    $request->getAttribute('cors_policy') === null
                        && $request->getAttribute('produces') === null ? 'bare' : 'unexpected-policy',
                ));
                $routes->get('/cors', static fn(Request $request): Response => Response::plaintext(
                    $request->getAttribute('cors_policy') instanceof Cors
                        && $request->getAttribute('produces') === null ? 'cors' : 'missing-cors',
                ), ['attributes' => ['cors' => $cors]]);
                $routes->get('/produces', static fn(Request $request): Response => Response::plaintext(
                    $request->getAttribute('produces') instanceof Produces
                        && $request->getAttribute('cors_policy') === null ? 'produces' : 'missing-produces',
                ), ['attributes' => ['produces' => $produces]]);
                $routes->get('/both', static fn(Request $request): Response => Response::plaintext(
                    $request->getAttribute('cors_policy') instanceof Cors
                        && $request->getAttribute('produces') instanceof Produces ? 'both' : 'missing-both',
                ), ['attributes' => ['cors' => $cors, 'produces' => $produces]]);
            },
            environment: 'production',
            configFingerprint: 'policy-fast-path',
            preGlobalTags: [],
            postGlobalTags: [],
        );

        $builder->compile($intermixPath);
        $container = $builder->production($intermixPath);
        $compiled = new RouterArtifactCompiler()->compile($built, $routerPath);
        $artifact = new RouterArtifactLoader()->loadPrevalidated(
            $routerPath,
            $compiled['fingerprint'],
            'production',
            'policy-fast-path',
        );
        $dispatcher = new RuntimeDispatcher(new InterMixRuntime($container), $artifact);
        $decoded = new ReflectionProperty($artifact, 'decodedRoutes');
        $pipelines = new ReflectionProperty($dispatcher, 'pipelines');

        foreach (['bare', 'bare', 'cors', 'produces', 'both'] as $offset => $name) {
            $index = match ($name) {
                'bare' => 0,
                'cors' => 1,
                'produces' => 2,
                'both' => 3,
            };
            $response = $dispatcher->dispatch(
                $index,
                $artifact->planForIndex($index),
                Request::fake(uri: '/' . $name),
                [],
            );
            expect((string) $response->getBody())->toBe($name);
            expect($pipelines->getValue($dispatcher))->toBe([]);
            expect($decoded->getValue($artifact))->toHaveCount(
                match ($name) {
                    'bare' => 0,
                    'cors' => 1,
                    'produces' => 2,
                    'both' => 3,
                },
            );
        }
    } finally {
        cleanTestCache($directory);
    }
});


test('compiled route middleware still creates one reusable pipeline without retaining request state', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-compiled-middleware-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create pipeline fixture.');
    }

    try {
        $builder = ContainerBuilder::create('webrick.pipeline.' . bin2hex(random_bytes(4)));
        $built = new RouteCompiler()->compile(
            register: static function (Registrar $routes): void {
                $routes->get('/secured', static fn(Request $request): Response => Response::plaintext(
                    (string) $request->getAttribute('nonce', ''),
                ), [
                    'middleware' => [static function (Request $request, Closure $next): Response {
                        $response = $next($request);
                        if (!$response instanceof Response) {
                            throw new UnexpectedValueException('Pipeline must return Response.');
                        }

                        return $response->withHeader('X-Pipeline-Nonce', (string) $request->getAttribute('nonce', ''));
                    }],
                ]);
            },
            environment: 'production',
            configFingerprint: 'pipeline-fast-path',
            preGlobalTags: [],
            postGlobalTags: [],
        );

        $builder->compile($directory . '/intermix.php');
        $container = $builder->production($directory . '/intermix.php');
        $path = $directory . '/router.php';
        new RouterArtifactCompiler()->compile($built, $path);
        $artifact = new RouterArtifactLoader()->load($path, 'production', 'pipeline-fast-path');
        $dispatcher = new RuntimeDispatcher(new InterMixRuntime($container), $artifact);
        $pipelines = new ReflectionProperty($dispatcher, 'pipelines');

        foreach (['one', 'two'] as $nonce) {
            $response = $dispatcher->dispatch(
                0,
                $artifact->planForIndex(0),
                Request::fake(uri: '/secured')->withAttribute('nonce', $nonce),
                [],
            );
            expect((string) $response->getBody())->toBe($nonce)
                ->and($response->getHeaderLine('X-Pipeline-Nonce'))->toBe($nonce);
            expect($pipelines->getValue($dispatcher))->toHaveCount(1);
        }
    } finally {
        cleanTestCache($directory);
    }
});
