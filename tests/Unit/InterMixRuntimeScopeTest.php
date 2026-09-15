<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\Webrick\Runtime\InterMixRuntime;

final readonly class WebrickRuntimeScopedMarker
{
    public function __construct(public string $id) {}
}

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
