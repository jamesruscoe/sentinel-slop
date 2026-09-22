<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case Fetching = 'fetching';
    case Preflight = 'preflight';
    case Detecting = 'detecting';
    case Analysing = 'analysing';
    case Heuristics = 'heuristics';
    case Normalising = 'normalising';
    case Scoring = 'scoring';
    case Synthesising = 'synthesising';
    case Complete = 'complete';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Fetching => 'Fetching repository',
            self::Preflight => 'Running preflight checks',
            self::Detecting => 'Detecting stack',
            self::Analysing => 'Running analysers',
            self::Heuristics => 'Running slop heuristics',
            self::Normalising => 'Normalising findings',
            self::Scoring => 'Calculating slop score',
            self::Synthesising => 'Generating prompts',
            self::Complete => 'Complete',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Complete, self::Failed], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Rough progress for the UI, 0-100.
     */
    public function progress(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Fetching => 10,
            self::Preflight => 20,
            self::Detecting => 30,
            self::Analysing => 45,
            self::Heuristics => 60,
            self::Normalising => 70,
            self::Scoring => 80,
            self::Synthesising => 90,
            self::Complete, self::Failed => 100,
        };
    }

    /**
     * The pipeline order, used to validate transitions.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [
            self::Queued,
            self::Fetching,
            self::Preflight,
            self::Detecting,
            self::Analysing,
            self::Heuristics,
            self::Normalising,
            self::Scoring,
            self::Synthesising,
            self::Complete,
        ];
    }
}
