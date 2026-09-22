<?php

declare(strict_types=1);

namespace App\Scanning\Enums;

enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * Higher rank means more severe.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function fromSarifLevel(?string $level): self
    {
        return match ($level) {
            'error' => self::High,
            'warning' => self::Medium,
            'note' => self::Low,
            default => self::Info,
        };
    }
}
