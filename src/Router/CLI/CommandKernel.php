<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\CLI;

final class CommandKernel
{
    /** @param array<string,callable> $cmdMap */
    public function __construct(private array $cmdMap)
    {
    }

    public function handle(array $argv): int
    {
        $cmd = $argv[1] ?? 'help';
        if (!isset($this->cmdMap[$cmd])) {
            fwrite(STDERR, "Unknown command: {$cmd}\n");
            return 1;
        }
        $fn = $this->cmdMap[$cmd];
        $code = $fn(array_slice($argv, 2));
        return (int)$code;
    }
}
