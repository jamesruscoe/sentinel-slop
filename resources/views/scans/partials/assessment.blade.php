@php($assessment = $scan->assessment)
@if ($assessment)
    <section class="surface relative overflow-hidden p-6 sm:p-8">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-400/60 to-transparent"></div>
        <div class="flex items-center gap-3">
            <div class="grid h-9 w-9 place-items-center rounded-xl bg-violet-500/10 ring-1 ring-violet-400/25">
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4.5 w-4.5 text-violet-300" aria-hidden="true"><path d="M10 1a.75.75 0 0 1 .71.51l1.2 3.58 3.58 1.2a.75.75 0 0 1 0 1.42l-3.58 1.2-1.2 3.58a.75.75 0 0 1-1.42 0l-1.2-3.58-3.58-1.2a.75.75 0 0 1 0-1.42l3.58-1.2 1.2-3.58A.75.75 0 0 1 10 1Zm5 11a.75.75 0 0 1 .7.48l.46 1.2 1.2.46a.75.75 0 0 1 0 1.4l-1.2.46-.46 1.2a.75.75 0 0 1-1.4 0l-.46-1.2-1.2-.46a.75.75 0 0 1 0-1.4l1.2-.46.46-1.2A.75.75 0 0 1 15 12Z" /></svg>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-white">Assessment</h2>
                <p class="text-xs text-ink-500">Written by the model from the repository profile and the findings. It may only name files, symbols and counts it was given.</p>
            </div>
        </div>

        <div class="mt-6 max-w-3xl space-y-3 text-[15px] leading-relaxed text-ink-200">
            @foreach (preg_split('/\n\s*\n|\n/', trim($assessment['summary'])) as $paragraph)
                @if (trim($paragraph) !== '')
                    <p>{{ trim($paragraph) }}</p>
                @endif
            @endforeach
        </div>

        <div class="mt-8 grid gap-4 lg:grid-cols-3">
            <div class="surface-inset p-5">
                <h3 class="flex items-center gap-2 text-xs font-semibold tracking-[0.12em] text-emerald-300 uppercase"><span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>What is working</h3>
                <ul class="mt-4 space-y-2.5 text-sm text-ink-300">
                    @forelse ($assessment['strengths'] as $strength)
                        <li class="flex gap-2.5">
                            <svg viewBox="0 0 16 16" fill="currentColor" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400/80" aria-hidden="true"><path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" /></svg>
                            <span>{{ $strength }}</span>
                        </li>
                    @empty
                        <li class="text-ink-500">Nothing singled out.</li>
                    @endforelse
                </ul>
            </div>

            <div class="surface-inset p-5">
                <h3 class="flex items-center gap-2 text-xs font-semibold tracking-[0.12em] text-amber-300 uppercase"><span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>Structural problems</h3>
                <ol class="mt-4 space-y-4 text-sm">
                    @forelse ($assessment['structural_problems'] as $problem)
                        <li>
                            <div class="font-medium text-ink-100">{{ $problem['title'] }}</div>
                            @if ($problem['evidence'] !== '')
                                <div class="mt-1 text-xs leading-relaxed text-ink-400"><span class="text-ink-500">Evidence:</span> {{ $problem['evidence'] }}</div>
                            @endif
                            @if ($problem['impact'] !== '')
                                <div class="mt-1 text-xs leading-relaxed text-ink-400"><span class="text-ink-500">Impact:</span> {{ $problem['impact'] }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="text-ink-500">None identified.</li>
                    @endforelse
                </ol>
            </div>

            <div class="surface-inset p-5">
                <h3 class="flex items-center gap-2 text-xs font-semibold tracking-[0.12em] text-violet-300 uppercase"><span class="h-1.5 w-1.5 rounded-full bg-violet-400"></span>Recommended refactors</h3>
                <ol class="mt-4 space-y-4 text-sm">
                    @forelse ($assessment['recommended_refactors'] as $refactor)
                        <li>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-ink-100">{{ $refactor['title'] }}</span>
                                @if ($refactor['effort'] !== '')
                                    <span class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-medium tracking-wide text-violet-200 uppercase ring-1 ring-violet-400/25">{{ $refactor['effort'] }}</span>
                                @endif
                            </div>
                            @if ($refactor['rationale'] !== '')
                                <div class="mt-1 text-xs leading-relaxed text-ink-400">{{ $refactor['rationale'] }}</div>
                            @endif
                            @if ($refactor['scope'] !== '')
                                <div class="mt-1 font-mono text-[11px] text-ink-500">{{ $refactor['scope'] }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="text-ink-500">None proposed.</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </section>
@endif
