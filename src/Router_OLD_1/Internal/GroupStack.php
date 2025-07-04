<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Internal;

/**
 * Cheap, immutable stack used by attribute-scanner **only**.
 * (The fluent Registrar embeds its grouping logic directly.)
 */
final class GroupStack
{
    /** @var list<array{prefix:string,middleware:array,name:string|null,domain:string|null}> */
    private array $stack = [];

    public function push(
        string  $prefix   = '',
        array   $mw       = [],
        ?string $name     = null,
        ?string $domain   = null,
    ): void {
        $this->stack[] = [
            'prefix'     => $prefix,
            'middleware' => $mw,
            'name'       => $name,
            'domain'     => $domain,
        ];
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    /* Flatten current context */
    public function context(): array
    {
        $ctx = ['prefix' => '', 'middleware' => [], 'name' => null, 'domain' => null];

        foreach ($this->stack as $g) {
            if ($g['prefix'])      { $ctx['prefix']     .= '/' . ltrim($g['prefix'], '/'); }
            if ($g['name'])        { $ctx['name']        = trim(($ctx['name'] ? $ctx['name'] . '.' : '') . $g['name'], '.'); }
            if ($g['domain'])      { $ctx['domain']      = $g['domain']; }
            $ctx['middleware'] = array_values(array_unique([...$ctx['middleware'], ...$g['middleware']]));
        }
        $ctx['prefix'] = '/' . ltrim($ctx['prefix'], '/');

        return $ctx;
    }
}
