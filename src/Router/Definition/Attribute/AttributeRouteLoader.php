<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use FilesystemIterator;
use Infocyph\Webrick\Router\Definition\Registrar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;

/**
 * Scans directories for PHP classes and registers routes declared via attributes.
 */
final class AttributeRouteLoader
{
    /**
     * Convenience filter: only scan files that look like controllers.
     * Usage: AttributeRouteLoader::registerFromDirs($r, $roots, AttributeRouteLoader::controllerFileFilter());
     *
     * @return callable(SplFileInfo):bool
     */
    public static function controllerFileFilter(): callable
    {
        return static function (SplFileInfo $f): bool {
            $name = $f->getFilename();

            // Typical convention: *Controller.php (still requires PHP extension)
            return str_ends_with($name, 'Controller.php');
        };
    }

    /**
     * Fast path: if you already know the FQCNs, this is the most efficient route.
     *
     * @param list<class-string> $classes
     */
    public static function register(Registrar $registrar, array $classes): void
    {
        // de-dup while preserving order
        $seen = [];
        foreach ($classes as $fqcn) {
            if (isset($seen[$fqcn])) {
                continue;
            }
            $seen[$fqcn] = true;
            self::registerClass($registrar, $fqcn);
        }
    }

    /**
     * Discover classes from PSR-4 roots and register annotated routes.
     *
     * @param array<string,string> $roots e.g. ['App\\Http\\Controllers\\' => __DIR__.'/app/Http/Controllers']
     * @param null|callable(SplFileInfo):bool $filter optional file filter (return true to include the file)
     */
    public static function registerFromDirs(Registrar $registrar, array $roots, ?callable $filter = null): void
    {
        $classes = self::collectAnnotatedClasses($roots, $filter);
        if ($classes !== []) {
            self::register($registrar, $classes);
        }
    }

    /**
     * Build per-route options array for Registrar::add().
     *
     * @param list<class-string|object> $methodMw
     * @return array{
     *   as?:non-empty-string,
     *   middleware:list<class-string|object>,
     *   attributes:array{produces?:Produces,cors?:Cors}
     * }
     */
    private static function buildOptions(Route $rAttr, array $methodMw, ?Produces $prod, ?Cors $cors): array
    {
        $attributes = [];
        if ($prod instanceof Produces) {
            $attributes['produces'] = $prod;
        }
        if ($cors instanceof Cors) {
            $attributes['cors'] = $cors;
        }

        $opts = [
            'middleware' => $methodMw,
            'attributes' => $attributes,
        ];

        if ($rAttr->name !== null && $rAttr->name !== '') {
            $opts['as'] = $rAttr->name; // Registrar supports 'as' or 'name'
        }

        return $opts;
    }

    /* ───────────────────────────── Collect / Resolve ─────────────────────────── */

    /**
     * @param array<string,string> $roots
     * @param null|callable(SplFileInfo):bool $filter
     * @return list<class-string>
     */
    private static function collectAnnotatedClasses(array $roots, ?callable $filter): array
    {
        $out = [];

        foreach ($roots as $nsPrefix => $dir) {
            $dir = rtrim($dir, DIRECTORY_SEPARATOR);
            if (!is_dir($dir)) {
                throw new RuntimeException("Directory not found: {$dir}");
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $f */
            foreach ($it as $f) {
                if (self::shouldSkipFile($f, $filter)) {
                    continue;
                }

                $fqcn = self::fqcnFromFile($nsPrefix, $dir, $f);
                self::loadClassIfNeeded($f, $fqcn);
                $resolvedClass = self::resolveConcreteClass($fqcn);
                if ($resolvedClass === null) {
                    continue;
                }

                $rc = new ReflectionClass($resolvedClass);

                // include classes with class-level OR method-level #[Route] attributes
                if (self::hasRouteRelevantAttributes($rc) || self::hasMethodLevelRoutes($rc)) {
                    $out[] = $resolvedClass;
                }
            }
        }

        // de-dup while preserving discovery order
        $out = array_values(array_unique($out));

        return $out;
    }

    /**
     * @param list<string> $methods
     * @param array{0:class-string,1:non-empty-string} $handler
     * @param array{
     *   as?:non-empty-string,
     *   middleware:list<class-string|object>,
     *   attributes:array{produces?:Produces,cors?:Cors}
     * } $opts
     */
    private static function emitRoutes(
        Registrar $r,
        array $methods,
        string $path,
        array $handler,
        array $opts,
    ): void {
        foreach ($methods as $verb) {
            $call = strtolower($verb);
            if (!method_exists($r, $call)) {
                continue;
            }
            $r->{$call}($path, $handler, $opts);
        }
    }

    private static function fqcnFromFile(string $nsPrefix, string $rootDir, SplFileInfo $f): string
    {
        $rel = substr($f->getPathname(), strlen($rootDir) + 1);
        $base = str_replace(DIRECTORY_SEPARATOR, '\\', substr($rel, 0, -4));

        return rtrim($nsPrefix, '\\') . '\\' . ltrim($base, '\\');
    }

    /**
     * Consider a class eligible if ANY public method has a #[Route] attribute.
     */
    /** @param ReflectionClass<object> $rc */
    private static function hasMethodLevelRoutes(ReflectionClass $rc): bool
    {
        return array_any(
            $rc->getMethods(ReflectionMethod::IS_PUBLIC),
            fn($rm) => $rm->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) !== [],
        );
    }

