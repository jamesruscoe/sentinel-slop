<?php

declare(strict_types=1);

namespace App\Scanning\Normalise;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;

/**
 * Turns raw analyser output into the canonical findings shape: relative
 * forward-slash paths, clamped snippets and messages, redacted secrets,
 * de-duplicated, sorted most severe first.
 */
final class FindingNormaliser
{
    private const MAX_MESSAGE_CHARS = 1000;

    private const MAX_SNIPPET_LINE_CHARS = 300;

    public function __construct(
        private readonly SecretRedactor $redactor = new SecretRedactor,
        private readonly FindingDeduplicator $deduplicator = new FindingDeduplicator,
        private readonly int $maxSnippetLines = 6,
    ) {}

    public function normalise(FindingCollection $findings, ?string $repoPath = null): FindingCollection
    {
        $normalised = $findings->map(fn (Finding $f) => $this->redactor->redact($this->clean($f, $repoPath)));
        $deduped = $this->deduplicator->dedupe($normalised);

        $sorted = $deduped->all();
        usort($sorted, fn (Finding $a, Finding $b) => [$b->severity->rank(), $a->filePath, $a->line ?? 0]
            <=> [$a->severity->rank(), $b->filePath, $b->line ?? 0]);

        return new FindingCollection($sorted);
    }

    private function clean(Finding $finding, ?string $repoPath): Finding
    {
        $path = str_replace(chr(92), '/', $finding->filePath);

        if ($repoPath !== null) {
            $root = rtrim(str_replace(chr(92), '/', $repoPath), '/').'/';
            if (str_starts_with($path, $root)) {
                $path = substr($path, strlen($root));
            }
        }

        $path = ltrim(preg_replace('~^(\./)+~', '', $path) ?? $path, '/');

        $message = trim($finding->message);
        if (mb_strlen($message) > self::MAX_MESSAGE_CHARS) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_CHARS - 1).'…';
        }

        return $finding->with([
            'file_path' => $path,
            'line' => $finding->line !== null && $finding->line > 0 ? $finding->line : null,
            'message' => $message,
            'snippet' => $this->clampSnippet($finding->snippet),
        ]);
    }

    private function clampSnippet(?string $snippet): ?string
    {
        if ($snippet === null || trim($snippet) === '') {
            return null;
        }

        $lines = array_slice(preg_split('/\r?\n/', rtrim($snippet)) ?: [], 0, $this->maxSnippetLines);
        $lines = array_map(fn (string $line) => mb_strlen($line) > self::MAX_SNIPPET_LINE_CHARS
            ? mb_substr(rtrim($line), 0, self::MAX_SNIPPET_LINE_CHARS - 1).'…'
            : rtrim($line), $lines);

        return implode("\n", $lines);
    }
}
