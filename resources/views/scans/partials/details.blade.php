<section class="mb-8 space-y-4">
    @if ($scan->synthesis_payload)
        <div class="rounded-md border border-zinc-800">
            <button type="button" wire:click="$toggle('showPayload')" class="flex w-full items-center justify-between px-4 py-3 text-left text-sm">
                <span class="font-medium">What was sent to the AI provider</span>
                <span class="text-xs text-zinc-400">
                    @if (isset($scan->synthesis_payload['usage']))
                        {{ number_format($scan->synthesis_payload['usage']['input_tokens']) }} tokens in, {{ number_format($scan->synthesis_payload['usage']['output_tokens']) }} out ·
                    @endif
                    {{ $showPayload ? 'Hide' : 'Show' }}
                </span>
            </button>
            @if ($showPayload)
                <div class="space-y-3 border-t border-zinc-800 px-4 py-3 text-xs">
                    <p class="text-zinc-400">Exactly this text, and nothing else from your repository, went to the model ({{ $scan->synthesis_payload['model'] }}). Secret values were redacted before it was built.</p>
                    <div class="font-medium text-zinc-300">System prompt</div>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-zinc-900 p-3 text-zinc-300">{{ $scan->synthesis_payload['system'] }}</pre>
                    <div class="font-medium text-zinc-300">User prompt</div>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-zinc-900 p-3 text-zinc-300">{{ $scan->synthesis_payload['user'] }}</pre>
                </div>
            @endif
        </div>
    @endif

    @if ($skipped['total'] > 0)
        <div class="rounded-md border border-zinc-800 px-4 py-3 text-sm">
            <div class="font-medium">{{ $skipped['total'] }} file{{ $skipped['total'] === 1 ? '' : 's' }} skipped</div>
            <p class="mt-1 text-xs text-zinc-400">
                @foreach ($skipped['counts'] as $reason => $count)
                    {{ str_replace('_', ' ', $reason) }}: {{ $count }}{{ $loop->last ? '' : ' · ' }}
                @endforeach
                @if ($skipped['truncated']) · list truncated @endif
            </p>
        </div>
    @endif
</section>
