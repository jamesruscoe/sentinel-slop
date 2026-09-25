<div @if ($scan->isActive()) wire:poll.5s="poll" @endif>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('repositories.scans.index', $scan->repository) }}" class="inline-flex items-center gap-1 text-sm text-ink-400 transition hover:text-white">
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
                {{ $scan->repository->full_name }}
            </a>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Scan {{ $scan->created_at->format('j M Y, H:i') }}</h1>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-400">
                <x-scan-status :status="$scan->status" />
                @if ($scan->commit_sha)
                    <span class="chip">commit <span class="font-mono text-ink-200">{{ substr($scan->commit_sha, 0, 8) }}</span></span>
                @endif
                <span class="chip">model <span class="text-ink-200">{{ $scan->llm_model }}</span></span>
                @if ($scan->finished_at && $scan->started_at)
                    <span class="chip">took <span class="text-ink-200">{{ $scan->started_at->diffForHumans($scan->finished_at, true) }}</span></span>
                @endif
            </div>
        </div>
        @if ($scan->isTerminal())
            @can('scan', $scan->repository)
                <form method="POST" action="{{ route('repositories.scans.store', $scan->repository) }}" class="flex items-center gap-2">
                    @csrf
                    @if (count($models) > 1)
                        <select name="model" class="field">
                            @foreach ($models as $model)
                                <option value="{{ $model }}" @selected($model === $scan->llm_model)>{{ $model }}</option>
                            @endforeach
                        </select>
                    @endif
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" /></svg>
                        Scan again
                    </button>
                </form>
            @endcan
        @endif
    </div>

    @if ($scan->isActive())
        <section class="surface relative overflow-hidden p-6 sm:p-8" aria-live="polite">
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-400/70 to-transparent"></div>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="eyebrow">In progress</div>
                    <h2 class="mt-1.5 text-xl font-medium text-white">{{ $scan->status->label() }}…</h2>
                </div>
                <span class="text-3xl font-semibold tracking-tight text-violet-200 tabular-nums">{{ $scan->status->progress() }}<span class="text-lg text-ink-400">%</span></span>
            </div>
            <div class="mt-5 h-2 overflow-hidden rounded-full bg-white/[0.06]">
                <div class="h-full rounded-full bg-gradient-to-r from-violet-500 via-fuchsia-500 to-violet-400 shadow-[0_0_16px_rgb(168_85_247/0.7)] transition-all duration-700" style="width: {{ $scan->status->progress() }}%"></div>
            </div>
            <ol class="mt-8 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($stages as $stage)
                    <li class="flex items-center gap-3 text-sm {{ match ($stage['state']) { 'done' => 'text-ink-300', 'current' => 'text-white', 'failed' => 'text-rose-300', default => 'text-ink-500' } }}">
                        <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full {{ match ($stage['state']) { 'done' => 'bg-emerald-400/15 text-emerald-300', 'current' => 'bg-violet-500/20 ring-1 ring-violet-400/60', 'failed' => 'bg-rose-400/15 text-rose-300', default => 'ring-1 ring-white/10' } }}">
                            @if ($stage['state'] === 'done')
                                <svg viewBox="0 0 16 16" fill="currentColor" class="h-3 w-3" aria-hidden="true"><path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" /></svg>
                            @elseif ($stage['state'] === 'current')
                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-violet-300"></span>
                            @elseif ($stage['state'] === 'failed')
                                <span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span>
                            @endif
                        </span>
                        {{ $stage['status']->label() }}
                    </li>
                @endforeach
            </ol>
            <p class="mt-8 border-t border-white/[0.06] pt-5 text-xs text-ink-500">This page updates live. Your repository is only read, never run, and its files are deleted as soon as the scan finishes.</p>
        </section>
    @elseif ($scan->status === \App\Enums\ScanStatus::Failed)
        <section class="surface relative overflow-hidden border-rose-500/20 p-6 sm:p-8">
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-rose-400/60 to-transparent"></div>
            <div class="flex gap-4">
                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-rose-500/10 ring-1 ring-rose-400/25">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 text-rose-300" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" /></svg>
                </div>
                <div>
                    <h2 class="text-lg font-medium text-rose-100">This scan failed</h2>
                    <p class="mt-1 text-sm text-ink-300">{{ $scan->error_message }}</p>
                    <p class="mt-4 text-xs text-ink-500">All files fetched for this scan have been deleted.</p>
                </div>
            </div>
        </section>
    @else
        <div class="space-y-6">
            @include('scans.partials.summary')
            @include('scans.partials.assessment')
            @include('scans.partials.prompts')
            @include('scans.partials.findings')
            @include('scans.partials.details')
        </div>
    @endif
</div>
