<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\View;

interface ViewFactoryInterface
{
    /**
     * Render a template into HTML output.
     *
     * @param string $view Template identifier
     * @param array<string,mixed> $data Template data
     * @return string Rendered HTML
     */
    public function render(string $view, array $data = []): string;
}