    /** @param ReflectionClass<object> $rc */
    private static function hasRouteRelevantAttributes(ReflectionClass $rc): bool
    {
        return
            $rc->getAttributes(Route::class) !== []
            || $rc->getAttributes(Group::class) !== []
            || $rc->getAttributes(Middleware::class) !== [];
    }

    /**
     * Try Composer autoload first; if that fails, include the file directly.
     */
    private static function loadClassIfNeeded(SplFileInfo $file, string $fqcn): void
    {
        if (!class_exists($fqcn, true)) { // true → allow autoload
            require_once $file->getPathname();
        }
    }

    /** @param ReflectionClass<object>|ReflectionMethod $ref */
    private static function readCors(ReflectionClass|ReflectionMethod $ref): ?Cors
    {
        $a = $ref->getAttributes(Cors::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return $a ? $a->newInstance() : null;
    }

    /* ───────────────────────────── Attribute readers ────────────────────────── */

    /**
     * @param ReflectionClass<object> $rc
     * @return array{0:string,1:?string,2:list<class-string|object>,3:string}
     */
    private static function readGroup(ReflectionClass $rc): array
    {
        $a = $rc->getAttributes(Group::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if (!$a) {
            return ['', null, [], ''];
        }
        /** @var Group $g */
        $g = $a->newInstance();

        return [$g->prefix ?: '', $g->domain, $g->middleware, $g->name ?: ''];
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $ref
     * @return list<class-string|object>
     */
    private static function readMiddlewares(ReflectionClass|ReflectionMethod $ref): array
    {
        $out = [];
        foreach ($ref->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF) as $a) {
            /** @var Middleware $m */
            $m = $a->newInstance();
            array_push($out, ...$m->stack);
        }

        return $out;
    }

    /** @param ReflectionClass<object>|ReflectionMethod $ref */
    private static function readProduces(ReflectionClass|ReflectionMethod $ref): ?Produces
    {
        $a = $ref->getAttributes(Produces::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return $a ? $a->newInstance() : null;
    }

    /* ───────────────────────────── Registration ─────────────────────────────── */

    /** @param class-string $fqcn */
    private static function registerClass(Registrar $registrar, string $fqcn): void
    {
        $rc = new ReflectionClass($fqcn);

        // Class-level context
        [$grpPrefix, $grpDomain, $grpMw, $grpName] = self::readGroup($rc);
        $classMw = self::readMiddlewares($rc);
        $classProd = self::readProduces($rc);
        $classCors = self::readCors($rc);

        $registrar->group(
            [
                'prefix' => $grpPrefix,
                'domain' => $grpDomain,
                'middleware' => [...$grpMw, ...$classMw],
                'name' => $grpName,
            ],
            function (Registrar $r) use ($rc, $fqcn, $classProd, $classCors) {
                self::registerPublicMethods($r, $rc, $fqcn, $classProd, $classCors);
            },
        );
    }

    /**
     * @param ReflectionClass<object> $rc
     * @param class-string $fqcn
     */
    private static function registerPublicMethods(
        Registrar $r,
        ReflectionClass $rc,
        string $fqcn,
        ?Produces $classProd,
        ?Cors $classCors,
    ): void {
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            $routeAttrs = $rm->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($routeAttrs === []) {
                continue;
            }

            $methodMw = self::readMiddlewares($rm);
            $methProd = self::readProduces($rm) ?? $classProd;
            $methCors = self::readCors($rm) ?? $classCors;

            foreach ($routeAttrs as $attr) {
                /** @var Route $rAttr */
                $rAttr = $attr->newInstance();
                $methods = array_values(array_map(strtoupper(...), (array) $rAttr->method));
                $opts = self::buildOptions($rAttr, $methodMw, $methProd, $methCors);
                /** @var array{0:class-string,1:non-empty-string} $handler */
                $handler = [$fqcn, $rm->getName()];

                self::emitRoutes($r, $methods, $rAttr->path, $handler, $opts);
            }
        }
    }

    /**
     * @return class-string|null
     */
    private static function resolveConcreteClass(string $fqcn): ?string
    {
        if (!class_exists($fqcn, false)) {
            return null;
        }
        /** @var class-string $resolved */
        $resolved = $fqcn;
        $rc = new ReflectionClass($resolved);

        return $rc->isAbstract() || $rc->isInterface() ? null : $resolved;
    }

    /** @param null|callable(SplFileInfo):bool $filter */
    private static function shouldSkipFile(SplFileInfo $file, ?callable $filter): bool
    {
        if ($file->getExtension() !== 'php') {
            return true;
        }

        if (preg_match('~(^|[/\\\\])vendor([/\\\\]|$)~', $file->getPathname()) === 1) {
            return true;
        }

        return $filter !== null && !$filter($file);
    }
}
