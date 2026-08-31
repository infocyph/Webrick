<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpExceptionInterface;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/** Per-request HTTP error renderer. Process-level PHP errors belong to PhpErrorBridge. */
final readonly class ErrorHandler
{
    /**
     * @param array<class-string,int> $exceptionMap
     * @param null|callable(Request,Throwable,int,array<string,string>):mixed $responseRenderer
     */
    public function __construct(
        private LoggerInterface|\Closure|null $logger = null,
        private bool $debug = false,
        private string $requestIdHeader = 'X-Request-Id',
        private array $exceptionMap = [],
        private mixed $responseRenderer = null,
    ) {
        new HeaderBag([$this->requestIdHeader => 'probe']);
        foreach ($this->exceptionMap as $class => $code) {
            if (!is_string($class) || !is_int($code) || !is_a($class, Throwable::class, true)) {
                throw new \InvalidArgumentException('Error handler exception map must use throwable class names and integer statuses.');
            }
            if (!StatusEnum::isErrorCode($code)) {
                throw new \InvalidArgumentException('Error handler exception statuses must be between 400 and 599.');
            }
        }
        if ($this->responseRenderer !== null && !is_callable($this->responseRenderer)) {
            throw new \InvalidArgumentException('Error response renderer must be callable or null.');
        }
    }

    /**
     * @param callable(Request):Response $core
     */
    public function handle(Request $request, callable $core): Response
    {
        try {
            return $core($request);
        } catch (Throwable $error) {
            $status = $this->resolveStatus($error);
            $response = $this->render($request, $error, $status);
            $this->log($error, $request, $status);

            return $response;
        }
    }

    /** @return array<string,string> */
    private static function singleValueHeaders(HeaderBag $bag): array
    {
        $headers = [];
        foreach ($bag->all() as $name => $values) {
            if ($values !== []) {
                $headers[$name] = $values[count($values) - 1];
            }
        }

        return $headers;
    }

    /**
     * @return array<string,string>
     */
    private function buildRenderHeaders(Request $request, Throwable $error): array
    {
        $bag = new HeaderBag([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept',
        ]);
        $requestId = $this->resolveRequestId($request);
        if ($requestId !== '') {
            $bag = $bag->with($this->requestIdHeader, $requestId);
        }
        foreach ($this->exceptionHeaders($error) as $name => $value) {
            $bag = $bag->with($name, $value);
        }

        return self::singleValueHeaders($bag);
    }

    /**
     * @return array{exception:class-string<Throwable>,file:string}
     */
    private function debugMeta(Throwable $error): array
    {
        return [
            'exception' => $error::class,
            'file' => $error->getFile() . ':' . $error->getLine(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function exceptionHeaders(Throwable $error): array
    {
        return $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];
    }

    private function htmlError(int $status, string $reason, string $message, string $requestId, Throwable $error): string
    {
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $debug = '';
        if ($this->debug) {
            $debug = sprintf(
                '<div style="margin-top:1rem;padding:.75rem;border:1px solid #ddd;border-radius:6px;"><div><strong>%s</strong></div><div>%s:%d</div><pre style="white-space:pre-wrap">%s</pre></div>',
                $escape($error::class),
                $escape($error->getFile()),
                $error->getLine(),
                $escape($error->getTraceAsString()),
            );
        }
        $requestIdHtml = $requestId !== ''
            ? '<div style="opacity:.7">Request-Id: ' . $escape($requestId) . '</div>'
            : '';

        return <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <title>{$status} {$escape($reason)}</title>
            <style>
            body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; margin: 2rem; line-height:1.45; }
            h1 { margin: 0 0 .25rem 0; font-size: 1.5rem; }
            .card { padding: 1rem; border: 1px solid #e5e7eb; border-radius: .75rem; background: #fff; max-width: 900px; }
            .sub { color: #6b7280; margin-top:.25rem; }
            </style>
            <div class="card">
              <h1>{$status} {$escape($reason)}</h1>
              <div class="sub">{$escape($message)}</div>
              {$requestIdHtml}
              {$debug}
            </div>
            HTML;
    }

    private function isXmlLike(string $type): bool
    {
        $type = strtolower($type);

        return $type === MediaTypeEnum::XML->base()
            || $type === 'text/xml'
            || str_ends_with($type, '+xml');
    }

    private function log(Throwable $error, Request $request, int $status): void
    {
        $logger = $this->resolveLogger();
        if (!$logger instanceof LoggerInterface) {
            return;
        }

        $statusCase = StatusEnum::tryFrom($status);
        $level = match (true) {
            $statusCase?->isServerError() ?? StatusEnum::isServerErrorCode($status) => 'error',
            $status === StatusEnum::NOT_FOUND->value || $status === StatusEnum::METHOD_NOT_ALLOWED->value => 'notice',
            default => 'warning',
        };

        $logger->{$level}(
            sprintf('[http:%d] %s: %s', $status, $error::class, $error->getMessage()),
            [
                'status' => $status,
                'series' => $statusCase?->series(),
                'method' => HttpMethodEnum::normalize($request->getMethod()),
                'path' => $request->getUri()->getPath(),
                'request_id' => $this->resolveRequestId($request) ?: null,
                'exception' => $error,
            ],
        );
    }

    private function mappedExceptionStatus(Throwable $error): ?int
    {
        foreach ($this->exceptionMap as $class => $code) {
            if ($error instanceof $class && StatusEnum::isErrorCode($code)) {
                return $code;
            }
        }

        return null;
    }

    private function render(Request $request, Throwable $error, int $status): Response
    {
        $statusEnum = StatusEnum::tryFrom($status) ?? StatusEnum::INTERNAL_SERVER_ERROR;
        $headers = $this->buildRenderHeaders($request, $error);
        $requestId = $this->resolveRequestId($request);

        if (HttpMethodEnum::normalize($request->getMethod()) === HttpMethodEnum::HEAD->value) {
            return Response::empty($status, $headers);
        }
        if (!$statusEnum->allowsBody()) {
            return Response::empty($status, $headers);
        }

        $custom = $this->renderWithOverride($request, $error, $status, $headers);
        if ($custom instanceof Response) {
            return $custom;
        }

        $reason = $statusEnum->reason();
        $public = $this->resolvePublicMessage($error, $reason);
        $message = $this->debug ? ($error->getMessage() ?: $public) : $public;

        return $this->renderByType(
            $this->resolveRenderType($request),
            $request,
            $error,
            $status,
            $reason,
            $message,
            $requestId,
            $headers,
        );
    }

    /**
     * @param array<string,string> $headers
     */
    private function renderByType(
        string $wanted,
        Request $request,
        Throwable $error,
        int $status,
        string $reason,
        string $message,
        string $requestId,
        array $headers,
    ): Response {
        if ($wanted === MediaTypeEnum::PROBLEM_JSON->value) {
            return $this->renderProblemJson($request, $error, $status, $reason, $message, $requestId, $headers);
        }
        if (MediaTypeEnum::isJsonLike($wanted)) {
            $headers['Content-Type'] ??= $wanted;

            return $this->renderJson($error, $status, $reason, $message, $requestId, $headers);
        }
        if ($this->isXmlLike($wanted)) {
            $headers['Content-Type'] ??= $wanted;

            return Response::create(
                $this->xmlError($status, $reason, $message, $requestId, $error),
                $status,
                $headers,
            );
        }
        if ($wanted === MediaTypeEnum::HTML->base()) {
            $headers['Content-Type'] ??= MediaTypeEnum::HTML->value;

            return Response::create(
                $this->htmlError($status, $reason, $message, $requestId, $error),
                $status,
                $headers,
            );
        }

        return $this->renderPlain($error, $status, $reason, $message, $requestId, $headers);
    }

    /**
     * @param array<string,string> $headers
     */
    private function renderJson(
        Throwable $error,
        int $status,
        string $reason,
        string $message,
        string $requestId,
        array $headers,
    ): Response {
        $payload = ['error' => $message, 'code' => $status, 'reason' => $reason];
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }
        if ($this->debug) {
            $payload += $this->debugMeta($error);
        }

        return Response::json($payload, $status, $headers);
    }

    /**
     * @param array<string,string> $headers
     */
    private function renderPlain(
        Throwable $error,
        int $status,
        string $reason,
        string $message,
        string $requestId,
        array $headers,
    ): Response {
        $headers['Content-Type'] ??= MediaTypeEnum::PLAIN->value;
        $lines = ["{$status} {$reason}", $message];
        if ($requestId !== '') {
            $lines[] = "Request-Id: {$requestId}";
        }
        if ($this->debug) {
            $lines[] = $error::class;
            $lines[] = $error->getFile() . ':' . $error->getLine();
        }

        return Response::plaintext(implode("\n", $lines), $status, $headers);
    }

    /**
     * @param array<string,string> $headers
     */
    private function renderProblemJson(
        Request $request,
        Throwable $error,
        int $status,
        string $reason,
        string $message,
        string $requestId,
        array $headers,
    ): Response {
        $payload = [
            'type' => 'about:blank',
            'title' => $reason,
            'status' => $status,
            'detail' => $message,
            'instance' => $request->getUri()->getPath(),
        ];
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }
        if ($this->debug) {
            $payload += $this->debugMeta($error);
        }

        $headers['Content-Type'] ??= MediaTypeEnum::PROBLEM_JSON->value;
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        );

        return Response::create($json === false ? '{}' : $json, $status, $headers);
    }

    /**
     * @param array<string,string> $headers
     */
    private function renderWithOverride(
        Request $request,
        Throwable $error,
        int $status,
        array $headers,
    ): ?Response {
        if (!is_callable($this->responseRenderer)) {
            return null;
        }

        $response = ($this->responseRenderer)($request, $error, $status, $headers);

        return $response instanceof Response ? $response : null;
    }

    private function resolveLogger(): ?LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface || $this->logger === null) {
            return $this->logger;
        }

        $logger = ($this->logger)();
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new \UnexpectedValueException('The error logger factory must return a PSR-3 logger or null.');
        }

        return $logger;
    }

    private function resolvePublicMessage(Throwable $error, string $fallback): string
    {
        if (!$error instanceof HttpExceptionInterface) {
            return $fallback;
        }

        $message = $error->getPublicMessage();

        return $message !== '' ? $message : $fallback;
    }

    private function resolveRenderType(Request $request): string
    {
        $negotiated = $request->getAttribute('negotiated.type');
        if (is_string($negotiated) && $negotiated !== '') {
            return strtolower($negotiated);
        }

        return new ContentNegotiator($request->headers())->preferred([
            MediaTypeEnum::PLAIN->base(),
            MediaTypeEnum::PROBLEM_JSON->value,
            MediaTypeEnum::JSON->base(),
            '+json',
            MediaTypeEnum::HTML->base(),
            MediaTypeEnum::XML->base(),
            'text/xml',
            '+xml',
        ]) ?? MediaTypeEnum::PLAIN->base();
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = $request->getAttribute('request_id') ?: $request->getHeaderLine($this->requestIdHeader);
        if (!is_string($requestId) || $requestId === '') {
            return '';
        }

        $requestId = preg_replace('/[^\x21-\x7E]/', '', $requestId) ?? '';

        return substr($requestId, 0, 128);
    }

    private function resolveStatus(Throwable $error): int
    {
        if ($error instanceof HttpExceptionInterface) {
            $status = $error->getStatusCode();

            return StatusEnum::isErrorCode($status)
                ? $status
                : StatusEnum::INTERNAL_SERVER_ERROR->value;
        }

        return $this->mappedExceptionStatus($error) ?? StatusEnum::INTERNAL_SERVER_ERROR->value;
    }

    private function xmlError(
        int $status,
        string $reason,
        string $message,
        string $requestId,
        Throwable $error,
    ): string {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $trace = $this->debug ? '<trace>' . $escape($error->getTraceAsString()) . '</trace>' : '';
        $requestIdElement = $requestId !== '' ? '<request_id>' . $escape($requestId) . '</request_id>' : '';
        $exceptionElement = $this->debug
            ? '<exception>' . $escape($error::class) . '</exception><file>' . $escape($error->getFile()) . ':' . $error->getLine() . '</file>'
            : '';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <error>
              <code>{$status}</code>
              <reason>{$escape($reason)}</reason>
              <message>{$escape($message)}</message>
              {$requestIdElement}
              {$exceptionElement}
              {$trace}
            </error>
            XML;
    }
}
