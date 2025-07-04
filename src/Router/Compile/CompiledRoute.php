<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compile;

use JsonSerializable;

/**
 * Lightweight DTO for CLI tooling & cache snapshots.
 */
final class CompiledRoute implements JsonSerializable
{
    /**
     * @param list<string> $verbs
     * @param list<string> $vars
     * @param list<string|object> $middleware
     */
    public function __construct(
        public readonly array   $verbs,
        public readonly string  $path,
        public readonly string  $regex,
        public readonly array   $vars,
        public readonly ?string $name        = null,
        public readonly array   $middleware  = [],
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
