<?php

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;
use LogicException;
use SensitiveParameter;

/**
 * A short-lived GitHub App installation access token.
 *
 * Deliberately awkward to leak: it cannot be serialised (so it can never end
 * up in a queue payload), var_dump/dd redact it, and it has no __toString.
 */
final class InstallationToken
{
    public function __construct(
        #[SensitiveParameter] private readonly string $value,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]', 'expiresAt' => $this->expiresAt->toIso8601String()];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Installation tokens must never be serialised.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Installation tokens must never be unserialised.');
    }
}
