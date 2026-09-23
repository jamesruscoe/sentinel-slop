<?php

namespace App\Services\Scanning;

use App\Models\Scan;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Applies config('sentinel.retention') to scans.synthesis_payload, which
 * contains snippets of users' code. See config/sentinel.php.
 */
final class SynthesisPayloadRetention
{
    public const MODES = ['retain', 'truncate', 'purge'];

    public const FINDINGS_MARKER = 'Findings (most severe first):';

    public function __construct(private readonly string $mode, private readonly int $days)
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown synthesis payload retention mode: {$mode}");
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('sentinel.retention.synthesis_payload', 'purge'),
            (int) config('sentinel.retention.synthesis_payload_days', 30),
        );
    }

    /**
     * @return int Number of scans changed.
     */
    public function apply(?Carbon $now = null): int
    {
        if ($this->mode === 'retain') {
            return 0;
        }

        $cutoff = ($now ?? Carbon::now())->subDays($this->days);
        $query = Scan::query()->whereNotNull('synthesis_payload')->where('created_at', '<', $cutoff);

        if ($this->mode === 'purge') {
            return $query->update(['synthesis_payload' => null]);
        }

        $changed = 0;
        $query->select(['id', 'synthesis_payload'])->chunkById(100, function ($scans) use (&$changed) {
            foreach ($scans as $scan) {
                $payload = $scan->synthesis_payload;
                if (! is_array($payload) || ($payload['truncated'] ?? false) === true) {
                    continue;
                }
                $scan->forceFill(['synthesis_payload' => self::truncate($payload)])->save();
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Keeps the system prompt and the user prompt's header (stack, score,
     * category counts); drops everything from the findings list onwards.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function truncate(array $payload): array
    {
        $user = (string) ($payload['user'] ?? '');
        $position = strpos($user, self::FINDINGS_MARKER);
        $payload['user'] = ($position === false ? $user : substr($user, 0, $position + strlen(self::FINDINGS_MARKER)))."\n[findings removed by retention policy]\n";
        $payload['truncated'] = true;

        return $payload;
    }
}
