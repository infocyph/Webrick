<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\CLI;

final class CommandKernel
{
    /**
     * Create a command kernel.
     *
     * @param array<string,callable> $cmdMap Map of command name => handler callable.
     *        Each handler will receive an array of string arguments and should
     *        return an int exit code (or a value castable to int).
     */
    public function __construct(private array $cmdMap)
    {
    }

    /**
     * Handle a CLI invocation represented by an argv-style array.
     *
     * Behaviour:
     *  - Uses $argv[1] as the command name, falling back to 'help' when absent.
     *  - If the command is not registered writes "Unknown command: {cmd}\n" to STDERR
     *    and returns exit code 1.
     *  - Otherwise invokes the registered handler with the remaining arguments
     *    (array_slice($argv, 2)) and returns the handler's result cast to int.
     *
     * @param array<string> $argv argv-style array (index 0 is the script name)
     * @return int Exit status code from the invoked command (or 1 on unknown command)
     */
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
