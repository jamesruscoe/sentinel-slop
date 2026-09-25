<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07060d">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body class="relative min-h-full font-sans text-ink-100 antialiased">
    <div class="backdrop-grid" aria-hidden="true"></div>

    <header class="sticky top-0 z-30 px-4 pt-3 sm:pt-4">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 rounded-2xl border border-white/[0.07] bg-ink-900/60 px-3 py-2 shadow-[0_8px_32px_-12px_rgb(0_0_0/0.6)] backdrop-blur-xl sm:px-4">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5 rounded-lg py-1 pr-2 font-semibold tracking-tight text-white">
                <x-logo-mark class="h-7 w-7" />
                <span>Sentinel <span class="text-gradient">Slop</span></span>
            </a>
            <nav class="flex items-center gap-1 text-sm">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-ghost px-2.5 py-1.5 sm:px-3 {{ request()->routeIs('dashboard') ? 'bg-white/[0.06] text-white' : '' }}">Repositories</a>
                    <a href="{{ route('scans.index') }}" class="btn btn-ghost px-2.5 py-1.5 sm:px-3 {{ request()->routeIs('scans.*', 'repositories.scans.*') ? 'bg-white/[0.06] text-white' : '' }}">Scans</a>
                    <span class="mx-1 hidden h-5 w-px bg-white/10 sm:block"></span>
                    <form method="POST" action="{{ route('logout') }}" class="hidden sm:block">
                        @csrf
                        <button type="submit" class="btn btn-ghost px-3 py-1.5 text-ink-400">Sign out</button>
                    </form>
                    <img src="{{ auth()->user()->avatar_url }}" alt="" class="ml-1 hidden h-8 w-8 rounded-full ring-2 ring-violet-500/40 ring-offset-2 ring-offset-ink-900 sm:block">
                @else
                    <a href="{{ route('auth.github') }}" class="btn btn-primary py-1.5">
                        <x-github-icon class="h-4 w-4" />
                        <span>Sign in<span class="hidden sm:inline"> with GitHub</span></span>
                    </a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="relative mx-auto max-w-6xl px-4 pt-10 pb-20 sm:pt-12">
        @if (session('success'))
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-400"></span>{{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-rose-500/25 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                <span class="h-2 w-2 shrink-0 rounded-full bg-rose-400"></span>{{ session('error') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-rose-500/25 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                <span class="h-2 w-2 shrink-0 rounded-full bg-rose-400"></span>{{ $errors->first() }}
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="relative mx-auto max-w-6xl px-4 pb-10">
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-white/[0.06] pt-6 text-xs text-ink-500">
            <span>Sentinel Slop reads your code. It never runs it.</span>
            @auth
                <form method="POST" action="{{ route('logout') }}" class="sm:hidden">
                    @csrf
                    <button type="submit" class="hover:text-ink-300">Sign out</button>
                </form>
            @endauth
        </div>
    </footer>
</body>
</html>
