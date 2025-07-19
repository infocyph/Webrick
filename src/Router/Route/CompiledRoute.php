<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Closure;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * Hot-path DTO consumed by matchers.
 *
 *  – does all heavy work (placeholder parsing, PCRE building) exactly **once**
 *  – now also carries an immutable <segment-spec> array for the new matcher
 *
 * SegmentSpec shape
 * -----------------
 *  • literal : ['type' => 'lit'  , 'val'  => string]
 *  • param   : ['type' => 'param', 'name' => string, 'regex' => string]
 *
 * @psalm-type SegmentSpec = array{type:'lit',val:string}|array{type:'param',name:string,regex:string}
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class CompiledRoute implements RouteInterface
{
    /** auto-incrementing ordinal (declaration order) */
    private static int $autoIdx = 0;

    /** @var callable|class-string */
    private $handler;

    /** @var list<SegmentSpec> */
    private array $segments;

    /** immutable constructor – only `fromRoute()` should call this */
    public function __construct(
        private readonly string       $method,
        private readonly string       $path,
        callable|Closure|string       $handler,
        private readonly ?string      $domain,
        private readonly array        $middleware,
        private readonly ?string      $name,
        private readonly bool         $dynamic,
        private readonly string       $regex,
        private readonly array        $variables,   // ordered capture names
        private readonly int          $index,
        private readonly ?Cors        $corsPolicy,
        array                         $segments,    // <── new
    ) {
        $this->handler  = $handler;
        $this->segments = $segments;
    }

    /* ─────────────── factory ─────────────── */

    /**
     * Compile *once* from the declarative Route into an immutable DTO.
     */
    public static function fromRoute(RouteInterface $route): self
    {
        [ $segments, $vars, $regex, $dynamic ] =
            self::parsePath($route->getPath());

        $mw = \method_exists($route, 'getMiddlewares')
            ? $route->getMiddlewares()
            : $route->getMiddleware();

        $cors = \method_exists($route, 'getCorsPolicy')
            ? $route->getCorsPolicy() : null;

        return new self(
            method     : $route->getMethod(),
            path       : $route->getPath(),
            handler    : $route->getHandler(),
            domain     : $route->getDomain(),
            middleware : $mw,
            name       : $route->getName(),
            dynamic    : $dynamic,
            regex      : $regex,
            variables  : $vars,
            index      : self::$autoIdx++,
            corsPolicy : $cors,
            segments   : $segments,
        );
    }

    /* ─────────────── accessors ─────────────── */

    public function getMethod(): string
    {
        return $this->method;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    public function getHandler(): callable
    {
        return $this->handler;
    }
    public function getDomain(): ?string
    {
        return $this->domain;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getMiddlewares(): array
    {
        return $this->middleware;
    }
    public function getMiddleware(): array
    {
        return $this->middleware;
    } // BC alias
    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }
    public function isDynamic(): bool
    {
        return $this->dynamic;
    }
    public function getRegex(): string
    {
        return $this->regex;
    }
    /** @return list<non-empty-string> */
    public function getVariables(): array
    {
        return $this->variables;
    }
    /** @return list<SegmentSpec> */
    public function getSegments(): array
    {
        return $this->segments;
    }
    public function getPathLength(): int
    {
        return $this->path === '/' ? 0 :
            substr_count(trim($this->path, '/'), '/') + 1;
    }
    public function getIndex(): int
    {
        return $this->index;
    }

    /* ─────────────── internal helper ─────────────── */

    /**
     * Parse the URI **once**:
     *   – build segment table
     *   – build capture-ordered variable list
     *   – build full PCRE (still needed by FastRegexCompiler)
     *
     * @return array{0:list<SegmentSpec>,1:list<string>,2:string,3:bool}
     */
    private static function parsePath(string $path): array
    {
        // completely static?
        if (!str_contains($path, '{')) {
            return [
                /* segments */ [['type' => 'lit', 'val' => $path === '/' ? '' : ltrim($path, '/')]],
                /* vars     */ [],
                /* regex    */ '#\A' . preg_quote($path, '#') . '\z#D',
                /* dynamic  */ false,
            ];
        }

        $segments   = [];
        $vars       = [];
        $patternBuf = [];

        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }

            if (
                \preg_match('/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/', $raw, $m)
            ) {
                [ , $name, $constraint ] = $m + [null, null, null];

                $body = $constraint
                    ? ConstraintRegistry::buildPattern($constraint)
                    : '[^/]+';

                $segments[] = [
                    'type'  => 'param',
                    'name'  => $name,
                    'regex' => '#\A' . $body . '\z#D',
                ];
                $vars[]        = $name;
                $patternBuf[]  = '(' . $body . ')';
                continue;
            }

            // literal
            $segments[] = ['type' => 'lit', 'val' => $raw];
            $patternBuf[] = \preg_quote($raw, '#');
        }

        $regex = '#\A/' . \implode('/', $patternBuf) . '\z#D';

        return [$segments, $vars, $regex, /*dynamic*/ true];
    }
}
