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
    /**
     * Render a view and construct an HTML Response.
     *
     * Behaviour:
     *  - Resolves a ViewFactoryInterface implementation from the Intermix DI container
     *    using $factoryId (default: ViewFactoryInterface::class).
     *  - Throws RuntimeException if no factory is bound for the given $factoryId.
     *  - Calls $factory->render($view, $data) to obtain the rendered HTML.
     *  - Ensures a Content-Type header of "text/html; charset={$charset}" is present
     *    (will be added if not provided in $headers).
     *  - Delegates to parent Response with the rendered HTML as the body Stream.
     *
     * @param string $view Template name / identifier passed to the factory.
     * @param array<string,mixed> $data Data to be made available to the template.
     * @param int $status HTTP status code to use for the response (default 200).
     * @param array<string,string> $headers Additional response headers (name => value).
     * @param string|null $charset Charset to include in the Content-Type header (default 'utf-8').
     * @param string|null $factoryId Container key for the view factory (default ViewFactoryInterface::class).
     * @throws \RuntimeException If no view factory is bound for $factoryId.
     */
    public function __construct(
        string $view,
        array $data = [],
        int $status = 200,
        array $headers = [],
        ?string $charset = 'utf-8',
        ?string $factoryId = ViewFactoryInterface::class, // container key
    ) {
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
