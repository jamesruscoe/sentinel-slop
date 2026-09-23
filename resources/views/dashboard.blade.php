<x-layouts.app title="Repositories">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Repositories</h1>
        <a href="{{ route('github.install') }}" class="rounded-md border border-zinc-700 px-3 py-1.5 text-sm font-medium text-zinc-200 hover:text-white">Install on more repositories</a>
    </div>

    @if ($repositories->isEmpty())
        <p class="text-zinc-400">No repositories yet. Install the GitHub App to choose which repositories Sentinel Slop can read.</p>
    @else
        <ul class="divide-y divide-zinc-800 rounded-md border border-zinc-800">
            @foreach ($repositories as $repository)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <a href="{{ route('repositories.scans.index', $repository) }}" class="font-medium hover:text-white">{{ $repository->full_name }}</a>
                        <div class="text-xs text-zinc-500">{{ $repository->installation->account_login }} · {{ $repository->default_branch ?? 'default branch' }}</div>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-zinc-400">
                        @if ($repository->latestScan?->isActive())
                            <a href="{{ route('scans.show', $repository->latestScan) }}" class="text-indigo-300 hover:text-white">Scan in progress…</a>
                        @else
                            @if ($repository->latestCompletedScan)
                                <a href="{{ route('scans.show', $repository->latestCompletedScan) }}" class="hover:text-white">Score <x-score-badge :score="$repository->latestCompletedScan->slop_score" /></a>
                            @elseif ($repository->latestScan)
                                <a href="{{ route('scans.show', $repository->latestScan) }}" class="hover:text-white">Last scan failed</a>
                            @else
                                <span>Not scanned yet</span>
                            @endif
                            @can('scan', $repository)
                                <form method="POST" action="{{ route('repositories.scans.store', $repository) }}" class="flex items-center gap-2">
                                    @csrf
                                    @if (count($models) > 1)
                                        <select name="model" class="rounded-md border border-zinc-700 bg-zinc-900 px-2 py-1 text-xs">
                                            @foreach ($models as $model)
                                                <option value="{{ $model }}">{{ $model }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-500">Scan</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
