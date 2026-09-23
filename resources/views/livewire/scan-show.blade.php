<div @if ($scan->isActive()) wire:poll.5s="poll" @endif>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="text-sm text-zinc-400"><a href="{{ route('repositories.scans.index', $scan->repository) }}" class="hover:text-white">{{ $scan->repository->full_name }}</a></div>
            <h1 class="text-2xl font-semibold">Scan {{ $scan->created_at->format('j M Y, H:i') }}</h1>
            <div class="mt-1 text-xs text-zinc-500">
                <x-scan-status :status="$scan->status" />
                @if ($scan->commit_sha) · commit <span class="font-mono">{{ substr($scan->commit_sha, 0, 8) }}</span> @endif
                · model {{ $scan->llm_model }}
                @if ($scan->finished_at && $scan->started_at) · took {{ $scan->started_at->diffForHumans($scan->finished_at, true) }} @endif
            </div>
        </div>
        @if ($scan->isTerminal())
            @can('scan', $scan->repository)
                <form method="POST" action="{{ route('repositories.scans.store', $scan->repository) }}" class="flex items-center gap-2">
                    @csrf
                    @if (count($models) > 1)
                        <select name="model" class="rounded-md border border-zinc-700 bg-zinc-900 px-2 py-1.5 text-sm">
                            @foreach ($models as $model)
                                <option value="{{ $model }}" @selected($model === $scan->llm_model)>{{ $model }}</option>
                            @endforeach
                        </select>
                    @endif
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Scan again</button>
                </form>
            @endcan
        @endif
    </div>

    @if ($scan->isActive())
        <section class="rounded-md border border-zinc-800 p-6" aria-live="polite">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-medium">{{ $scan->status->label() }}…</h2>
                <span class="text-sm text-zinc-400">{{ $scan->status->progress() }}%</span>
            </div>
            <div class="mb-6 h-2 overflow-hidden rounded-full bg-zinc-800">
                <div class="h-full bg-indigo-500 transition-all duration-500" style="width: {{ $scan->status->progress() }}%"></div>
            </div>
            <ol class="grid gap-2 sm:grid-cols-3">
                @foreach ($stages as $stage)
                    <li class="flex items-center gap-2 text-sm {{ match ($stage['state']) { 'done' => 'text-emerald-300', 'current' => 'text-white', 'failed' => 'text-red-300', default => 'text-zinc-500' } }}">
                        <span class="inline-block h-2 w-2 rounded-full {{ match ($stage['state']) { 'done' => 'bg-emerald-400', 'current' => 'animate-pulse bg-indigo-400', 'failed' => 'bg-red-400', default => 'bg-zinc-700' } }}"></span>
                        {{ $stage['status']->label() }}
                    </li>
                @endforeach
            </ol>
            <p class="mt-6 text-xs text-zinc-500">This page updates live. Your repository is only read, never run, and its files are deleted as soon as the scan finishes.</p>
        </section>
    @elseif ($scan->status === \App\Enums\ScanStatus::Failed)
        <section class="rounded-md border border-red-900 bg-red-950/40 p-6">
            <h2 class="font-medium text-red-200">This scan failed</h2>
            <p class="mt-2 text-sm text-red-100">{{ $scan->error_message }}</p>
            <p class="mt-4 text-xs text-zinc-400">All files fetched for this scan have been deleted.</p>
        </section>
    @else
        @include('scans.partials.summary')
        @include('scans.partials.prompts')
        @include('scans.partials.findings')
        @include('scans.partials.details')
    @endif
</div>
