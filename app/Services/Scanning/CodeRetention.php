<?php

namespace App\Services\Scanning;

use App\Models\Finding;
use App\Models\Scan;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Applies config('sentinel.retention') to everything that holds users' code:
 * scans.synthesis_payload and findings.snippet. See config/sentinel.php.
 */
final class CodeRetention
{
    public const MODES = ['retain', 'truncate', 'purge'];

    public const FINDINGS_MARKER = 'Findings (most severe first):';

    public function __construct(private readonly string $mode, private readonly int $days)
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown code retention mode: {$mode}");
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('sentinel.retention.code', 'purge'), (int) config('sentinel.retention.days', 30));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function days(): int
    {
        return $this->days;
    }

    /**
     * @return array{scans: int, findings: int} Rows changed.
     */
    public function apply(?Carbon $now = null): array
    {
        if ($this->mode === 'retain') {
            return ['scans' => 0, 'findings' => 0];
        }

        $cutoff = ($now ?? Carbon::now())->subDays($this->days);

        $findings = Finding::query()
            ->whereNotNull('snippet')
            ->whereIn('scan_id', Scan::query()->select('id')->where('created_at', '<', $cutoff))
            ->update(['snippet' => null]);

        $scans = $this->mode === 'purge'
            ? Scan::query()->whereNotNull('synthesis_payload')->where('created_at', '<', $cutoff)->update(['synthesis_payload' => null])
            : $this->truncatePayloads($cutoff);

        return ['scans' => $scans, 'findings' => $findings];
    }

    private function truncatePayloads(Carbon $cutoff): int
    {
        $changed = 0;

        Scan::query()->whereNotNull('synthesis_payload')->where('created_at', '<', $cutoff)
            ->select(['id', 'synthesis_payload'])
            ->chunkById(100, function ($scans) use (&$changed) {
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
