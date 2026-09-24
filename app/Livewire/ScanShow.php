<?php

namespace App\Livewire;

use App\Enums\ScanStatus;
use App\Models\Finding;
use App\Models\Prompt;
use App\Models\RulesFile;
use App\Models\Scan;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Profile\ProfileFormatter;
use App\Scanning\Profile\RepositoryProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One page for the whole life of a scan: live progress while it runs
 * (Reverb events, with polling as a fallback) and the results once done.
 */
#[Layout('components.layouts.app')]
class ScanShow extends Component
{
    use WithPagination;

    public Scan $scan;

    #[Url(as: 'severity')]
    public string $severityFilter = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'editor')]
    public string $editor = 'claude_code';

    public bool $showPayload = false;

    public bool $showProfile = false;

    public function mount(Scan $scan): void
    {
        Gate::authorize('view', $scan);

        $this->scan = $scan;
        if (TargetEditor::tryFrom($this->editor) === null) {
            $this->editor = TargetEditor::ClaudeCode->value;
        }
    }

    /**
     * Reverb pushes every stage change on the private scan channel; the
     * Livewire Echo listener re-renders the component. The listener exists
     * only when broadcasting is configured: with no window.Echo (production
     * runs without Reverb) Livewire's Echo bridge aborts the component's
     * setup and takes wire:poll down with it, so nothing updated at all.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (config('broadcasting.default', 'null') === 'null') {
            return [];
        }

        return ["echo-private:scans.{$this->scan->uuid},.scan.progressed" => 'refreshScan'];
    }

    public function refreshScan(): void
    {
        $this->scan->refresh();
    }

    /**
     * Polling fallback while the scan is active, in case websockets are down.
     */
    public function poll(): void
    {
        $this->refreshScan();
    }

    public function updatedSeverityFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function targetEditor(): TargetEditor
    {
        return TargetEditor::tryFrom($this->editor) ?? TargetEditor::ClaudeCode;
    }

    /**
     * @return list<array{status: ScanStatus, state: string}>
     */
    #[Computed]
    public function stages(): array
    {
        $current = $this->scan->status;
        $pipeline = ScanStatus::pipeline();
        $currentIndex = array_search($current, $pipeline, true);
        $failed = $current === ScanStatus::Failed;

        $stages = [];
        foreach ($pipeline as $index => $status) {
            if ($status === ScanStatus::Complete) {
                continue;
            }
            $stages[] = ['status' => $status, 'state' => match (true) {
                $failed && $currentIndex === false => 'failed',
                $currentIndex !== false && $index < $currentIndex => 'done',
                $currentIndex !== false && $index === $currentIndex => 'current',
                $current === ScanStatus::Complete => 'done',
                default => 'pending',
            }];
        }

        return $stages;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function severityCounts(): array
    {
        $counts = $this->scan->findings()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->all();

        return array_map(fn (Severity $s) => (int) ($counts[$s->value] ?? 0), array_combine(
            array_map(fn (Severity $s) => $s->value, Severity::cases()),
            Severity::cases(),
        ));
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function categoryCounts(): array
    {
        $counts = $this->scan->findings()->selectRaw('category, count(*) as n')->groupBy('category')->orderByDesc('n')->pluck('n', 'category')->all();

        return array_map('intval', $counts);
    }

    /**
     * @return LengthAwarePaginator<int, Finding>
     */
    #[Computed]
    public function findings(): LengthAwarePaginator
    {
        return $this->scan->findings()
            ->when(Severity::tryFrom($this->severityFilter), fn ($q, $s) => $q->where('severity', $s))
            ->when(FindingCategory::tryFrom($this->categoryFilter), fn ($q, $c) => $q->where('category', $c))
            ->orderByRaw($this->severityOrderSql())
            ->orderBy('file_path')
            ->orderBy('line')
            ->paginate(50);
    }

    /**
     * @return Collection<int, Prompt>
     */
    #[Computed]
    public function prompts(): Collection
    {
        return $this->scan->prompts()->where('target_editor', $this->targetEditor())->orderBy('phase')->get();
    }

    #[Computed]
    public function rulesFile(): ?RulesFile
    {
        return $this->scan->rulesFiles()->where('target_editor', $this->targetEditor())->first();
    }

    /**
     * @return array{total: int, counts: array<string, int>, entries: list<array<string, mixed>>, truncated: bool}
     */
    #[Computed]
    public function skipped(): array
    {
        $files = $this->scan->skipped_files ?? [];

        return [
            'total' => (int) ($files['total'] ?? 0),
            'counts' => array_map('intval', (array) ($files['counts'] ?? [])),
            'entries' => array_values((array) ($files['entries'] ?? [])),
            'truncated' => (bool) ($files['truncated'] ?? false),
        ];
    }

    public function render(): View
    {
        $complete = $this->scan->status === ScanStatus::Complete;

        return view('livewire.scan-show', [
            'title' => 'Scan of '.$this->scan->repository->full_name,
            'complete' => $complete,
            'stages' => $this->stages(),
            'stack' => Stack::fromArray($this->scan->detected_stack ?? []),
            'severityCounts' => $complete ? $this->severityCounts() : [],
            'categoryCounts' => $complete ? $this->categoryCounts() : [],
            'findings' => $complete ? $this->findings() : null,
            'prompts' => $complete ? $this->prompts() : new Collection,
            'targetEditor' => $this->targetEditor(),
            'rulesFile' => $complete ? $this->rulesFile() : null,
            'skipped' => $this->skipped(),
            'profileText' => $complete && $this->scan->profile !== null ? ProfileFormatter::render(RepositoryProfile::fromArray($this->scan->profile)) : null,
            'hasCritical' => $complete && $this->scan->findings()->whereIn('category', [FindingCategory::Malware->value, FindingCategory::Secrets->value])->exists(),
            'models' => (array) config('sentinel.synthesis.models', []),
        ]);
    }

    private function severityOrderSql(): string
    {
        $cases = implode(' ', array_map(fn (Severity $s) => "WHEN '{$s->value}' THEN {$s->rank()}", Severity::cases()));

        return "CASE severity {$cases} ELSE 0 END DESC";
    }
}
