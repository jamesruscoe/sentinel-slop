<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

/**
 * One entry of a tar stream. The data has not been read when the entry is
 * yielded; call copyTo() to stream it, or do nothing and the reader discards it.
 */
final class TarEntry
{
    public function __construct(
        private readonly TarStreamReader $reader,
        public readonly string $name,
        public readonly string $type,
        public readonly int $size,
        public readonly string $linkTarget,
        public readonly int $mode,
    ) {}

    public function isRegular(): bool
    {
        return $this->type === '0' || $this->type === '7';
    }

    public function isDirectory(): bool
    {
        return $this->type === '5';
    }

    public function isSymlink(): bool
    {
        return $this->type === '2';
    }

    public function isHardLink(): bool
    {
        return $this->type === '1';
    }

    /**
     * @param  callable(string): void  $sink
     * @return int bytes delivered
     */
    public function copyTo(callable $sink): int
    {
        return $this->reader->copyCurrent($sink);
    }
}
