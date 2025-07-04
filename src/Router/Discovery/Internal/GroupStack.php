<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Discovery\Internal;

/**
 * Minimal immutable helper used only by the attribute scanner
 * when nested #[Group] support arrives. (Kept for bw-compat.)
 */
final class GroupStack
{
    /** @var list<array{prefix:string,mw:list<string>,name:string|null,domain:string|null}> */
    private array $stack = [];

    public function push(
        string  $prefix = '',
        array   $mw     = [],
        ?string $name   = null,
        ?string $domain = null,
    ): void {
        $this->stack[] = compact('prefix', 'mw', 'name', 'domain');
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    /** Flattens current context. @return array{prefix:string,mw:list<string>,name:string|null,domain:string|null} */
    public function context(): array
    {
        $ctx = ['prefix'=>'', 'mw'=>[], 'name'=>null, 'domain'=>null];

        foreach ($this->stack as $g) {
            if ($g['prefix'])   { $ctx['prefix'] .= '/' . ltrim($g['prefix'], '/'); }
            $ctx['mw']     = array_values(array_unique([...$ctx['mw'], ...$g['mw']]));
            $ctx['name']   = $g['name']   ?? $ctx['name'];
            $ctx['domain'] = $g['domain'] ?? $ctx['domain'];
        }
        $ctx['prefix'] = '/' . ltrim($ctx['prefix'], '/');

        return $ctx;
    }
}
