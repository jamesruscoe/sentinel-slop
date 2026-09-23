<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

/**
 * Structural facts about a repository, computed without any analyser (see
 * RepositoryProfiler for how each section is derived). Plain data: file
 * paths, directory names, counts and the names of functions the parser
 * found. It is stored on the scan, shown on the results page and sent to
 * the reviewer as observations it may reason about.
 *
 * Sections (all present, possibly empty):
 *   summary       source/test file counts, lines, families, depth, which families absence checks assess
 *   areas         one row per area (see Naming::areaOf) with sizes, kinds, signal counts, logging/test coverage
 *   largest_files, largest_functions
 *   features      files grouped by name stem: how many files/directories/lines each feature takes
 *   duplication   clusters built from jscpd pairs, biggest lines-saved first
 *   tests         test files, frameworks, ratio, areas without linked tests (logic and UI separately)
 *   logging       what logging was detected, how (direct/wrapper), which layers have none
 *   validation    validation mechanisms found, input-reading files, and those without any validation reference
 *   errors        try/catch sites, outbound call sites, guarded/unguarded files, global handler mechanisms
 *   config        direct env reads outside config directories
 *   dependencies  direct/dev counts, single-use and never-imported packages
 *   cohesion      large flat directories with diverse contents, grab-bag helper files
 *   documentation README presence, comment density, sparsely commented areas
 *   observations  short human sentences the profiler wants the reviewer to see (not findings)
 */
final class RepositoryProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        $section = $this->data[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }
}
