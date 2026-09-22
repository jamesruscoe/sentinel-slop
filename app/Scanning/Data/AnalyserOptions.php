<?php

declare(strict_types=1);

namespace App\Scanning\Data;

/**
 * Where the bundled configs live and how long tools may run.
 */
final class AnalyserOptions
{
    public function __construct(
        public readonly string $configPath,
        public readonly string $semgrepRulesPath,
        public readonly int $timeoutSeconds = 300,
        public readonly string $phpstanMemoryLimit = '1G',
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `sentinel` config array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            configPath: rtrim(str_replace(chr(92), '/', (string) ($config['paths']['analyser_configs'] ?? '')), '/'),
            semgrepRulesPath: rtrim(str_replace(chr(92), '/', (string) ($config['paths']['semgrep_rules'] ?? '')), '/'),
            timeoutSeconds: (int) ($config['tools']['timeout_seconds'] ?? 300),
            phpstanMemoryLimit: (string) ($config['tools']['phpstan_memory_limit'] ?? '1G'),
        );
    }

    public function configFile(string $name): string
    {
        return $this->configPath.'/'.$name;
    }
}
