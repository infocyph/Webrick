<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/** Build-plane handler and middleware inspector. */
final class HandlerCompiler
{
    public function compile(CompiledRoute $route): ExecutionPlan
    {
        $handler = $this->normalizeHandler($route->getHandler());
        $middleware = $this->compileMiddlewareList($route->getMiddlewares());
        $reflection = $this->reflect($handler);
        $routeArguments = $this->routeArgumentNames($reflection, $route->getVariables());
        $terminalKind = $this->terminalKind($handler, $reflection, $routeArguments);
        $kind = $middleware === [] ? $terminalKind : ExecutionKind::MIDDLEWARE_PIPELINE;

        return new ExecutionPlan(
            routeId: RouteIdentity::forRoute($route),
            kind: $kind,
            terminalKind: $terminalKind,
            handler: $handler,
            middleware: $middleware,
            routeArguments: $routeArguments,
            capabilities: $this->capabilities(
                $route,
                $reflection,
                $kind,
                $terminalKind,
                $this->middlewareRequiresScope($middleware),
                $routeArguments,
            ),
        );
    }

    /**
     * @param list<mixed> $middleware
     * @return list<mixed>
     */
    public function compileMiddlewareList(array $middleware): array
    {
        $compiled = [];
        foreach ($middleware as $entry) {
            if (is_string($entry)) {
                $alias = strtolower(trim(explode(':', $entry, 2)[0]));
                if ($alias !== '' && MiddlewareAliases::has($alias)) {
                    $entry = MiddlewareAliases::compileString($entry);
                }
            }
            $compiled[] = $this->normalizeMiddlewareDescriptor($entry);
        }

        return $compiled;
    }

    /**
     * @param list<string> $routeArguments
     */
    private function allRequiredParametersAreRouteArguments(ReflectionFunctionAbstract $reflection, array $routeArguments): bool
    {
        $set = array_fill_keys($routeArguments, true);

        return array_all($reflection->getParameters(), fn($parameter) => !(!$parameter->isOptional() && !isset($set[$parameter->getName()])));
    }

    /**
     * @param list<string> $routeArguments
     */
    private function capabilities(
        CompiledRoute $route,
        ReflectionFunctionAbstract $reflection,
        ExecutionKind $kind,
        ExecutionKind $terminalKind,
        bool $middlewareRequiresScope,
        array $routeArguments,
    ): int {
        $mask = 0;
        if ($this->reflectionNeedsRequest($reflection) || $kind === ExecutionKind::MIDDLEWARE_PIPELINE) {
            $mask |= RouteCapability::REQUEST;
        }
        if ($terminalKind === ExecutionKind::COMPILED_INVOKE || $middlewareRequiresScope) {
            $mask |= RouteCapability::SCOPE;
        }
        if ($kind === ExecutionKind::MIDDLEWARE_PIPELINE) {
            $mask |= RouteCapability::MIDDLEWARE;
        }
        if ($route->getDomain() !== null && $route->getDomain() !== '' && $route->getDomain() !== '*') {
            $mask |= RouteCapability::DOMAIN;
        }
        if ($route->getCorsPolicy() !== null) {
            $mask |= RouteCapability::CORS;
        }
        if ($route->getProduces() !== null) {
            $mask |= RouteCapability::PRODUCES;
        }
        if ($routeArguments !== []) {
            $mask |= RouteCapability::ROUTE_ARGS;
        }

        return $mask;
    }

    /** @param list<mixed> $middleware */
    private function middlewareRequiresScope(array $middleware): bool
    {
        return array_any($middleware, fn($descriptor) => !is_callable($descriptor) || (is_string($descriptor) && !function_exists($descriptor)));
    }

    /**
     * @param array{0:object|string,1:string}|string|callable $handler
     * @return array{0:object|string,1:string}|string|callable
     */
    private function normalizeHandler(array|string|callable $handler): array|string|callable
    {
        if (is_array($handler) && is_object($handler[0]) && is_callable($handler)) {
            return Closure::fromCallable($handler);
        }
        if (is_object($handler) && !$handler instanceof Closure) {
            return Closure::fromCallable($handler);
        }
        if (!is_string($handler)) {
            return $handler;
        }

        return $this->normalizeStringHandler($handler);
    }

