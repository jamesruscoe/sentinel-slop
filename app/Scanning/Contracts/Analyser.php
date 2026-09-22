<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;

/**
 * A tool that inspects fetched repository files and reports findings.
 *
 * Implementations only ever READ the files under $path. They must never
 * execute, import, or load configuration from the scanned repository.
 */
interface Analyser
{
    /** Short identifier used as the `tool` on findings, e.g. "phpstan". */
    public function name(): string;

    public function supports(Stack $stack): bool;

    /**
     * @param  string  $path  Absolute path to the fetched repository root.
     */
    public function run(string $path): FindingCollection;
}
