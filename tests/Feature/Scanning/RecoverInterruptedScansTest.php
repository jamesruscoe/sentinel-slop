<?php

use App\Enums\ScanStatus;
use App\Events\ScanFailed;
use App\Events\ScanProgressed;
use App\Models\Installation;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\User;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/sentinel-tests/recover-'.bin2hex(random_bytes(4));
    config()->set('sentinel.scan_storage_path', $this->base);
    config()->set('sentinel.queue.job_timeout_seconds', 900);
    app()->forgetInstance(ScanWorkspaceFactory::class);
    Event::fake([ScanProgressed::class, ScanFailed::class]);

    $this->repository = Repository::factory()->for(Installation::factory()->for(User::factory()))->create();
});

afterEach(function () {
    if (is_dir($this->base)) {
        exec('rm -rf '.escapeshellarg($this->base));
    }
});

function scanWithWorkspace(Repository $repository, ScanStatus $status, Carbon $updatedAt, string $base): Scan
{
    $scan = Scan::factory()->for($repository)->create(['status' => $status]);
    Scan::query()->whereKey($scan->id)->update(['updated_at' => $updatedAt]);
    @mkdir("{$base}/{$scan->uuid}/repo", 0777, true);
    file_put_contents("{$base}/{$scan->uuid}/repo/a.php", '<?php');

    return $scan->fresh();
}

test('scans nothing has touched for longer than a stage may run are failed and their workspaces removed', function () {
    $stale = scanWithWorkspace($this->repository, ScanStatus::Analysing, now()->subMinutes(20), $this->base);
    $recent = scanWithWorkspace($this->repository, ScanStatus::Analysing, now()->subMinutes(5), $this->base);
    $finished = scanWithWorkspace($this->repository, ScanStatus::Complete, now()->subMinutes(1), $this->base);
    @mkdir("{$this->base}/not-a-scan-uuid", 0777, true);
    touch("{$this->base}/not-a-scan-uuid", time() - 3600);

    // The stale scan's workspace goes with its failure; the sweep removes the finished scan's and the unknown one.
    $this->artisan('sentinel:recover-interrupted')->expectsOutputToContain('1 interrupted scan failed, 2 workspaces removed.')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(ScanStatus::Failed)
        ->and($stale->fresh()->error_message)->toContain('interrupted by a deployment or worker restart')
        ->and($stale->fresh()->finished_at)->not->toBeNull()
        ->and(is_dir("{$this->base}/{$stale->uuid}"))->toBeFalse()
        ->and($recent->fresh()->status)->toBe(ScanStatus::Analysing)
        ->and(is_dir("{$this->base}/{$recent->uuid}"))->toBeTrue()
        ->and($finished->fresh()->status)->toBe(ScanStatus::Complete)
        ->and(is_dir("{$this->base}/{$finished->uuid}"))->toBeFalse()
        ->and(is_dir("{$this->base}/not-a-scan-uuid"))->toBeFalse();
    Event::assertDispatchedTimes(ScanFailed::class, 1);
});

test('--force fails every running scan, for the entrypoint of a sole worker after a deploy', function () {
    $recent = scanWithWorkspace($this->repository, ScanStatus::Synthesising, now()->subSeconds(30), $this->base);

    $this->artisan('sentinel:recover-interrupted', ['--force' => true])->expectsOutputToContain('1 interrupted scan failed')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(ScanStatus::Failed)
        ->and(is_dir("{$this->base}/{$recent->uuid}"))->toBeFalse();
});
