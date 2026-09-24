<section class="mb-8 grid gap-4 md:grid-cols-3">
    <div class="rounded-md border border-zinc-800 p-6">
        <div class="text-xs uppercase tracking-wide text-zinc-500">Slop score</div>
        <div class="mt-2 flex items-baseline gap-2">
            <x-score-badge :score="$scan->slop_score" size="lg" />
            <span class="text-zinc-500">/ 100</span>
        </div>
        <p class="mt-3 text-xs text-zinc-400">
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

    <div class="rounded-md border border-zinc-800 p-6 text-sm">
        <div class="text-xs uppercase tracking-wide text-zinc-500">Findings</div>
        <dl class="mt-2 space-y-1">
            @foreach ($severityCounts as $severity => $count)
                @if ($count > 0)
                    <div class="flex justify-between">
                        <dt class="capitalize">{{ $severity }}</dt>
                        <dd class="tabular-nums">{{ $count }}</dd>
                    </div>
                @endif
            @endforeach
            <div class="flex justify-between border-t border-zinc-800 pt-1 text-zinc-400">
                <dt>Lines of code</dt><dd class="tabular-nums">{{ number_format((int) $scan->lines_of_code) }}</dd>
            </div>
            <div class="flex justify-between text-zinc-400">
                <dt>Inline suppressions</dt><dd class="tabular-nums">{{ (int) $scan->suppression_count }} ({{ $scan->suppression_density ?? 0 }} / kloc)</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-md border border-zinc-800 p-6 text-sm">
        <div class="text-xs uppercase tracking-wide text-zinc-500">Stack</div>
        <dl class="mt-2 space-y-1">
            <div><dt class="inline text-zinc-400">Languages:</dt> <dd class="inline">{{ implode(', ', array_map(fn ($l, $p) => "{$l} {$p}%", array_keys($stack->languagePercentages()), $stack->languagePercentages())) ?: 'unknown' }}</dd></div>
            <div><dt class="inline text-zinc-400">Frameworks:</dt> <dd class="inline">{{ $stack->describeFrameworks() ?: 'none detected' }}</dd></div>
            @if ($stack->describeRuntimes() !== '')
                <div><dt class="inline text-zinc-400">Runtimes:</dt> <dd class="inline">{{ $stack->describeRuntimes() }}</dd></div>
            @endif
            <div><dt class="inline text-zinc-400">Tooling:</dt> <dd class="inline">{{ implode(', ', $stack->tooling) ?: 'none detected' }}</dd></div>
        </dl>
        @php($coverage = \App\Scanning\Analysers\LanguageCoverage::describe($stack, \App\Scanning\Analysers\AnalyserFailure::list($scan->analyser_failures)))
        <dl class="mt-3 space-y-1 border-t border-zinc-800 pt-3 text-xs">
            <div><dt class="inline text-zinc-400">Language analysers:</dt>
                <dd class="inline">{{ implode('; ', array_map(fn ($lang, $tools) => "{$lang}: ".implode(', ', $tools), array_keys($coverage['analysed']), $coverage['analysed'])) ?: 'none for this stack' }}</dd></div>
            @foreach ($coverage['failed'] as $failed)
                <div class="text-red-300"><dt class="inline">Did not run:</dt>
                    <dd class="inline">{{ ucfirst($failed) }} Nothing it would have reported is in these findings, and the score does not account for it.</dd></div>
            @endforeach
            @if ($coverage['structural_only'] !== [])
                <div class="text-amber-200"><dt class="inline">Structural analysis only:</dt>
                    <dd class="inline">{{ implode(', ', $coverage['structural_only']) }}. No line-level analyser for {{ count($coverage['structural_only']) === 1 ? 'this language' : 'these languages' }} yet; findings there come from the profile, duplication and secrets scanning.</dd></div>
            @endif
            <div class="text-zinc-500">Every language: {{ implode(', ', $coverage['universal']) }}.</div>
        </dl>
    </div>
</section>
