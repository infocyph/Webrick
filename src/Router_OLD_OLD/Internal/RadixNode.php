<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Internal;

/**
 * Minimal *radix tree* node – **not currently wired** into the live
 * router (array look-ups were quicker for now).  It remains here for
 * future experimentation with billions of static routes.
 */
final class RadixNode
{
    public function __construct(
        public string $label   = '',
        public ?int   $routeId = null,
        /** @var array<string,RadixNode> */
        public array  $children = []
    ) {}

    public function insert(string $path, int $routeId): void
    {
        if ($path === '') {
            $this->routeId = $routeId;
            return;
        }

        foreach ($this->children as $edge => $child) {
            $lcp = self::lcp($edge, $path);
            if ($lcp !== '') {
                // Split edge if needed
                if ($lcp !== $edge) {
                    $remain = substr($edge, strlen($lcp));
                    $childNew        = new self($remain, $child->routeId, $child->children);
                    $child->label    = $lcp;
                    $child->routeId  = null;
                    $child->children = [$remain => $childNew];
                }

                $child->insert(substr($path, strlen($lcp)), $routeId);
                return;
            }
        }

        // No overlap – just add
        $this->children[$path] = new self($path, $routeId);
    }

    /** Longest-common-prefix */
    private static function lcp(string $a, string $b): string
    {
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                return substr($a, 0, $i);
            }
        }
        return substr($a, 0, $len);
    }
}
