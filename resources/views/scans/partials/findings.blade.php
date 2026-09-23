<section class="mb-8 rounded-md border border-zinc-800 p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Findings <span class="text-sm font-normal text-zinc-400">{{ $findings->total() }}</span></h2>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <select wire:model.live="severityFilter" class="rounded-md border border-zinc-700 bg-zinc-900 px-2 py-1">
                <option value="">All severities</option>
                @foreach ($severityCounts as $severity => $count)
                    <option value="{{ $severity }}">{{ ucfirst($severity) }} ({{ $count }})</option>
                @endforeach
            </select>
            <select wire:model.live="categoryFilter" class="rounded-md border border-zinc-700 bg-zinc-900 px-2 py-1">
                <option value="">All categories</option>
                @foreach ($categoryCounts as $category => $count)
                    <option value="{{ $category }}">{{ \App\Scanning\Enums\FindingCategory::from($category)->label() }} ({{ $count }})</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($findings->isEmpty())
        <p class="text-sm text-zinc-400">No findings{{ $severityFilter || $categoryFilter ? ' match these filters' : '' }}.</p>
    @else
        <ul class="divide-y divide-zinc-800">
            @foreach ($findings as $finding)
                <li class="py-3 text-sm" wire:key="finding-{{ $finding->id }}">
                    <div class="flex flex-wrap items-start gap-2">
                        <span class="inline-flex shrink-0 rounded px-1.5 py-0.5 text-xs font-medium {{ match ($finding->severity) {
                            \App\Scanning\Enums\Severity::Critical => 'bg-red-900 text-red-100',
                            \App\Scanning\Enums\Severity::High => 'bg-orange-900 text-orange-100',
                            \App\Scanning\Enums\Severity::Medium => 'bg-amber-900 text-amber-100',
                            \App\Scanning\Enums\Severity::Low => 'bg-zinc-800 text-zinc-200',
                            default => 'bg-zinc-800 text-zinc-400',
                        } }}">{{ $finding->severity->label() }}</span>
                        <span class="font-mono text-xs text-zinc-300">{{ $finding->location() }}</span>
                        <span class="text-xs text-zinc-500">{{ $finding->category->label() }} · {{ $finding->tool }}{{ $finding->rule_id ? '/'.$finding->rule_id : '' }}</span>
                    </div>
                    <p class="mt-1 text-zinc-200">{{ $finding->message }}</p>
                    @if ($finding->snippet)
                        <pre class="mt-2 overflow-x-auto rounded bg-zinc-900 px-3 py-2 text-xs text-zinc-300">{{ $finding->snippet }}</pre>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $findings->links() }}</div>
    @endif
</section>
