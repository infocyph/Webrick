<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\View;

/**
 * Minimal contract the router/handlers expect.
 *
 * Any engine (Blade, Twig, etc.) can implement it.
 */
interface ViewFactoryInterface
{
    /**
     * Render a view into raw string.
     *
     * @param string $name   e.g. 'users.profile'
     * @param array  $data   passed variables
     */
    public function render(string $name, array $data = []): string;
}