    private function normalizeMiddlewareDescriptor(mixed $entry): mixed
    {
        if ($entry instanceof RuntimeMiddlewareDescriptor || is_callable($entry)) {
            return $entry;
        }
        if (!is_string($entry)) {
            throw new InvalidArgumentException('Unsupported middleware descriptor at build time.');
        }

        return $this->normalizeStringMiddlewareDescriptor($entry);
    }

    /** @return array{0:string,1:string}|string */
    private function normalizeStringHandler(string $handler): array|string
    {
        foreach (['::', '@'] as $separator) {
            if (!str_contains($handler, $separator)) {
                continue;
            }
            [$class, $method] = explode($separator, $handler, 2);
            $class = trim($class);
            $method = trim($method);
            if ($class === '' || $method === '' || !class_exists($class) || !method_exists($class, $method)) {
                throw new InvalidArgumentException("Invalid route handler '{$handler}'.");
            }

            return [$class, $method];
        }

        if (function_exists($handler)) {
            return $handler;
        }
        if (class_exists($handler) && method_exists($handler, '__invoke')) {
            return [$handler, '__invoke'];
        }

        throw new InvalidArgumentException("Route handler '{$handler}' is not resolvable at build time.");
    }

    /** @return array{0:class-string,1:string}|string */
    private function normalizeStringMiddlewareDescriptor(string $entry): array|string
    {
        if (class_exists($entry) && method_exists($entry, '__invoke')) {
            return [$entry, '__invoke'];
        }
        if (str_contains($entry, '::')) {
            [$class, $method] = explode('::', $entry, 2);
            if ($class !== '' && $method !== '' && class_exists($class) && method_exists($class, $method)) {
                /** @var class-string $class */
                return [$class, $method];
            }
        }
        if (function_exists($entry)) {
            return $entry;
        }

        throw new InvalidArgumentException("Unknown middleware descriptor '{$entry}' during route compilation.");
    }

    private function parameterIsRequest(ReflectionParameter $parameter): bool
    {
        if ($parameter->getName() === 'request') {
            return true;
        }
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        return is_a(Request::class, $type->getName(), true);
    }

    /** @param array{0:object|string,1:string}|string|callable $handler */
    private function reflect(array|string|callable $handler): ReflectionFunctionAbstract
    {
        if ($handler instanceof Closure) {
            return new ReflectionFunction($handler);
        }
        if (is_array($handler)) {
            return new ReflectionMethod($handler[0], $handler[1]);
        }
        if (is_string($handler) && function_exists($handler)) {
            return new ReflectionFunction($handler);
        }
        if (is_object($handler)) {
            return new ReflectionMethod($handler, '__invoke');
        }

        throw new InvalidArgumentException('Unable to inspect route handler during compilation.');
    }

    private function reflectionNeedsRequest(ReflectionFunctionAbstract $reflection): bool
    {
        return array_any(
            $reflection->getParameters(),
            fn(ReflectionParameter $parameter): bool => $this->parameterIsRequest($parameter),
        );
    }

    /**
     * @param list<string> $routeVariables
     * @return list<string>
     */
    private function routeArgumentNames(ReflectionFunctionAbstract $reflection, array $routeVariables): array
    {
        if ($routeVariables === []) {
            return [];
        }

        $available = array_fill_keys($routeVariables, true);
        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (isset($available[$name])) {
                $arguments[] = $name;
            }
        }

        return $arguments;
    }

    /**
     * @param list<string> $routeArguments
     */
    private function terminalKind(
        mixed $handler,
        ReflectionFunctionAbstract $reflection,
        array $routeArguments,
    ): ExecutionKind {
        if (!is_callable($handler)) {
            return ExecutionKind::COMPILED_INVOKE;
        }

        $parameters = $reflection->getParameters();
        if ($parameters === []) {
            return ExecutionKind::DIRECT_ZERO_ARG;
        }
        if (count($parameters) === 1 && $this->parameterIsRequest($parameters[0])) {
            return ExecutionKind::DIRECT_REQUEST;
        }
        if ($routeArguments !== [] && $this->allRequiredParametersAreRouteArguments($reflection, $routeArguments)) {
            return ExecutionKind::DIRECT_ROUTE_ARGS;
        }
        if (array_all($parameters, static fn(ReflectionParameter $parameter): bool => $parameter->isOptional())) {
            return ExecutionKind::DIRECT_ZERO_ARG;
        }

        return ExecutionKind::COMPILED_INVOKE;
    }
}
