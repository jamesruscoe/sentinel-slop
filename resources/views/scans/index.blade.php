<x-layouts.app :title="$title">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            @isset($repository)
                <a href="{{ route('scans.index') }}" class="inline-flex items-center gap-1 text-sm text-ink-400 transition hover:text-white">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
                    All repositories
                </a>
                <h1 class="mt-2 truncate text-3xl font-semibold tracking-tight text-white">{{ $repository->full_name }}</h1>
            @else
                <div class="eyebrow">Activity</div>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Scan history</h1>
            @endisset
            @unless ($scans->isEmpty())
                <p class="mt-1 text-sm text-ink-400">{{ number_format($scans->total()) }} {{ Str::plural('scan', $scans->total()) }}</p>
            @endunless
        </div>
        @isset($repository)
            @can('scan', $repository)
                <form method="POST" action="{{ route('repositories.scans.store', $repository) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" /></svg>
                        Scan now
                    </button>
                </form>
            @endcan
        @endisset
    </div>

    @if ($scans->isEmpty())
        <div class="surface px-6 py-14 text-center text-ink-400">No scans yet.</div>
    @else
        <ul class="surface divide-y divide-white/[0.05] overflow-hidden">
            @foreach ($scans as $scan)
                <li>
                    <a href="{{ route('scans.show', $scan) }}" class="group flex items-center justify-between gap-4 px-4 py-3.5 transition hover:bg-white/[0.03] sm:px-5">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-ink-100 group-hover:text-white">
                                @isset($repository)
                                    Scan {{ $scan->created_at->format('j M Y, H:i') }}
                                @else
                                    {{ $scan->repository->full_name }}
                                @endisset
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-500">
                                <span>{{ $scan->created_at->diffForHumans() }}</span>
                                <span class="text-ink-600">•</span>
                                <span>{{ $scan->llm_model }}</span>
                                @if ($scan->commit_sha)
                                    <span class="text-ink-600">•</span>
                                    <span class="font-mono text-ink-400">{{ substr($scan->commit_sha, 0, 8) }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <x-scan-status :status="$scan->status" />
                            @if ($scan->status === \App\Enums\ScanStatus::Complete)
                                <x-score-badge :score="$scan->slop_score" />
                            @else
                                <span class="hidden w-9 sm:block"></span>
                            @endif
                            <svg viewBox="0 0 20 20" fill="currentColor" class="hidden h-4 w-4 text-ink-600 transition group-hover:translate-x-0.5 group-hover:text-ink-300 sm:block" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="mt-6">{{ $scans->links() }}</div>
    @endif
</x-layouts.app>
