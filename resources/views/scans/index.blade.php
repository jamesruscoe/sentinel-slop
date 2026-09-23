<x-layouts.app :title="$title">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ isset($repository) ? $repository->full_name : 'Scan history' }}</h1>
            @isset($repository)
                <a href="{{ route('scans.index') }}" class="text-sm text-zinc-400 hover:text-white">All repositories</a>
            @endisset
        </div>
        @isset($repository)
            @can('scan', $repository)
                <form method="POST" action="{{ route('repositories.scans.store', $repository) }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Scan now</button>
                </form>
            @endcan
        @endisset
    </div>

    @if ($scans->isEmpty())
        <p class="text-zinc-400">No scans yet.</p>
    @else
        <ul class="divide-y divide-zinc-800 rounded-md border border-zinc-800">
            @foreach ($scans as $scan)
                <li>
                    <a href="{{ route('scans.show', $scan) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-900">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $scan->repository->full_name }}</div>
                            <div class="text-xs text-zinc-500">{{ $scan->created_at->diffForHumans() }} · {{ $scan->llm_model }}@if ($scan->commit_sha) · <span class="font-mono">{{ substr($scan->commit_sha, 0, 8) }}</span>@endif</div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <x-scan-status :status="$scan->status" />
                            @if ($scan->status === \App\Enums\ScanStatus::Complete)
                                <x-score-badge :score="$scan->slop_score" />
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $scans->links() }}</div>
    @endif
</x-layouts.app>
