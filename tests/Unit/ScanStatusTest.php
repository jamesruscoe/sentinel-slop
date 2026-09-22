<?php

use App\Enums\ScanStatus;

test('complete and failed are the only terminal statuses', function () {
    $terminal = array_filter(ScanStatus::cases(), fn (ScanStatus $s) => $s->isTerminal());

    expect(array_values($terminal))->toBe([ScanStatus::Complete, ScanStatus::Failed]);
});

test('the pipeline order matches the brief', function () {
    expect(array_map(fn (ScanStatus $s) => $s->value, ScanStatus::pipeline()))->toBe([
        'queued', 'fetching', 'preflight', 'detecting', 'analysing', 'heuristics',
        'normalising', 'scoring', 'synthesising', 'complete',
    ]);
});

test('progress increases monotonically through the pipeline', function () {
    $last = -1;
    foreach (ScanStatus::pipeline() as $status) {
        expect($status->progress())->toBeGreaterThanOrEqual($last);
        $last = $status->progress();
    }
});
