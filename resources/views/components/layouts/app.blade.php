<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body class="min-h-full bg-zinc-950 text-zinc-100 antialiased">
    <header class="border-b border-zinc-800">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <a href="{{ route('home') }}" class="text-lg font-semibold tracking-tight">Sentinel Slop</a>
            <nav class="flex items-center gap-4 text-sm">
                @auth
                    <a href="{{ route('dashboard') }}" class="hover:text-white">Repositories</a>
                    <a href="{{ route('scans.index') }}" class="hover:text-white">Scans</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-zinc-400 hover:text-white">Sign out</button>
                    </form>
                    <img src="{{ auth()->user()->avatar_url }}" alt="" class="h-8 w-8 rounded-full">
                @else
                    <a href="{{ route('auth.github') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500">Sign in with GitHub</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('success'))
            <div class="mb-6 rounded-md border border-emerald-800 bg-emerald-950 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-md border border-red-800 bg-red-950 px-4 py-3 text-sm text-red-200">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-6 rounded-md border border-red-800 bg-red-950 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
