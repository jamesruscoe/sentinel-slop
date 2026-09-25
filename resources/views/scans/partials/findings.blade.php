<section class="surface p-6 sm:p-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="flex items-center gap-2.5 text-lg font-semibold text-white">Findings <span class="rounded-full bg-white/[0.06] px-2 py-0.5 text-xs font-medium text-ink-300 tabular-nums">{{ $findings->total() }}</span></h2>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="severityFilter" class="field" aria-label="Filter by severity">
                <option value="">All severities</option>
                @foreach ($severityCounts as $severity => $count)
                    <option value="{{ $severity }}">{{ ucfirst($severity) }} ({{ $count }})</option>
                @endforeach
            </select>
            <select wire:model.live="categoryFilter" class="field" aria-label="Filter by category">
                <option value="">All categories</option>
                @foreach ($categoryCounts as $category => $count)
                    <option value="{{ $category }}">{{ \App\Scanning\Enums\FindingCategory::from($category)->label() }} ({{ $count }})</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($findings->isEmpty())
        <div class="mt-6 grid place-items-center rounded-xl border border-dashed border-white/10 px-6 py-10 text-center">
            <svg viewBox="0 0 20 20" fill="currentColor" class="h-6 w-6 text-emerald-400/80" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" /></svg>
            <p class="mt-2 text-sm text-ink-400">No findings{{ $severityFilter || $categoryFilter ? ' match these filters' : '' }}.</p>
        </div>
    @else
        <ul class="mt-6 space-y-2.5">
            @foreach ($findings as $finding)
                @php
                    [$badge, $accent] = match ($finding->severity) {
                        \App\Scanning\Enums\Severity::Critical => ['bg-rose-500/15 text-rose-200 ring-rose-400/40', 'before:bg-rose-500'],
                        \App\Scanning\Enums\Severity::High => ['bg-orange-500/15 text-orange-200 ring-orange-400/35', 'before:bg-orange-400'],
                        \App\Scanning\Enums\Severity::Medium => ['bg-amber-400/10 text-amber-200 ring-amber-300/30', 'before:bg-amber-300'],
                        \App\Scanning\Enums\Severity::Low => ['bg-white/[0.05] text-ink-300 ring-white/10', 'before:bg-ink-600'],
                        default => ['bg-white/[0.04] text-ink-400 ring-white/10', 'before:bg-ink-700'],
                    };
                @endphp
                <li class="relative overflow-hidden rounded-xl border border-white/[0.06] bg-ink-950/40 py-3.5 pr-4 pl-5 text-sm before:absolute before:inset-y-0 before:left-0 before:w-[3px] {{ $accent }}" wire:key="finding-{{ $finding->id }}">
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <span class="inline-flex shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $badge }}">{{ $finding->severity->label() }}</span>
                        <span class="min-w-0 font-mono text-xs break-all text-ink-200">{{ $finding->location() }}</span>
                        <span class="text-xs text-ink-500">{{ $finding->category->label() }} · {{ $finding->tool }}{{ $finding->rule_id ? '/'.$finding->rule_id : '' }}</span>
                    </div>
                    <p class="mt-2 leading-relaxed text-ink-200">{{ $finding->message }}</p>
                    @if ($finding->snippet)
                        <pre class="code-block mt-3">{{ $finding->snippet }}</pre>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="mt-6">{{ $findings->links() }}</div>
    @endif
</section>
