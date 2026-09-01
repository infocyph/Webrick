<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Request\Core\ServerRequest;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UriComponents;
use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Request\Support\PayloadParseState;
use Infocyph\Webrick\Support\HttpUtils;
use InvalidArgumentException;
use RuntimeException;

/** Native Webrick request surface with explicit structured-payload parse states. */
class NativeServerRequest extends ServerRequest
{
    private mixed $jsonPayload = null;

    private ?PayloadParseState $jsonState = null;

    private mixed $xmlPayload = null;

    private ?PayloadParseState $xmlState = null;

    protected function __clone(): void
    {
        parent::__clone();
        $this->resetPayloadCaches();
    }

    public static function createFromGlobals(): self
    {
        $server = self::stringMap($_SERVER);
        $handle = fopen('php://input', 'rb') ?: fopen('php://temp', 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open request input stream.');
        }
        $body = new Stream($handle);
        $protocol = $server['SERVER_PROTOCOL'] ?? null;
        $httpVersion = is_string($protocol) && str_starts_with($protocol, 'HTTP/')
            ? substr($protocol, 5)
            : '1.1';

        $request = new self(
            is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : HttpMethodEnum::GET->value,
            UriComponents::fromServerParams($server),
            $server,
            RequestHeaders::extractFromServer($server),
            $body,
            $httpVersion,
            self::stringMap($_POST),
            self::stringMap($_FILES),
            query: $_GET === [] ? null : self::stringMap($_GET),
            cookies: self::stringMap($_COOKIE),
        );

        if (
            in_array($request->getMethod(), [HttpMethodEnum::PUT->value, HttpMethodEnum::PATCH->value, HttpMethodEnum::DELETE->value], true)
            && HttpUtils::baseMediaType($request->getHeaderLine('Content-Type'))
                === MediaTypeEnum::FORM_URLENCODED->base()
        ) {
            parse_str((string) $body, $form);
            $request = $request->withParsedBody(self::stringMap($form));
        }

        return $request;
    }

    public function cookie(?string $key = null): mixed
    {
        return self::valueFromMap($this->getCookieParams(), $key);
    }

    public function jsonParseState(): PayloadParseState
    {
        return $this->jsonState ?? ($this->isJsonContentType()
            ? PayloadParseState::NOT_PARSED
            : PayloadParseState::NOT_APPLICABLE);
    }

    public function parsedJson(?string $key = null): mixed
    {
        $this->parseJson();
        if ($this->jsonState === PayloadParseState::NOT_APPLICABLE) {
            return $this->post($key);
        }
        if ($this->jsonState === PayloadParseState::INVALID) {
            throw new InvalidArgumentException('Invalid JSON request body.');
        }

        return self::valueFromPayload($this->jsonPayload, $key);
    }

    public function parsedXml(?string $key = null): mixed
    {
        $this->parseXml();
        if ($this->xmlState === PayloadParseState::NOT_APPLICABLE) {
            return $this->post($key);
        }
        if ($this->xmlState === PayloadParseState::INVALID) {
            throw new InvalidArgumentException('Invalid XML request body.');
        }

        return self::valueFromPayload($this->xmlPayload, $key);
    }

    public function post(?string $key = null): mixed
    {
        $parsed = $this->getParsedBody();
        $body = is_array($parsed) ? self::stringMap($parsed) : [];

        return self::valueFromMap($body, $key);
    }

    public function query(?string $key = null): mixed
    {
        return self::valueFromMap($this->getQueryParams(), $key);
    }

    public function server(?string $key = null): mixed
    {
        return self::valueFromMap($this->getServerParams(), $key);
    }

    /**
     * @param array<string,mixed>|object|null $data
     */
    public function withParsedBody(object|array|null $data): static
    {
        return parent::withParsedBody($data);
    }

    public function xmlParseState(): PayloadParseState
    {
        return $this->xmlState ?? ($this->isXmlContentType()
            ? PayloadParseState::NOT_PARSED
            : PayloadParseState::NOT_APPLICABLE);
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function stringMap(array $value): array
    {
        $map = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $map
     */
    private static function valueFromMap(array $map, ?string $key): mixed
    {
        return $key === null ? $map : ($map[$key] ?? null);
    }

    private static function valueFromPayload(mixed $payload, ?string $key): mixed
    {
        if ($key === null) {
            return $payload;
        }

        return is_array($payload) ? ($payload[$key] ?? null) : null;
    }

    private function isJsonContentType(): bool
    {
        return HttpUtils::isJsonContentType($this->getHeaderLine('Content-Type'));
    }

    private function isXmlContentType(): bool
    {
        return HttpUtils::isXmlContentType($this->getHeaderLine('Content-Type'));
    }

    private function parseJson(): void
    {
        if ($this->jsonState !== null) {
            return;
        }
        if (!$this->isJsonContentType()) {
            $this->jsonState = PayloadParseState::NOT_APPLICABLE;

            return;
        }

        try {
            $this->jsonPayload = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
            $this->jsonState = PayloadParseState::PARSED;
        } catch (\JsonException) {
            $this->jsonPayload = null;
            $this->jsonState = PayloadParseState::INVALID;
        }
    }

    private function parseXml(): void
    {
        if ($this->xmlState !== null) {
            return;
        }
        if (!$this->isXmlContentType()) {
            $this->xmlState = PayloadParseState::NOT_APPLICABLE;

            return;
        }
        if (!function_exists('simplexml_load_string')) {
            $this->xmlState = PayloadParseState::INVALID;

            return;
        }

        $xml = simplexml_load_string(
            $this->raw(),
            'SimpleXMLElement',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        if ($xml === false) {
            $this->xmlState = PayloadParseState::INVALID;

            return;
        }

        try {
            $encoded = json_encode($xml, JSON_THROW_ON_ERROR);
            $this->xmlPayload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR) ?? [];
            $this->xmlState = PayloadParseState::PARSED;
        } catch (\JsonException) {
            $this->xmlPayload = null;
            $this->xmlState = PayloadParseState::INVALID;
        }
    }

    private function resetPayloadCaches(): void
    {
        $this->jsonPayload = null;
        $this->jsonState = null;
        $this->xmlPayload = null;
        $this->xmlState = null;
    }
}
