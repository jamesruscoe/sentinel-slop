<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

/**
 * Thresholds for the profile and its absence checks. Every check has a
 * minimum population so that a two-file directory cannot trigger it; the
 * defaults are deliberately conservative (see AbsenceChecks).
 */
final class ProfileConfig
{
    public function __construct(
        public readonly int $minSourceFilesForTests = 20,
        public readonly int $minAreaFilesForTests = 5,
        public readonly int $minServiceFilesForLogging = 8,
        public readonly int $minSourceFilesForLogging = 20,
        public readonly int $minInputFilesForValidation = 3,
        public readonly int $minEnvSites = 5,
        public readonly int $minEnvFiles = 3,
        public readonly int $minClusterOccurrences = 3,
        public readonly int $minClusterSavedLines = 100,
        public readonly int $highClusterSavedLines = 200,
        public readonly int $minDumpingGroundFiles = 25,
        public readonly float $dumpingGroundStemDiversity = 0.7,
        public readonly int $minDumpingGroundKinds = 3,
        public readonly int $minExternalSitesUnguarded = 5,
        public readonly int $grabBagFileLines = 300,
        public readonly int $minFeatureFiles = 3,
        public readonly int $topCount = 10,
        public readonly int $wrapperHops = 2,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `sentinel.profile` config array.
     */
    public static function fromArray(array $config): self
    {
        $defaults = new self;
        $int = fn (string $key, int $default): int => (int) ($config[$key] ?? $default);

        return new self(
            minSourceFilesForTests: $int('min_source_files_for_tests', $defaults->minSourceFilesForTests),
            minAreaFilesForTests: $int('min_area_files_for_tests', $defaults->minAreaFilesForTests),
            minServiceFilesForLogging: $int('min_service_files_for_logging', $defaults->minServiceFilesForLogging),
            minSourceFilesForLogging: $int('min_source_files_for_logging', $defaults->minSourceFilesForLogging),
            minInputFilesForValidation: $int('min_input_files_for_validation', $defaults->minInputFilesForValidation),
            minEnvSites: $int('min_env_sites', $defaults->minEnvSites),
            minEnvFiles: $int('min_env_files', $defaults->minEnvFiles),
            minClusterOccurrences: $int('min_cluster_occurrences', $defaults->minClusterOccurrences),
            minClusterSavedLines: $int('min_cluster_saved_lines', $defaults->minClusterSavedLines),
            highClusterSavedLines: $int('high_cluster_saved_lines', $defaults->highClusterSavedLines),
            minDumpingGroundFiles: $int('min_dumping_ground_files', $defaults->minDumpingGroundFiles),
            dumpingGroundStemDiversity: (float) ($config['dumping_ground_stem_diversity'] ?? $defaults->dumpingGroundStemDiversity),
            minDumpingGroundKinds: $int('min_dumping_ground_kinds', $defaults->minDumpingGroundKinds),
            minExternalSitesUnguarded: $int('min_external_sites_unguarded', $defaults->minExternalSitesUnguarded),
            grabBagFileLines: $int('grab_bag_file_lines', $defaults->grabBagFileLines),
            minFeatureFiles: $int('min_feature_files', $defaults->minFeatureFiles),
            topCount: $int('top_count', $defaults->topCount),
            wrapperHops: $int('wrapper_hops', $defaults->wrapperHops),
        );
    }
}
