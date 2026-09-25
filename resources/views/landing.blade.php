<x-layouts.app>
    <section class="grid items-center gap-12 pt-4 lg:grid-cols-[1.15fr_1fr] lg:gap-16 lg:pt-10">
        <div>
            <span class="inline-flex items-center gap-2 rounded-full border border-violet-400/25 bg-violet-500/10 px-3 py-1 text-xs font-medium text-violet-200">
                <span class="h-1.5 w-1.5 rounded-full bg-violet-400 shadow-[0_0_8px_2px_rgb(167_139_250/0.7)]"></span>
                Code review for the agent era
            </span>
            <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-[-0.03em] text-white sm:text-6xl">
                Find the AI slop in your repo.
                <span class="text-gradient">Get prompts that fix it.</span>
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-ink-300">
                Sentinel Slop scans a GitHub repository for low-quality, AI-generated code patterns, works out
                your stack, applies best practices for it, and generates phased fix-it prompts you paste into
                Claude Code or Cursor.
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                @guest
                    <a href="{{ route('auth.github') }}" class="btn btn-primary px-5 py-2.5 text-base">
                        <x-github-icon class="h-4.5 w-4.5" />
                        Sign in with GitHub
                    </a>
                @else
                    <a href="{{ route('dashboard') }}" class="btn btn-primary px-5 py-2.5 text-base">Go to your dashboard</a>
                @endguest
                <a href="#how-it-works" class="btn btn-secondary px-5 py-2.5 text-base">How it works</a>
            </div>
        </div>

        {{-- Illustrative result card --}}
        <div class="relative" aria-hidden="true">
            <div class="absolute -inset-6 rounded-[2rem] bg-gradient-to-br from-violet-600/30 via-fuchsia-500/10 to-transparent blur-2xl"></div>
            <div class="surface relative overflow-hidden p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-mono text-xs text-ink-400">acme/checkout-service</div>
                        <div class="mt-1 text-sm font-medium text-white">Scan complete</div>
                    </div>
                    <span class="chip font-mono">main · 3f2a91c</span>
                </div>
                <div class="mt-6 flex items-center gap-6">
                    <x-score-ring :score="62" size="lg" />
                    <div class="flex-1 space-y-2.5 text-sm">
                        @foreach ([['High', 4, 'bg-orange-400', 'w-2/12'], ['Medium', 11, 'bg-amber-400', 'w-6/12'], ['Low', 23, 'bg-ink-500', 'w-10/12']] as [$label, $count, $colour, $width])
                            <div>
                                <div class="flex justify-between text-xs text-ink-300"><span>{{ $label }}</span><span class="tabular-nums">{{ $count }}</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-white/[0.06]"><div class="h-full rounded-full {{ $colour }} {{ $width }}"></div></div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="mt-6 space-y-2">
                    @foreach (['Fix swallowed exceptions in the payment flow', 'Add logging to external API calls', 'Collapse the duplicated form handlers'] as $i => $phase)
                        <div class="flex items-center gap-3 rounded-xl border border-white/[0.06] bg-ink-950/60 px-3 py-2.5 text-sm">
                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-violet-500/15 text-xs font-semibold text-violet-200 ring-1 ring-violet-400/30">{{ $i + 1 }}</span>
                            <span class="truncate text-ink-200">{{ $phase }}</span>
                            <span class="ml-auto shrink-0 rounded-md border border-white/10 px-1.5 py-0.5 text-[10px] text-ink-400">Copy</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="mt-24 grid gap-4 md:grid-cols-3">
        @foreach ([
            ['Your code is only read, never run.', 'Nothing from your repository is executed, installed, built, imported or loaded as configuration.', 'M12 3 4 6.5v5.2c0 4.5 3.4 8.1 8 9.3 4.6-1.2 8-4.8 8-9.3V6.5L12 3Z'],
            ['Files are deleted after every scan,', 'whether it succeeds or fails.', 'M5 7h14M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3'],
            ['Short, redacted code snippets are sent to an AI provider', 'to generate the prompts. Never the whole repository, and never secret values.', 'M4 12h4l2-6 4 12 2-6h4'],
        ] as [$lead, $rest, $icon])
            <div class="surface p-6">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-violet-500/10 ring-1 ring-violet-400/25">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-violet-300" aria-hidden="true"><path d="{{ $icon }}" /></svg>
                </div>
                <p class="mt-4 leading-relaxed text-ink-300"><span class="font-medium text-white">{{ $lead }}</span> {{ $rest }}</p>
            </div>
        @endforeach
    </section>

    <section id="how-it-works" class="mt-24 scroll-mt-28">
        <div class="eyebrow">How it works</div>
        <h2 class="mt-3 text-3xl font-semibold tracking-tight text-white">Three steps from slop to a plan.</h2>
        <ol class="mt-10 grid gap-4 md:grid-cols-3">
            @foreach ([
                ['Install the GitHub App', 'Pick exactly which repositories Sentinel Slop may read. Read-only contents access, nothing else.'],
                ['Scan', 'Static analysers, slop heuristics and a structural profile run against a throwaway copy, then a model reviews the results.'],
                ['Paste the phases', 'Each phase is a prompt for Claude Code or Cursor, ordered correctness first, then observability, structure and style.'],
            ] as $i => [$heading, $body])
                <li class="relative pl-12">
                    <span class="absolute top-0 left-0 grid h-8 w-8 place-items-center rounded-full bg-gradient-to-b from-violet-500 to-fuchsia-600 text-sm font-semibold text-white shadow-[0_0_20px_-4px_rgb(168_85_247/0.8)]">{{ $i + 1 }}</span>
                    <h3 class="font-medium text-white">{{ $heading }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-ink-400">{{ $body }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</x-layouts.app>
