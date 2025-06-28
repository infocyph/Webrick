<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\View;

use Illuminate\View\Factory as LaravelFactory;

/**
 * Thin adapter around `Illuminate\View\Factory`.
 *
 * Register this in Intermix container if you use Blade;
 * otherwise swap out for Twig etc.
 */
final class BladeViewFactory implements ViewFactoryInterface
{
    public function __construct(private LaravelFactory $blade) {}

    public function render(string $name, array $data = []): string
    {
        /** @var string $out */
        $out = $this->blade->make($name, $data)->render();
        return $out;
    }
}
