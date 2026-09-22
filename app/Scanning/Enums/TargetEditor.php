<?php

declare(strict_types=1);

namespace App\Scanning\Enums;

enum TargetEditor: string
{
    case ClaudeCode = 'claude_code';
    case Cursor = 'cursor';

    public function label(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Claude Code',
            self::Cursor => 'Cursor',
        };
    }

    /**
     * The rules file this editor reads for project conventions.
     */
    public function rulesFilename(): string
    {
        return match ($this) {
            self::ClaudeCode => 'CLAUDE.md',
            self::Cursor => '.cursor/rules/sentinel-slop.mdc',
        };
    }
}
