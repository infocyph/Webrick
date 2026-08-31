<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\View;

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Response\Response;

/**
 * Explicit view-rendering boundary. The selected runtime/composition root owns
 * ViewFactoryInterface resolution; Response remains a value/output type.
 */
final readonly class ViewResponder
{
    public function __construct(private ViewFactoryInterface $factory) {}

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $headers
     * @param string $view
     * @param int $status
     * @param ?string $charset
     */
    public function render(
        string $view,
        array $data = [],
        int $status = StatusEnum::OK->value,
        array $headers = [],
        ?string $charset = 'utf-8',
    ): Response {
        $contentType = MediaTypeEnum::HTML->base();
        if ($charset !== null && $charset !== '') {
            $contentType .= '; charset=' . $charset;
        }
        $headers += ['Content-Type' => $contentType];

        return new Response($status, $this->factory->render($view, $data), $headers);
    }
}
