<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

use App\Scanning\Data\Stack;

/**
 * Builds a Stack from GitHub's language breakdown plus root manifests parsed
 * as data. Manifests are read as text with a size cap and never evaluated.
 */
final class StackDetector
{
    private const MAX_MANIFEST_BYTES = 512 * 1024;

    /** @var array<string, string>  manifest basename => parser method */
    private const MANIFESTS = [
        'composer.json' => 'composer',
        'package.json' => 'npm',
        'pyproject.toml' => 'pyproject',
        'requirements.txt' => 'requirements',
    ];

    /**
     * @param  array<string, int>  $languages  From GitHub's languages endpoint.
     * @param  list<string>  $treePaths  Every path in the tree (data only).
     */
    public function detect(string $repoPath, array $languages, array $treePaths): Stack
    {
        arsort($languages);

        $manifests = [];
        $frameworks = [];
        $tooling = [];
        $dependencies = [];
        $versions = [];

        foreach (self::MANIFESTS as $basename => $kind) {
            $contents = $this->readManifest($repoPath.'/'.$basename);
            if ($contents === null) {
                continue;
            }

            $manifests[] = $basename;
            $data = match ($kind) {
                'composer' => ComposerJsonParser::parse($contents),
                'npm' => PackageJsonParser::parse($contents),
                'pyproject' => PyprojectParser::parse($contents),
                default => RequirementsParser::parse($contents),
            };

            array_push($frameworks, ...$data->frameworks);
            array_push($tooling, ...$data->tooling);
            $versions += $data->versions;
            $dependencies[$data->ecosystem] = array_values(array_unique([...($dependencies[$data->ecosystem] ?? []), ...$data->dependencies]));
        }

        $presence = ToolingDetector::detect($treePaths);
        array_push($tooling, ...$presence['tooling']);

        if (in_array('tsconfig.json', $treePaths, true) && ! isset($languages['TypeScript'])) {
            $tooling[] = 'typescript';
        }

        $packageManagers = $presence['package_managers'];
        foreach ($manifests as $manifest) {
            $packageManagers[] = match ($manifest) {
                'composer.json' => 'composer',
                'package.json' => 'npm',
                default => 'pip',
            };
        }

        return new Stack(
            languages: array_map('intval', $languages),
            frameworks: self::unique($frameworks),
            tooling: self::unique($tooling),
            manifests: $manifests,
            packageManagers: self::unique($packageManagers),
            dependencies: $dependencies,
            versions: $versions,
        );
    }

    private function readManifest(string $path): ?string
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_MANIFEST_BYTES) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        $values = array_values(array_unique(array_map('strtolower', $values)));
        sort($values);

        return $values;
    }
}
