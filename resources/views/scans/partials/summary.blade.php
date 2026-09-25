@php
    $severityColours = ['critical' => 'bg-rose-500', 'high' => 'bg-orange-400', 'medium' => 'bg-amber-300', 'low' => 'bg-ink-500', 'info' => 'bg-ink-600'];
    $findingTotal = array_sum($severityCounts);
@endphp
<section class="grid gap-4 lg:grid-cols-[1fr_1fr_1.25fr]">
    <div class="surface relative overflow-hidden p-6">
        <div class="pointer-events-none absolute -top-16 -right-16 h-48 w-48 rounded-full bg-violet-600/20 blur-3xl"></div>
        <div class="eyebrow">Slop score</div>
        <div class="mt-4 flex items-center gap-5">
            <x-score-ring :score="$scan->slop_score" size="lg" />
            <div class="text-sm text-ink-400">out of <span class="text-ink-200">100</span><br>higher is cleaner</div>
        </div>
        <p class="mt-5 text-sm leading-relaxed text-ink-300">
            @if ($hasCritical)
                Capped at {{ config('sentinel.score.critical_cap') }} because secrets or malware-like patterns were found.
            @elseif ($scan->slop_score >= 80)
                Light problems only. Clean code scores 100.
            @elseif ($scan->slop_score >= 50)
                Moderate problems. Work through the phased prompts below in order, then scan again to measure the change.
            @else
                Dense problems. Work through the phased prompts below in order, then scan again to measure the change.
            @endif
        </p>
    </div>

    <div class="surface p-6 text-sm">
        <div class="flex items-baseline justify-between">
            <div class="eyebrow">Findings</div>
            <span class="text-2xl font-semibold tracking-tight text-white tabular-nums">{{ number_format($findingTotal) }}</span>
        </div>
        @if ($findingTotal > 0)
            <div class="mt-4 flex h-2 gap-0.5 overflow-hidden rounded-full">
                @foreach ($severityCounts as $severity => $count)
                    @if ($count > 0)
                        <div class="{{ $severityColours[$severity] ?? 'bg-ink-600' }}" style="flex: {{ max($count / $findingTotal, 0.02) }}"></div>
                    @endif
                @endforeach
            </div>
        @endif
        <dl class="mt-4 space-y-2">
            @foreach ($severityCounts as $severity => $count)
                @if ($count > 0)
                    <div class="flex items-center justify-between">
                        <dt class="flex items-center gap-2 capitalize text-ink-200"><span class="h-2 w-2 rounded-full {{ $severityColours[$severity] ?? 'bg-ink-600' }}"></span>{{ $severity }}</dt>
                        <dd class="text-ink-200 tabular-nums">{{ $count }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
        <dl class="mt-4 space-y-2 border-t border-white/[0.06] pt-4 text-ink-400">
            <div class="flex justify-between">
                <dt>Lines of code</dt><dd class="text-ink-300 tabular-nums">{{ number_format((int) $scan->lines_of_code) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>Inline suppressions</dt><dd class="text-ink-300 tabular-nums">{{ (int) $scan->suppression_count }} ({{ $scan->suppression_density ?? 0 }} / kloc)</dd>
            </div>
        </dl>
    </div>

    <div class="surface p-6 text-sm">
        <div class="eyebrow">Stack</div>
        <div class="mt-4 flex flex-wrap gap-1.5">
            @forelse ($stack->languagePercentages() as $language => $percent)
                <span class="chip"><span class="text-ink-100">{{ $language }}</span> {{ $percent }}%</span>
            @empty
                <span class="text-ink-500">Languages unknown</span>
            @endforelse
        </div>
        <dl class="mt-4 space-y-1.5">
            <div><dt class="inline text-ink-400">Frameworks:</dt> <dd class="inline text-ink-200">{{ $stack->describeFrameworks() ?: 'none detected' }}</dd></div>
            @if ($stack->describeRuntimes() !== '')
                <div><dt class="inline text-ink-400">Runtimes:</dt> <dd class="inline text-ink-200">{{ $stack->describeRuntimes() }}</dd></div>
            @endif
            <div><dt class="inline text-ink-400">Tooling:</dt> <dd class="inline text-ink-200">{{ implode(', ', $stack->tooling) ?: 'none detected' }}</dd></div>
        </dl>
        @php($coverage = \App\Scanning\Analysers\LanguageCoverage::describe($stack, \App\Scanning\Analysers\AnalyserFailure::list($scan->analyser_failures)))
        <dl class="mt-4 space-y-2 border-t border-white/[0.06] pt-4 text-xs leading-relaxed">
            <div><dt class="inline text-ink-400">Language analysers:</dt>
                <dd class="inline text-ink-200">{{ implode('; ', array_map(fn ($lang, $tools) => "{$lang}: ".implode(', ', $tools), array_keys($coverage['analysed']), $coverage['analysed'])) ?: 'none for this stack' }}</dd></div>
            @foreach ($coverage['failed'] as $failed)
                <div class="rounded-lg border border-rose-400/20 bg-rose-500/10 px-2.5 py-1.5 text-rose-200"><dt class="inline font-medium">Did not run:</dt>
                    <dd class="inline">{{ ucfirst($failed) }} Nothing it would have reported is in these findings, and the score does not account for it.</dd></div>
            @endforeach
            @if ($coverage['structural_only'] !== [])
                <div class="rounded-lg border border-amber-400/20 bg-amber-500/10 px-2.5 py-1.5 text-amber-100"><dt class="inline font-medium">Structural analysis only:</dt>
                    <dd class="inline">{{ implode(', ', $coverage['structural_only']) }}. No line-level analyser for {{ count($coverage['structural_only']) === 1 ? 'this language' : 'these languages' }} yet; findings there come from the profile, duplication and secrets scanning.</dd></div>
            @endif
            <div class="text-ink-500">Every language: {{ implode(', ', $coverage['universal']) }}.</div>
        </dl>
    </div>
</section>
