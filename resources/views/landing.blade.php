<x-layouts.app>
    <section class="max-w-3xl space-y-6">
        <h1 class="text-4xl font-bold tracking-tight">Find the AI slop in your repo. Get prompts that fix it.</h1>
        <p class="text-lg text-zinc-300">
            Sentinel Slop scans a GitHub repository for low-quality, AI-generated code patterns, works out
            your stack, applies best practices for it, and generates phased fix-it prompts you paste into
            Claude Code or Cursor.
        </p>
        <ul class="space-y-2 text-zinc-300">
            <li><span class="font-medium text-white">Your code is only read, never run.</span> Nothing from your repository is executed, installed, built, imported or loaded as configuration.</li>
            <li><span class="font-medium text-white">Files are deleted after every scan,</span> whether it succeeds or fails.</li>
            <li><span class="font-medium text-white">Short, redacted code snippets are sent to an AI provider</span> to generate the prompts. Never the whole repository, and never secret values.</li>
        </ul>
        @guest
            <a href="{{ route('auth.github') }}" class="inline-block rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Sign in with GitHub</a>
        @else
            <a href="{{ route('dashboard') }}" class="inline-block rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Go to your dashboard</a>
        @endguest
    </section>
</x-layouts.app>
