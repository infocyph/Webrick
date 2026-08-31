<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use InvalidArgumentException;

final readonly class SignedUrlConfig
{
    public const string DEFAULT_ALGORITHM = 'sha3-256';

    public const string DEFAULT_EXPIRY_PARAM = '_exp';

    public const string DEFAULT_SIGNATURE_PARAM = '_sig';

    public const string MODE_ABSOLUTE = 'absolute';

    public const string MODE_RELATIVE = 'relative';

    public string $algorithm;

    public ?int $defaultTtl;

    public string $expiryParam;

    public ?string $generationKey;

    /** @var list<string> */
    public array $ignoredQueryParams;

    public int $leeway;

    public string $payloadMode;

    public string $signatureParam;

    /** @var list<string> */
    public array $verificationKeys;

    /**
     * @param list<string> $ignoredQueryParams
     * @param list<string> $verificationKeys
     * @param ?string $generationKey
     * @param ?int $defaultTtl
     * @param string $signatureParam
     * @param string $expiryParam
     * @param string $algorithm
     * @param string $payloadMode
     * @param int $leeway
     */
    public function __construct(
        ?string $generationKey = null,
        array $verificationKeys = [],
        ?int $defaultTtl = null,
        string $signatureParam = self::DEFAULT_SIGNATURE_PARAM,
        string $expiryParam = self::DEFAULT_EXPIRY_PARAM,
        string $algorithm = self::DEFAULT_ALGORITHM,
        string $payloadMode = self::MODE_RELATIVE,
        array $ignoredQueryParams = [],
        int $leeway = 0,
    ) {
        $this->generationKey = self::normalizeNullableString($generationKey, 'generationKey');
        $this->defaultTtl = self::normalizeNullablePositiveInt($defaultTtl, 'defaultTtl');
        $this->signatureParam = self::normalizeParamName($signatureParam, 'signatureParam');
        $this->expiryParam = self::normalizeParamName($expiryParam, 'expiryParam');
        if ($this->signatureParam === $this->expiryParam) {
            throw new InvalidArgumentException('signatureParam and expiryParam must differ.');
        }

        $this->algorithm = self::normalizeAlgorithm($algorithm);
        $this->payloadMode = self::normalizePayloadMode($payloadMode);
        $this->leeway = self::normalizeLeeway($leeway);
        $this->verificationKeys = self::normalizeVerificationKeys($verificationKeys, $this->generationKey);
        $this->ignoredQueryParams = self::normalizeIgnoredQueryParams(
            $ignoredQueryParams,
            $this->signatureParam,
            $this->expiryParam,
        );
    }

    /**
     * @param array<int|string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $verificationKeys = $config['verificationKeys'] ?? [];
        if (!\is_array($verificationKeys)) {
            $verificationKeys = [];
        }

        $ignoredQueryParams = $config['ignoredQueryParams'] ?? [];
        if (!\is_array($ignoredQueryParams)) {
            $ignoredQueryParams = [];
        }

        return new self(
            generationKey: \is_string($config['generationKey'] ?? null) ? $config['generationKey'] : null,
            verificationKeys: self::filterStringList($verificationKeys),
            defaultTtl: \is_int($config['defaultTtl'] ?? null) ? $config['defaultTtl'] : self::normalizeOptionalInt($config['defaultTtl'] ?? null),
            signatureParam: \is_string($config['signatureParam'] ?? null)
                ? $config['signatureParam']
                : self::DEFAULT_SIGNATURE_PARAM,
            expiryParam: \is_string($config['expiryParam'] ?? null)
                ? $config['expiryParam']
                : self::DEFAULT_EXPIRY_PARAM,
            algorithm: \is_string($config['algorithm'] ?? null)
                ? $config['algorithm']
                : self::DEFAULT_ALGORITHM,
            payloadMode: \is_string($config['payloadMode'] ?? null)
                ? $config['payloadMode']
                : self::MODE_RELATIVE,
            ignoredQueryParams: self::filterStringList($ignoredQueryParams),
            leeway: self::normalizeOptionalInt($config['leeway'] ?? null) ?? 0,
        );
    }

    public static function mergeLegacy(?self $config, ?string $generationKey = null, ?int $defaultTtl = null): ?self
    {
        if ($config === null && $generationKey === null && $defaultTtl === null) {
            return null;
        }

        $base = $config ?? new self();

        return new self(
            generationKey: $base->generationKey ?? self::normalizeNullableString($generationKey, 'generationKey'),
            verificationKeys: $base->verificationKeys,
            defaultTtl: $base->defaultTtl ?? self::normalizeNullablePositiveInt($defaultTtl, 'defaultTtl'),
            signatureParam: $base->signatureParam,
            expiryParam: $base->expiryParam,
            algorithm: $base->algorithm,
            payloadMode: $base->payloadMode,
            ignoredQueryParams: $base->ignoredQueryParams,
            leeway: $base->leeway,
        );
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<string>
     */
    private static function filterStringList(array $values): array
    {
        $filtered = [];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $filtered[] = $value;
            }
        }

        return $filtered;
    }

    private static function normalizeAlgorithm(string $algorithm): string
    {
        $algorithm = \trim(\strtolower($algorithm));
        if ($algorithm === '') {
            throw new InvalidArgumentException('algorithm must not be empty.');
        }

        if (!\in_array($algorithm, \hash_hmac_algos(), true)) {
            throw new InvalidArgumentException("Unsupported HMAC algorithm '{$algorithm}'.");
        }

        return $algorithm;
    }

    /**
     * @param list<string> $ignoredQueryParams
     * @return list<string>
     * @param string $signatureParam
     * @param string $expiryParam
     */
    private static function normalizeIgnoredQueryParams(
        array $ignoredQueryParams,
        string $signatureParam,
        string $expiryParam,
    ): array {
        $normalized = [];
        foreach ($ignoredQueryParams as $param) {
            $param = \trim($param);
            if ($param === '' || $param === $signatureParam || $param === $expiryParam) {
                continue;
            }

            $normalized[$param] = $param;
        }

        return \array_values($normalized);
    }

    private static function normalizeLeeway(int $leeway): int
    {
        if ($leeway < 0) {
            throw new InvalidArgumentException('leeway must be zero or greater.');
        }

        return $leeway;
    }

    private static function normalizeNullablePositiveInt(?int $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 1) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        return $value;
    }

    private static function normalizeNullableString(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = \trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("{$field} must not be empty when provided.");
        }

        return $value;
    }

    private static function normalizeOptionalInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && $value !== '' && \is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function normalizeParamName(string $param, string $field): string
    {
        $param = \trim($param);
        if ($param === '') {
            throw new InvalidArgumentException("{$field} must not be empty.");
        }

        return $param;
    }

    private static function normalizePayloadMode(string $payloadMode): string
    {
        $payloadMode = \strtolower(\trim($payloadMode));
        if ($payloadMode === self::MODE_RELATIVE || $payloadMode === self::MODE_ABSOLUTE) {
            return $payloadMode;
        }

        throw new InvalidArgumentException(
            "payloadMode must be '" . self::MODE_RELATIVE . "' or '" . self::MODE_ABSOLUTE . "'.",
        );
    }

    /**
     * @param list<string> $verificationKeys
     * @return list<string>
     * @param ?string $generationKey
     */
    private static function normalizeVerificationKeys(array $verificationKeys, ?string $generationKey): array
    {
        $normalized = [];
        foreach ($verificationKeys as $key) {
            $key = \trim($key);
            if ($key === '') {
                continue;
            }

            $normalized[$key] = $key;
        }

        if ($generationKey !== null) {
            $normalized[$generationKey] = $generationKey;
        }

        return \array_values($normalized);
    }
}
