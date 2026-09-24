@php($assessment = $scan->assessment)
@if ($assessment)
    <section class="mb-8 rounded-md border border-zinc-800 p-6">
        <h2 class="text-lg font-semibold">Assessment</h2>
        <p class="mt-1 text-xs text-zinc-500">Written by the model from the repository profile and the findings. It may only name files, symbols and counts it was given.</p>

        <div class="mt-4 max-w-3xl space-y-3 text-sm leading-relaxed text-zinc-200">
            @foreach (preg_split('/\n\s*\n|\n/', trim($assessment['summary'])) as $paragraph)
                @if (trim($paragraph) !== '')
                    <p>{{ trim($paragraph) }}</p>
                @endif
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-emerald-300">What is working</h3>
                <ul class="mt-2 space-y-2 text-sm text-zinc-300">
                    @forelse ($assessment['strengths'] as $strength)
                        <li class="flex gap-2"><span class="mt-1.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400"></span><span>{{ $strength }}</span></li>
                    @empty
                        <li class="text-zinc-500">Nothing singled out.</li>
                    @endforelse
                </ul>
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-300">Structural problems</h3>
                <ol class="mt-2 space-y-3 text-sm">
                    @forelse ($assessment['structural_problems'] as $problem)
                        <li>
                            <div class="font-medium text-zinc-100">{{ $problem['title'] }}</div>
                            @if ($problem['evidence'] !== '')
                                <div class="mt-0.5 text-xs text-zinc-400"><span class="text-zinc-500">Evidence:</span> {{ $problem['evidence'] }}</div>
                            @endif
                            @if ($problem['impact'] !== '')
                                <div class="mt-0.5 text-xs text-zinc-400"><span class="text-zinc-500">Impact:</span> {{ $problem['impact'] }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="text-zinc-500">None identified.</li>
                    @endforelse
                </ol>
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-indigo-300">Recommended refactors</h3>
                <ol class="mt-2 space-y-3 text-sm">
                    @forelse ($assessment['recommended_refactors'] as $refactor)
                        <li>
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-100">{{ $refactor['title'] }}</span>
                                @if ($refactor['effort'] !== '')
                                    <span class="rounded-full border border-zinc-700 px-2 py-0.5 text-[10px] uppercase tracking-wide text-zinc-400">{{ $refactor['effort'] }}</span>
                                @endif
                            </div>
                            @if ($refactor['rationale'] !== '')
                                <div class="mt-0.5 text-xs text-zinc-400">{{ $refactor['rationale'] }}</div>
                            @endif
                            @if ($refactor['scope'] !== '')
                                <div class="mt-0.5 font-mono text-[11px] text-zinc-500">{{ $refactor['scope'] }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="text-zinc-500">None proposed.</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </section>
@endif
