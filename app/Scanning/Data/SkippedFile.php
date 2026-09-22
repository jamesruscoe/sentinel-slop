<?php

declare(strict_types=1);

namespace App\Scanning\Data;

use App\Scanning\Enums\SkipReason;

final class SkippedFile
{
    public function __construct(
        public readonly string $path,
        public readonly SkipReason $reason,
        public readonly ?string $detail = null,
    ) {}

    /**
     * @return array{path: string, reason: string, detail: string|null}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'reason' => $this->reason->value, 'detail' => $this->detail];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['path'], SkipReason::from((string) $data['reason']), isset($data['detail']) ? (string) $data['detail'] : null);
    }
}
