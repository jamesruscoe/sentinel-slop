<?php

declare(strict_types=1);

namespace App\Scanning\Data;

use InvalidArgumentException;

final class RepositoryRef
{
    public function __construct(
        public readonly string $owner,
        public readonly string $name,
        public readonly ?string $branch = null,
    ) {
        foreach ([$owner, $name] as $part) {
            if ($part === '' || preg_match('/^[A-Za-z0-9._-]+$/', $part) !== 1) {
                throw new InvalidArgumentException("Invalid repository reference part: {$part}");
            }
        }
    }

    public static function fromFullName(string $fullName, ?string $branch = null): self
    {
        $parts = explode('/', $fullName, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid repository full name: {$fullName}");
        }

        return new self($parts[0], $parts[1], $branch);
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }
}
