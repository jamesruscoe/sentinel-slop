<x-layouts.app title="Connecting to GitHub">
    <x-slot:head>
        <meta http-equiv="refresh" content="4">
    </x-slot:head>
    <div class="surface mx-auto mt-6 max-w-xl p-8 text-center">
        <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-violet-500/10 ring-1 ring-violet-400/30">
            <svg viewBox="0 0 24 24" fill="none" class="h-7 w-7 animate-spin text-violet-300" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".2" stroke-width="2.5" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /></svg>
        </div>
        <h1 class="mt-6 text-2xl font-semibold tracking-tight text-white">Waiting for GitHub…</h1>
        <p class="mt-3 text-ink-300">GitHub is telling us about installation #{{ $installationId }}. This page refreshes automatically.</p>
        <p class="mt-3 text-sm text-ink-500">If nothing happens after a minute, the installation may belong to a different GitHub account than the one you signed in with.</p>
        <a href="{{ route('dashboard') }}" class="link mt-6 inline-block text-sm">Back to dashboard</a>
    </div>
</x-layouts.app>
