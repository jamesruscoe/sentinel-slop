<section class="space-y-3">
    @if ($profileText !== null)
        <div class="surface overflow-hidden">
            <button type="button" wire:click="$toggle('showProfile')" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left text-sm transition hover:bg-white/[0.02]">
                <span class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-white/[0.05] text-ink-300">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" /></svg>
                    </span>
                    <span>
                        <span class="block font-medium text-ink-100">Repository profile</span>
                        <span class="block text-xs text-ink-500">Structural facts from the file tree, no analyser involved</span>
                    </span>
                </span>
                <span class="btn btn-secondary btn-sm">{{ $showProfile ? 'Hide' : 'Show' }}</span>
            </button>
            @if ($showProfile)
                <div class="border-t border-white/[0.06] px-5 py-4 text-xs">
                    <p class="mb-3 text-ink-400">Areas, sizes, per-area logging, error-handling and validation counts, feature spread, duplication clusters, test coverage, dependency usage and the observations the reviewer was given. This is the same text the model read.</p>
                    <pre class="code-block max-h-[32rem] overflow-auto whitespace-pre">{{ $profileText }}</pre>
                </div>
            @endif
        </div>
    @endif

    @if ($scan->synthesis_payload)
        <div class="surface overflow-hidden">
            <button type="button" wire:click="$toggle('showPayload')" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left text-sm transition hover:bg-white/[0.02]">
                <span class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-white/[0.05] text-ink-300">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M6.28 5.22a.75.75 0 0 1 0 1.06L2.56 10l3.72 3.72a.75.75 0 0 1-1.06 1.06L.97 10.53a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Zm7.44 0a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L17.44 10l-3.72-3.72a.75.75 0 0 1 0-1.06ZM11.377 2.011a.75.75 0 0 1 .612.867l-2.5 14.5a.75.75 0 0 1-1.478-.255l2.5-14.5a.75.75 0 0 1 .866-.612Z" clip-rule="evenodd" /></svg>
                    </span>
                    <span>
                        <span class="block font-medium text-ink-100">What was sent to the AI provider</span>
                        @if (isset($scan->synthesis_payload['usage']))
                            <span class="block text-xs text-ink-500">{{ number_format($scan->synthesis_payload['usage']['input_tokens']) }} tokens in, {{ number_format($scan->synthesis_payload['usage']['output_tokens']) }} out</span>
                        @endif
                    </span>
                </span>
                <span class="btn btn-secondary btn-sm">{{ $showPayload ? 'Hide' : 'Show' }}</span>
            </button>
            @if ($showPayload)
                <div class="space-y-3 border-t border-white/[0.06] px-5 py-4 text-xs">
                    <p class="text-ink-400">Exactly this text, and nothing else from your repository, went to the model ({{ $scan->synthesis_payload['model'] }}). Secret values were redacted before it was built.</p>
                    <div class="eyebrow">System prompt</div>
                    <pre class="code-block max-h-96 overflow-auto whitespace-pre-wrap">{{ $scan->synthesis_payload['system'] }}</pre>
                    <div class="eyebrow">User prompt</div>
                    <pre class="code-block max-h-96 overflow-auto whitespace-pre-wrap">{{ $scan->synthesis_payload['user'] }}</pre>
                </div>
            @endif
        </div>
    @endif

    @if ($skipped['total'] > 0)
        <div class="surface flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 text-sm">
            <span class="font-medium text-ink-100">{{ $skipped['total'] }} file{{ $skipped['total'] === 1 ? '' : 's' }} skipped</span>
            <span class="flex flex-wrap gap-1.5">
                @foreach ($skipped['counts'] as $reason => $count)
                    <span class="chip">{{ str_replace('_', ' ', $reason) }} <span class="text-ink-100 tabular-nums">{{ $count }}</span></span>
                @endforeach
                @if ($skipped['truncated'])
                    <span class="chip">list truncated</span>
                @endif
            </span>
        </div>
    @endif
</section>
