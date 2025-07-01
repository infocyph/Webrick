<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Console;

use Infocyph\Webrick\Router\Cache\RouteCache;
use Infocyph\Webrick\Router\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name       : 'route',
    description: 'List, cache or clear the compiled routes (list|cache|clear).'
)]
final class RouteCommand extends Command
{
    public function __construct(
        private readonly RouteCache $cache,
        private readonly Router     $router
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Operation to perform: list, cache, or clear',
                'list'
            );
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        return match (strtolower((string) $in->getArgument('action'))) {
            'cache' => $this->warm($out),
            'clear' => $this->clear($out),
            'list', '' => $this->dump($out),
            default => $this->invalid($out),
        };
    }

    private function dump(OutputInterface $out): int
    {
        $routes = $this->cache->load()
            ?? $this->router->routes();

        $table = new Table($out);
        $table->setHeaders(['Method', 'URI', 'Name', 'Middleware']);

        foreach ($routes as $r) {
            $table->addRow([
                implode(',', $r->verbs),
                $r->path,
                $r->name ?? '—',
                $r->middleware ? implode(',', $r->middleware) : '—',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function warm(OutputInterface $out): int
    {
        $out->writeln('<info>• Compiling routes …</info>');
        $routes = $this->router->routes();

        $out->writeln('<info>• Writing cache …</info>');
        $this->cache->store($routes);

        $out->writeln('<comment>✓ Route cache warmed.</comment>');
        return Command::SUCCESS;
    }

    private function clear(OutputInterface $out): int
    {
        $out->writeln('<info>• Clearing route cache …</info>');
        $this->cache->clear();
        $out->writeln('<comment>✓ Cache cleared.</comment>');
        return Command::SUCCESS;
    }

    private function invalid(OutputInterface $out): int
    {
        $out->writeln('<error>Unknown action. Use list|cache|clear.</error>');
        return Command::INVALID;
    }
}
