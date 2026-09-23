<?php

use App\Scanning\Data\Finding;
use App\Scanning\Enums\Severity;
use App\Scanning\Heuristics\PlaceholderCodeHeuristic;

test('a method with a TODO and a trivial body is a Medium unimplemented-method finding, not a style note', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/CapacityService.php', <<<'SRC'
<?php

namespace App\Services;

class CapacityService
{
    public function availableKennels(string $date): array
    {
        // TODO: JOB 6 — implement
        return [];
    }

    public function isFull(string $date): bool
    {
        // TODO: JOB 6 — implement
        return false;
    }

    /** TODO: implement once bookings exist */
    public function occupancy(): int
    {
        return 0;
    }

    public function reserve(int $kennelId): void
    {
        // TODO: JOB 6 — implement
    }

    public function realWork(string $date): int
    {
        // TODO: handle bank holidays
        return $this->count($date) + 1;
    }

    private function count(string $date): int
    {
        return strlen($date);
    }

    public function honestConstant(): int
    {
        return 42;
    }
}
SRC);

    $findings = (new PlaceholderCodeHeuristic)->run($workspace->repoPath());
    $unimplemented = array_map(fn (Finding $f) => $f->line, array_values(array_filter($findings->all(), fn (Finding $f) => $f->ruleId === 'unimplemented-method')));

    expect($unimplemented)->toBe([7, 13, 20, 25])
        ->and(array_filter($findings->all(), fn (Finding $f) => $f->ruleId === 'unimplemented-method'))->each(fn ($f) => $f->severity->toBe(Severity::Medium))
        ->and(array_map(fn (Finding $f) => $f->line, array_values(array_filter($findings->all(), fn (Finding $f) => $f->ruleId === 'todo-marker'))))->toBe([9, 15, 19, 27, 32]);
});
