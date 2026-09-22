<x-layouts.app title="Connecting to GitHub">
    <x-slot:head>
        <meta http-equiv="refresh" content="4">
    </x-slot:head>
    <div class="max-w-xl space-y-3">
        <h1 class="text-2xl font-semibold">Waiting for GitHub…</h1>
        <p class="text-zinc-300">GitHub is telling us about installation #{{ $installationId }}. This page refreshes automatically.</p>
        <p class="text-sm text-zinc-500">If nothing happens after a minute, the installation may belong to a different GitHub account than the one you signed in with.</p>
        <a href="{{ route('dashboard') }}" class="text-sm text-indigo-400 hover:text-indigo-300">Back to dashboard</a>
    </div>
</x-layouts.app>
