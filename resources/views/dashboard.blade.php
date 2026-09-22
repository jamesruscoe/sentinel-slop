<x-layouts.app title="Dashboard">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Repositories</h1>
        <a href="{{ route('github.install') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Install on more repositories</a>
    </div>

    @if ($repositories->isEmpty())
        <p class="text-zinc-400">No repositories yet. Install the GitHub App to choose which repositories Sentinel Slop can read.</p>
    @else
        <ul class="divide-y divide-zinc-800 rounded-md border border-zinc-800">
            @foreach ($repositories as $repository)
                <li class="flex items-center justify-between px-4 py-3">
                    <div>
                        <div class="font-medium">{{ $repository->full_name }}</div>
                        <div class="text-xs text-zinc-500">{{ $repository->installation->account_login }} · {{ $repository->default_branch ?? 'default branch' }}</div>
                    </div>
                    <div class="text-sm text-zinc-400">
                        @if ($repository->latestCompletedScan)
                            Score {{ $repository->latestCompletedScan->slop_score }}
                        @else
                            Not scanned yet
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
