<section class="mb-8 rounded-md border border-zinc-800 p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Fix-it prompts</h2>
        <div class="flex items-center gap-2 text-sm">
            @foreach (\App\Scanning\Enums\TargetEditor::cases() as $target)
                <button type="button" wire:click="$set('editor', '{{ $target->value }}')"
                    class="rounded-md border px-3 py-1 {{ $target === $targetEditor ? 'border-indigo-500 bg-indigo-600 text-white' : 'border-zinc-700 text-zinc-300 hover:text-white' }}">{{ $target->label() }}</button>
            @endforeach
        </div>
    </div>

    @if ($scan->synthesis_error)
        <div class="rounded-md border border-amber-800 bg-amber-950 px-4 py-3 text-sm text-amber-100">{{ $scan->synthesis_error }}</div>
    @elseif ($prompts->isEmpty())
        <p class="text-sm text-zinc-400">No prompts were generated for this scan.</p>
    @else
        <p class="mb-4 text-sm text-zinc-400">Paste each phase into {{ $targetEditor->label() }} in the repository root, in order. Each one runs the tests before and after and stays inside its scope.
            <a href="{{ route('scans.prompts', [$scan, $targetEditor->value]) }}" class="text-indigo-300 hover:text-white">Download all phases</a>.</p>

        <div class="space-y-2" x-data="{ open: 1 }">
            @foreach ($prompts as $prompt)
                <div class="rounded-md border border-zinc-800" x-data="{ copied: false }">
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <button type="button" class="flex-1 text-left font-medium" @click="open = open === {{ $prompt->phase }} ? 0 : {{ $prompt->phase }}">
                            <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-zinc-800 text-xs">{{ $prompt->phase }}</span>{{ $prompt->title }}
                        </button>
                        <button type="button" class="rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-300 hover:text-white"
                            @click="navigator.clipboard.writeText($refs.body{{ $prompt->phase }}.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                    </div>
                    <pre x-show="open === {{ $prompt->phase }}" x-ref="body{{ $prompt->phase }}" class="overflow-x-auto whitespace-pre-wrap border-t border-zinc-800 bg-zinc-900 px-4 py-3 text-xs leading-relaxed text-zinc-200">{{ $prompt->body }}</pre>
                </div>
            @endforeach
        </div>
    @endif

    @if ($rulesFile)
        <div class="mt-6 rounded-md border border-zinc-800" x-data="{ open: false, copied: false }">
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <button type="button" class="flex-1 text-left" @click="open = !open">
                    <span class="font-medium">Rules file</span>
                    <span class="ml-2 font-mono text-xs text-zinc-400">{{ $rulesFile->filename }}</span>
                </button>
                <a href="{{ route('scans.rules', [$scan, $targetEditor->value]) }}" class="rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-300 hover:text-white">Download</a>
                <button type="button" class="rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-300 hover:text-white"
                    @click="navigator.clipboard.writeText($refs.rules.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                    x-text="copied ? 'Copied' : 'Copy'">Copy</button>
            </div>
            <pre x-show="open" x-ref="rules" class="overflow-x-auto whitespace-pre-wrap border-t border-zinc-800 bg-zinc-900 px-4 py-3 text-xs leading-relaxed text-zinc-200">{{ $rulesFile->body }}</pre>
        </div>
    @endif
</section>
