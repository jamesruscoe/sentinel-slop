<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;

/**
 * A custom AI-slop check. Same contract as an Analyser, kept separate so the
 * pipeline can run and report the two groups independently.
 */
interface Heuristic
{
    public function name(): string;

    public function supports(Stack $stack): bool;

    public function run(string $path): FindingCollection;
}
