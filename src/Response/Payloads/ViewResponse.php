<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\View\ViewFactoryInterface;

/**
 * Renders a template through any factory implementing ViewFactoryInterface.
 *
 * The factory is resolved through Intermix’s container; this keeps ViewResponse
 * decoupled from Blade/Twig specifics and zero-cost if you never bind a factory.
 */
final class ViewResponse extends Response
{
    public function __construct(
        string $view,
        array $data = [],
        int $status = 200,
        array $headers = [],
        ?string $charset = 'utf-8',
        ?string $factoryId = ViewFactoryInterface::class, // container key
    )
    {
        $container = \Infocyph\InterMix\DI\Container::instance('intermix');

        if (!$container->has($factoryId)) {
            throw new \RuntimeException("No view factory bound for {$factoryId}");
        }

        /** @var ViewFactoryInterface $factory */
        $factory = $container->get($factoryId);
        $html = $factory->render($view, $data);

        $headers += ['Content-Type' => "text/html; charset={$charset}"];
        parent::__construct($status, new Stream($html), $headers);
    }
}
