<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use InvalidArgumentException;

/**
 * Immutable, public admission verdict. Reason codes are bounded diagnostic metadata,
 * never provider responses, user identifiers, or credentials.
 */
final readonly class RiskDecision
{
    public const string ALLOW = 'allow';

    public const string ATTRIBUTE = 'webrick.risk_admission.decision';

    public const string CHALLENGE = 'challenge';

    public const string DENY = 'deny';

    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param array<array-key,mixed> $reasonCodes
     */
    private function __construct(
        public string $action,
        public ?int $score = null,
        public array $reasonCodes = [],
    ) {
        if (!in_array($action, [self::ALLOW, self::CHALLENGE, self::DENY, self::UNAVAILABLE], true)) {
            throw new InvalidArgumentException('Unknown risk admission action.');
        }
        if ($score !== null && $score < 0) {
            throw new InvalidArgumentException('Risk admission score must be non-negative.');
        }
        if (!array_is_list($reasonCodes) || count($reasonCodes) > 8) {
            throw new InvalidArgumentException('Risk admission reason codes must be a list of at most eight items.');
        }
        foreach ($reasonCodes as $code) {
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
                throw new InvalidArgumentException('Risk admission reason codes must be short, non-secret identifiers.');
            }
        }
    }

    /** @param array<array-key,mixed> $reasonCodes */
    public static function allow(?int $score = null, array $reasonCodes = []): self
    {
        return new self(self::ALLOW, $score, $reasonCodes);
    }

    /** @param array<array-key,mixed> $reasonCodes */
    public static function challenge(?int $score = null, array $reasonCodes = []): self
    {
        return new self(self::CHALLENGE, $score, $reasonCodes);
    }

    /** @param array<array-key,mixed> $reasonCodes */
    public static function deny(?int $score = null, array $reasonCodes = []): self
    {
        return new self(self::DENY, $score, $reasonCodes);
    }

    /** @param array<array-key,mixed> $reasonCodes */
    public static function unavailable(?int $score = null, array $reasonCodes = []): self
    {
        return new self(self::UNAVAILABLE, $score, $reasonCodes);
    }
}
