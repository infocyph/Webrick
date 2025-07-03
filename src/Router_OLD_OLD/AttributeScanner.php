<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Router\Attributes\Route as RouteAttr;
use Infocyph\Webrick\Router\Attributes\Middleware as MwAttr;
use ReflectionClass;
use ReflectionMethod;
use FilesystemIterator;

/**
 * Very small DFS that loads *.php files from given dirs, reflects
 * classes and registers #[Route] / #[Middleware] attributes.
 *
 * Call **once** during bootstrap:
 * ```php
 * AttributeScanner::scan($router, [__DIR__.'/Http/Controllers']);
 * ```
 */
final class AttributeScanner
{
    /**
     * @param list<string> $dirs
     */
    public static function scan(Router $router, array $dirs): void
    {
        $declaredBefore = get_declared_classes();

        /* 1️⃣  require all PHP files ------------------------------------------------ */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
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

        /* 2️⃣  reflect only freshly-loaded classes ---------------------------------- */
        $newClasses = array_diff(get_declared_classes(), $declaredBefore);

        foreach ($newClasses as $fqcn) {
            $rc  = new ReflectionClass($fqcn);
            $clsMw = [];
            foreach ($rc->getAttributes(MwAttr::class) as $a) {
                $clsMw = array_merge($clsMw, (array) $a->newInstance()->alias);
            }

            /* class-level #[Route] → acts as *prefix* (à la Laravel) */
            $clsPrefix = '';
            foreach ($rc->getAttributes(RouteAttr::class) as $a) {
                $attr = $a->newInstance();
                $clsPrefix = rtrim($attr->path, '/');
                $clsMw     = array_merge($clsMw, $attr->middleware);
            }

            /* ---- per-method #[Route] ------------------------------------------- */
            foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                foreach ($m->getAttributes(RouteAttr::class) as $a) {
                    /** @var RouteAttr $attr */
                    $attr = $a->newInstance();
                    $verbs = (array) $attr->method;

                    foreach ($verbs as $verb) {
                        $r = $router->{$verb}(
                            $clsPrefix . $attr->path,
                            [$fqcn, $m->getName()]
                        )
                            ->middleware([...$clsMw, ...$attr->middleware]);

                        if ($attr->name) {
                            $r->name($attr->name);
                        }
                    }
                }
            }
        }
    }
}
