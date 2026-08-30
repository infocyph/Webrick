<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\InputSanitizer;

/**
 * Explicit input-normalization compatibility middleware.
 *
 * This is not a security boundary. All mutation is opt-in; applications should
 * validate domain input and perform sink-specific output encoding separately.
 */
final readonly class InputSanitizerMiddleware
{
    private InputSanitizer $sanitizer;

    public function __construct(
        ?InputSanitizer $sanitizer = null,
        private bool $touchQuery = false,
        private bool $touchFormBodies = false,
        private bool $touchJsonBodies = false,
    ) {
        $this->sanitizer = $sanitizer ?? new InputSanitizer();
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->touchQuery) {
            $query = $req->getQueryParams();
            if ($query !== []) {
                $req = $req->withQueryParams($this->stringKeyMap($this->sanitizer->sanitizeArray($query)));
            }
        }

        $body = $req->getParsedBody();
        if (is_array($body) && $this->shouldTouchBody($req->getHeaderLine('Content-Type'))) {
            $req = $req->withParsedBody($this->stringKeyMap($this->sanitizer->sanitizeArray($body)));
        }

        return $next($req);
    }

    private function shouldTouchBody(string $contentType): bool
    {
        $mime = strtolower(strtok($contentType, ';') ?: '');

        return (HttpUtils::isFormContentType($contentType) && $this->touchFormBodies)
            || (str_starts_with($mime, MediaTypeEnum::JSON->base()) && $this->touchJsonBodies);
    }

    /** @param array<mixed> $input @return array<string,mixed> */
    private function stringKeyMap(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
