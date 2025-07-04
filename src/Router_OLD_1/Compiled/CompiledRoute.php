<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compiled;

use JsonSerializable;

/**
 * Normal runtime uses `Route` objects directly; this DTO is **only**
 * for CLI tooling (`route:list`) and cache warm-up dumps where a lean,
 * serialisable view is handy.
 */
final class CompiledRoute implements JsonSerializable
{
    /**
     * @param list<string> $verbs
     * @param list<string> $vars
     */
    public function __construct(
        public array  $verbs,
        public string $path,
        public string $regex,
        public array  $vars,
        public ?string $name = null,
        public array  $middleware = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'verbs'      => $this->verbs,
            'path'       => $this->path,
            'regex'      => $this->regex,
            'vars'       => $this->vars,
            'name'       => $this->name,
            'middleware' => $this->middleware,
        ];
    }
}
