<x-layouts.app title="Repositories">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="eyebrow">Workspace</div>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Repositories</h1>
            @unless ($repositories->isEmpty())
                <p class="mt-1 text-sm text-ink-400">{{ $repositories->count() }} {{ Str::plural('repository', $repositories->count()) }} Sentinel Slop can read.</p>
            @endunless
        </div>
        <a href="{{ route('github.install') }}" class="btn btn-secondary">
            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
            Install on more repositories
        </a>
    </div>

    @if ($repositories->isEmpty())
        <div class="surface grid place-items-center px-6 py-16 text-center">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-violet-500/10 ring-1 ring-violet-400/25">
                <x-github-icon class="h-6 w-6 text-violet-300" />
            </div>
            <p class="mt-4 max-w-md text-ink-300">No repositories yet. Install the GitHub App to choose which repositories Sentinel Slop can read.</p>
            <a href="{{ route('github.install') }}" class="btn btn-primary mt-6">Install the GitHub App</a>
        </div>
    @else
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($repositories as $repository)
                <li class="surface group relative flex flex-col p-5 transition hover:border-violet-400/25 hover:bg-ink-850/80">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a href="{{ route('repositories.scans.index', $repository) }}" class="block truncate text-[15px] font-medium text-white after:absolute after:inset-0 after:content-['']">
                                {{ $repository->full_name }}
                            </a>
                            <div class="mt-1 flex items-center gap-1.5 text-xs text-ink-500">
                                <svg viewBox="0 0 16 16" fill="currentColor" class="h-3 w-3" aria-hidden="true"><path d="M9.5 3.25a2.25 2.25 0 1 1 3 2.122V6A2.5 2.5 0 0 1 10 8.5H6a1 1 0 0 0-1 1v1.128a2.251 2.251 0 1 1-1.5 0V5.372a2.25 2.25 0 1 1 1.5 0v1.836A2.493 2.493 0 0 1 6 7h4a1 1 0 0 0 1-1v-.628A2.25 2.25 0 0 1 9.5 3.25Z" /></svg>
                                {{ $repository->installation->account_login }} · {{ $repository->default_branch ?? 'default branch' }}
                            </div>
                        </div>
                        @if ($repository->latestCompletedScan && ! $repository->latestScan?->isActive())
                            <a href="{{ route('scans.show', $repository->latestCompletedScan) }}" class="relative z-10 -m-1 rounded-full p-1 transition hover:bg-white/[0.04]">
                                <span class="sr-only">Score</span>
                                <x-score-ring :score="$repository->latestCompletedScan->slop_score" />
                            </a>
                        @else
                            <x-score-ring :score="null" />
                        @endif
                    </div>

                    <div class="relative z-10 mt-6 flex items-center justify-between gap-3 border-t border-white/[0.06] pt-4 text-xs text-ink-400">
                        @if ($repository->latestScan?->isActive())
                            <a href="{{ route('scans.show', $repository->latestScan) }}" class="inline-flex items-center gap-2 text-violet-300 hover:text-violet-200">
                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-violet-400"></span>Scan in progress…
                            </a>
                        @else
                            @if ($repository->latestCompletedScan)
                                <span>Scanned {{ $repository->latestCompletedScan->created_at->diffForHumans() }}</span>
                            @elseif ($repository->latestScan)
                                <a href="{{ route('scans.show', $repository->latestScan) }}" class="text-rose-300 hover:text-rose-200">Last scan failed</a>
                            @else
                                <span>Not scanned yet</span>
                            @endif
                            @can('scan', $repository)
                                <form method="POST" action="{{ route('repositories.scans.store', $repository) }}" class="flex items-center gap-2">
                                    @csrf
                                    @if (count($models) > 1)
                                        <select name="model" class="field py-1 text-xs">
                                            @foreach ($models as $model)
                                                <option value="{{ $model }}">{{ $model }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button type="submit" class="btn btn-primary btn-sm">Scan</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
