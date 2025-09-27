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
     * Register routes from a list of annotated controller classes.
     *
     * @param Registrar $registrar
     * @param list<class-string> $classes
     */
    public static function register(Registrar $registrar, array $classes): void
    {
        foreach ($classes as $fqcn) {
            self::registerClass($registrar, $fqcn);
        }
    }
    /**
     * Discover classes from PSR-4 roots and register annotated routes.
     *
     * @param Registrar $registrar
     * @param array<string,string> $roots e.g. ['App\\Http\\Controllers\\' => __DIR__.'/app/Http/Controllers']
     * @param null|callable(\SplFileInfo):bool $filter optional file filter
     */
    public static function registerFromDirs(Registrar $registrar, array $roots, ?callable $filter = null): void
    {
        $classes = self::collectAnnotatedClasses($roots, $filter);
        if ($classes !== []) {
            self::register($registrar, $classes);
        }
    }

    /**
     * @return array{name?:string,as?:string,middleware:array,attributes:array}
     */
    private static function buildOptions(Route $rAttr, array $methodMw, ?Produces $prod, ?Cors $cors): array
    {
        $opts = [
            'middleware' => $methodMw,
            'attributes' => array_filter([
                'produces' => $prod,
                'cors' => $cors,
            ]),
        ];

        if ($rAttr->name !== null && $rAttr->name !== '') {
            $opts['as'] = $rAttr->name; // Registrar supports 'as' or 'name'
        }

        return $opts;
    }

    /* ───────────────────────────── Collect / Resolve ─────────────────────────── */

    /**
     * @param array<string,string> $roots
     * @param null|callable(\SplFileInfo):bool $filter
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
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                if ($filter && !$filter($f)) {
                    continue;
                }

                $fqcn = self::fqcnFromFile($nsPrefix, $dir, $f);
                if ($fqcn === null) {
                    continue;
                }

                self::loadClassIfNeeded($f, $fqcn);
                if (!self::shouldLoadClass($fqcn)) {
                    continue;
                }

                $rc = new ReflectionClass($fqcn);
                if (self::hasRouteRelevantAttributes($rc)) {
                    $out[] = $fqcn;
                }
            }
        }

        return $out;
    }

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
                // Unknown HTTP verb helper; skip gracefully.
                continue;
            }
            $r->{$call}($path, $handler, $opts);
        }
    }

    private static function fqcnFromFile(string $nsPrefix, string $rootDir, SplFileInfo $f): ?string
    {
        $rel = substr($f->getPathname(), strlen($rootDir) + 1);
        if ($rel === false) {
            return null;
        }

        $base = str_replace(DIRECTORY_SEPARATOR, '\\', substr($rel, 0, -4));
        return rtrim($nsPrefix, '\\') . '\\' . ltrim($base, '\\');
    }

    private static function hasRouteRelevantAttributes(ReflectionClass $rc): bool
    {
        return
            $rc->getAttributes(Route::class) !== [] ||
            $rc->getAttributes(Group::class) !== [] ||
            $rc->getAttributes(Middleware::class) !== [];
    }

    private static function loadClassIfNeeded(SplFileInfo $file, string $fqcn): void
    {
        if (!class_exists($fqcn, false)) {
            require_once $file->getPathname();
        }
    }

    /** @param ReflectionClass|ReflectionMethod $ref */
    private static function readCors(object $ref): ?Cors
    {
        $a = $ref->getAttributes(Cors::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        return $a ? $a->newInstance() : null;
    }

    /* ───────────────────────────── Attribute readers ────────────────────────── */

    /** @return array{0:string,1:?string,2:list<class-string|object>,3:string} */
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

    /** @param ReflectionClass|ReflectionMethod $ref @return list<class-string|object> */
    private static function readMiddlewares(object $ref): array
    {
        $out = [];
        foreach ($ref->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF) as $a) {
            /** @var Middleware $m */
            $m = $a->newInstance();
            array_push($out, ...$m->stack);
        }
        return $out;
    }

    /** @param ReflectionClass|ReflectionMethod $ref */
    private static function readProduces(object $ref): ?Produces
    {
        $a = $ref->getAttributes(Produces::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        return $a ? $a->newInstance() : null;
    }

    /* ───────────────────────────── Registration ─────────────────────────────── */

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
                'middleware' => array_values(array_filter([...$grpMw, ...$classMw])),
                'name' => $grpName,
            ],
            function (Registrar $r) use ($rc, $fqcn, $classProd, $classCors) {
                self::registerPublicMethods($r, $rc, $fqcn, $classProd, $classCors);
            },
        );
    }

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
                $methods = array_map('strtoupper', (array)$rAttr->method);
                $opts = self::buildOptions($rAttr, $methodMw, $methProd, $methCors);
                $handler = [$fqcn, $rm->getName()];

                self::emitRoutes($r, $methods, $rAttr->path, $handler, $opts);
            }
        }
    }

    private static function shouldLoadClass(string $fqcn): bool
    {
        if (!class_exists($fqcn)) {
            return false;
        }
        $rc = new ReflectionClass($fqcn);
        return !($rc->isAbstract() || $rc->isInterface());
    }
}
