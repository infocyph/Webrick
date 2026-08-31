<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\View;

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Response\Response;

/**
 * Explicit view-rendering boundary. The selected runtime/composition root owns
 * ViewFactoryInterface resolution; Response remains a value/output type.
 */
final readonly class ViewResponder
{
    private const string CHARSET_RX = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    public function __construct(private ViewFactoryInterface $factory) {}

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $headers
     */
    public function render(
        string $view,
        array $data = [],
        int $status = StatusEnum::OK->value,
        array $headers = [],
        ?string $charset = 'utf-8',
    ): Response {
        $bag = new HeaderBag($headers);
        if (!$bag->has('Content-Type')) {
            $contentType = MediaTypeEnum::HTML->base();
            if ($charset !== null && $charset !== '') {
                if (preg_match(self::CHARSET_RX, $charset) !== 1) {
                    throw new \InvalidArgumentException('View response charset must be a valid HTTP token.');
                }
                $contentType .= '; charset=' . $charset;
            }
            $bag = $bag->with('Content-Type', $contentType);
        }

        return new Response($status, $this->factory->render($view, $data), $bag->all());
    }
}
