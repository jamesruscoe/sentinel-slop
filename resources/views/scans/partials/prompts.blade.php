<section class="surface p-6 sm:p-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-white">Fix-it prompts</h2>
            <p class="mt-0.5 text-xs text-ink-500">Phased, ordered, ready to paste into your agent.</p>
        </div>
        <div class="inline-flex rounded-xl border border-white/[0.08] bg-ink-950/70 p-1 text-sm" role="tablist">
            @foreach (\App\Scanning\Enums\TargetEditor::cases() as $target)
                <button type="button" role="tab" aria-selected="{{ $target === $targetEditor ? 'true' : 'false' }}" wire:click="$set('editor', '{{ $target->value }}')"
                    class="rounded-lg px-3.5 py-1.5 font-medium transition {{ $target === $targetEditor ? 'bg-gradient-to-b from-violet-500 to-violet-600 text-white shadow-[0_4px_16px_-4px_rgb(139_92_246/0.8)]' : 'text-ink-400 hover:text-white' }}">{{ $target->label() }}</button>
            @endforeach
        </div>
    </div>

    <div class="mt-6">
        @if ($scan->synthesis_error)
            <div class="flex gap-3 rounded-xl border border-amber-400/20 bg-amber-500/[0.08] px-4 py-3 text-sm text-amber-100">
                <svg viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 h-4 w-4 shrink-0 text-amber-300" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" /></svg>
                <span>{{ $scan->synthesis_error }}</span>
            </div>
        @elseif ($prompts->isEmpty())
            <p class="text-sm text-ink-400">No prompts were generated for this scan.</p>
        @else
            <p class="mb-5 text-sm text-ink-400">Paste each phase into {{ $targetEditor->label() }} in the repository root, in order. Each one runs the tests before and after and stays inside its scope.
                <a href="{{ route('scans.prompts', [$scan, $targetEditor->value]) }}" class="link">Download all phases</a>.</p>

            <ol class="relative space-y-2.5" x-data="{ open: 1 }">
                @foreach ($prompts as $prompt)
                    <li class="overflow-hidden rounded-xl border transition" x-data="{ copied: false }"
                        :class="open === {{ $prompt->phase }} ? 'border-violet-400/30 bg-violet-500/[0.04]' : 'border-white/[0.07] bg-ink-950/40 hover:border-white/15'">
                        <div class="flex items-center gap-3 px-4 py-3">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left font-medium text-ink-100" @click="open = open === {{ $prompt->phase }} ? 0 : {{ $prompt->phase }}">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold transition"
                                    :class="open === {{ $prompt->phase }} ? 'bg-gradient-to-b from-violet-500 to-fuchsia-600 text-white' : 'bg-white/[0.06] text-ink-300'">{{ $prompt->phase }}</span>
                                <span class="min-w-0">{{ $prompt->title }}</span>
                                <svg viewBox="0 0 20 20" fill="currentColor" class="ml-auto h-4 w-4 shrink-0 text-ink-500 transition" :class="open === {{ $prompt->phase }} && 'rotate-180'" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm"
                                @click="navigator.clipboard.writeText($refs.body{{ $prompt->phase }}.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                :class="copied && '!border-emerald-400/40 !text-emerald-200'"
                                x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                        </div>
                        <pre x-show="open === {{ $prompt->phase }}" x-ref="body{{ $prompt->phase }}" class="max-h-[32rem] overflow-auto border-t border-white/[0.06] bg-ink-950/70 px-4 py-4 font-mono text-[12px] leading-relaxed whitespace-pre-wrap text-ink-200">{{ $prompt->body }}</pre>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($rulesFile)
            <div class="mt-6 overflow-hidden rounded-xl border border-white/[0.07] bg-ink-950/40" x-data="{ open: false, copied: false }">
                <div class="flex items-center gap-2 px-4 py-3">
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="open = !open">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-white/[0.06] text-ink-300">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-4.12-4.122A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clip-rule="evenodd" /></svg>
                        </span>
                        <span class="font-medium text-ink-100">Rules file</span>
                        <span class="truncate font-mono text-xs text-violet-300">{{ $rulesFile->filename }}</span>
                    </button>
                    <a href="{{ route('scans.rules', [$scan, $targetEditor->value]) }}" class="btn btn-secondary btn-sm">Download</a>
                    <button type="button" class="btn btn-secondary btn-sm"
                        @click="navigator.clipboard.writeText($refs.rules.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                        x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>
                <pre x-show="open" x-ref="rules" class="max-h-[32rem] overflow-auto border-t border-white/[0.06] bg-ink-950/70 px-4 py-4 font-mono text-[12px] leading-relaxed whitespace-pre-wrap text-ink-200">{{ $rulesFile->body }}</pre>
            </div>
        @endif
    </div>
</section>
