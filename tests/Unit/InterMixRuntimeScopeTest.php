<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Webrick\Runtime\InterMixRuntime;

final readonly class WebrickRuntimeScopedMarker
{
    public function __construct(public string $id) {}
}

/** @return array{Container,InterMixRuntime} */
function webrick_runtime_scope_fixture(): array
{
    $container = new Container('webrick.runtime.scope.' . bin2hex(random_bytes(6)));
    $container->definitions()->bind(
        WebrickRuntimeScopedMarker::class,
        static fn(): WebrickRuntimeScopedMarker => new WebrickRuntimeScopedMarker(bin2hex(random_bytes(6))),
        LifetimeEnum::Scoped,
    );

    return [$container, new InterMixRuntime($container)];
}

test('InterMix runtime propagates one logical request scope into structured child work', function (): void {
    [$container, $runtime] = webrick_runtime_scope_fixture();
    $parent = null;
    $child = null;

    try {
        $runtime->withinScope('request', static function (Container $active) use ($runtime, &$parent, &$child): void {
            $parent = $active->get(WebrickRuntimeScopedMarker::class);
            $scopeContext = $runtime->captureScopeContext();

            expect($scopeContext)->toBeInstanceOf(ScopeContext::class);

            $fiber = new Fiber(static function () use ($runtime, $scopeContext): WebrickRuntimeScopedMarker {
                return $runtime->withinScopeContext(
                    $scopeContext,
                    static fn(Container $childContainer): WebrickRuntimeScopedMarker => $childContainer->get(
                        WebrickRuntimeScopedMarker::class,
                    ),
                );
            });
            $fiber->start();
            $child = $fiber->getReturn();
        });

        expect($parent)->toBeInstanceOf(WebrickRuntimeScopedMarker::class)
            ->and($child)->toBe($parent);
    } finally {
        $runtime->resetCurrentExecutionScope();
        $container->unset();
    }
});

test('InterMix runtime propagates explicit scope context through Runwire task locals only when attached', function (): void {
    [$container, $runtime] = webrick_runtime_scope_fixture();

    try {
        $runtime->withinScope('request', static function (Container $active) use ($runtime): void {
            $parent = $active->get(WebrickRuntimeScopedMarker::class);
            $scopeContext = $runtime->captureScopeContext();
            $scopeLocal = new TaskLocal();

            $resolved = new CoroutineRuntime()->run(
                static function (CoroutineScope $scope) use ($runtime, $scopeContext, $scopeLocal): array {
                    $scope->setLocal($scopeLocal, $scopeContext);
                    $spawnAttached = static function () use ($runtime, $scope, $scopeLocal) {
                        return $scope->spawn(static function () use ($runtime, $scope, $scopeLocal): WebrickRuntimeScopedMarker {
                            $captured = $scope->local($scopeLocal);
                            if (!$captured instanceof ScopeContext) {
                                throw new RuntimeException('Runwire task-local scope context was not inherited.');
                            }

                            return $runtime->withinScopeContext(
                                $captured,
                                static fn(Container $childContainer): WebrickRuntimeScopedMarker => $childContainer->get(
                                    WebrickRuntimeScopedMarker::class,
                                ),
                            );
                        });
                    };

                    $first = $spawnAttached();
                    $second = $spawnAttached();
                    $isolated = $scope->spawn(static function () use ($runtime): bool {
                        try {
                            $runtime->captureScopeContext();

                            return false;
                        } catch (ContainerException) {
                            return true;
                        }
                    });

                    return [$first->await(), $second->await(), $isolated->await()];
                },
            );

            expect($resolved[0])->toBe($parent)
                ->and($resolved[1])->toBe($parent)
                ->and($resolved[2])->toBeTrue();
        });
    } finally {
        $runtime->resetCurrentExecutionScope();
        $container->unset();
    }
});

test('InterMix runtime keeps independent fiber request scopes isolated when no context is propagated', function (): void {
    [$container, $runtime] = webrick_runtime_scope_fixture();

    $makeFiber = static fn(): Fiber => new Fiber(
        static fn(): WebrickRuntimeScopedMarker => $runtime->withinScope(
            'request',
            static function (Container $active): WebrickRuntimeScopedMarker {
                $marker = $active->get(WebrickRuntimeScopedMarker::class);
                Fiber::suspend($marker);

                return $active->get(WebrickRuntimeScopedMarker::class);
            },
        ),
    );

    try {
        $fiberA = $makeFiber();
        $fiberB = $makeFiber();
        $firstA = $fiberA->start();
        $firstB = $fiberB->start();

        $fiberB->resume();
        $fiberA->resume();

        expect($firstA)->toBeInstanceOf(WebrickRuntimeScopedMarker::class)
            ->and($firstB)->toBeInstanceOf(WebrickRuntimeScopedMarker::class)
            ->and($firstA)->not->toBe($firstB)
            ->and($fiberA->getReturn())->toBe($firstA)
            ->and($fiberB->getReturn())->toBe($firstB);
    } finally {
        $runtime->resetCurrentExecutionScope();
        $container->unset();
    }
});

test('InterMix runtime detaches propagated scope context when child work throws', function (): void {
    [$container, $runtime] = webrick_runtime_scope_fixture();

    try {
        $runtime->withinScope('request', static function (Container $active) use ($runtime): void {
            $parent = $active->get(WebrickRuntimeScopedMarker::class);
            $scopeContext = $runtime->captureScopeContext();
            $failingChild = new Fiber(static function () use ($runtime, $scopeContext): void {
                $runtime->withinScopeContext(
                    $scopeContext,
                    static function (Container $childContainer): never {
                        $childContainer->get(WebrickRuntimeScopedMarker::class);
                        throw new RuntimeException('structured-child-failure');
                    },
                );
            });

            expect(fn(): mixed => $failingChild->start())
                ->toThrow(RuntimeException::class, 'structured-child-failure');

            $replacementChild = new Fiber(static function () use ($runtime, $scopeContext): WebrickRuntimeScopedMarker {
                return $runtime->withinScopeContext(
                    $scopeContext,
                    static fn(Container $childContainer): WebrickRuntimeScopedMarker => $childContainer->get(
                        WebrickRuntimeScopedMarker::class,
                    ),
                );
            });
            $replacementChild->start();

            expect($replacementChild->getReturn())->toBe($parent);
        });
    } finally {
        $runtime->resetCurrentExecutionScope();
        $container->unset();
    }
});

test('InterMix runtime reset is idempotent and removes leaked carrier-local scope state', function (): void {
    [$container, $runtime] = webrick_runtime_scope_fixture();

    try {
        $container->enterScope('request');
        expect($runtime->captureScopeContext())->toBeInstanceOf(ScopeContext::class);

        $runtime->resetCurrentExecutionScope();
        $runtime->resetCurrentExecutionScope();

        expect(fn(): ScopeContext => $runtime->captureScopeContext())
            ->toThrow(ContainerException::class, 'Cannot capture a scope context without an active scope.');
    } finally {
        $runtime->resetCurrentExecutionScope();
        $container->unset();
    }
});
