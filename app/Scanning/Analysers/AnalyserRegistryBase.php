<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Contracts\Analyser;
use App\Scanning\Contracts\Heuristic;
use App\Scanning\Data\Stack;

abstract class AnalyserRegistryBase
{
    /**
     * @param  list<Analyser|Heuristic>  $entries
     */
    public function __construct(private readonly array $entries) {}

    /**
     * @return list<Analyser|Heuristic>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return list<Analyser|Heuristic>
     */
    public function supporting(Stack $stack): array
    {
        return array_values(array_filter($this->entries, fn (Analyser|Heuristic $entry) => $entry->supports($stack)));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (Analyser|Heuristic $entry) => $entry->name(), $this->entries);
    }
}
