<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Discovery;

use FilesystemIterator;
use Infocyph\Webrick\Router\Router;
use Infocyph\Webrick\Router\Attributes\Route as RouteAttr;
use Infocyph\Webrick\Router\Attributes\Middleware as MwAttr;
use ReflectionClass;
use ReflectionMethod;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tiny DFS that loads *.php files, reflects classes, and registers
 * #[Route] / #[Middleware] attributes with the supplied {@see Router}.
 *
 * Usage (one-shot, during bootstrap):
 * ```php
 * (new AttributeScanner())->scan($router, [__DIR__ . '/Http/Controllers']);
 * ```
 */
final class AttributeScanner
{
    /**
     * @param Router       $router  target router instance
     * @param list<string> $dirs    absolute paths to search
     */
    public function scan(Router $router, array $dirs): void
    {
        /* 1️⃣  require + record new classes ------------------------------------ */
        $before = get_declared_classes();

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                implode(PATH_SEPARATOR, $dirs),
                FilesystemIterator::SKIP_DOTS
            )
        );

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                require_once $file->getRealPath();
            }
        }

        $fresh = array_diff(get_declared_classes(), $before);

        /* 2️⃣  reflect + register --------------------------------------------- */
        foreach ($fresh as $fqcn) {
            $rc      = new ReflectionClass($fqcn);
            $clsMw   = self::attrsToMiddleware($rc->getAttributes(MwAttr::class));
            $prefix  = '';
            $domain  = null;

            foreach ($rc->getAttributes(RouteAttr::class) as $a) {
                /** @var RouteAttr $attr */
                $attr    = $a->newInstance();
                $prefix  = rtrim($attr->path, '/');
                $clsMw   = [...$clsMw, ...$attr->middleware];
                $domain  = $attr->domain ?: $domain;
            }

            /* per-method #[Route] ------------------------------------------- */
            foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                foreach ($m->getAttributes(RouteAttr::class) as $a) {
                    /** @var RouteAttr $attr */
                    $attr  = $a->newInstance();
                    $verbs = (array) $attr->method;

                    foreach ($verbs as $verb) {
                        $route = $router->{$verb}(
                            $prefix . $attr->path,
                            [$fqcn, $m->getName()]
                        )->middleware([...$clsMw, ...$attr->middleware]);

                        if ($attr->name)   { $route->name($attr->name); }
                        if ($domain)       { $route->withDomain($domain); }
                    }
                }
            }
        }
    }

    /** @param array<\ReflectionAttribute> $attrs */
    private static function attrsToMiddleware(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $a) {
            /** @var MwAttr $inst */
            $inst = $a->newInstance();
            $out  = [...$out, ... (array) $inst->alias];
        }
        return $out;
    }
}
