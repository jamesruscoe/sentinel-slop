@props(['status'])
@php
    [$classes, $dot] = match (true) {
        $status === \App\Enums\ScanStatus::Complete => ['border-emerald-400/20 bg-emerald-400/10 text-emerald-200', 'bg-emerald-400'],
        $status === \App\Enums\ScanStatus::Failed => ['border-rose-400/25 bg-rose-400/10 text-rose-200', 'bg-rose-400'],
        default => ['border-violet-400/30 bg-violet-400/10 text-violet-200', 'animate-pulse bg-violet-400'],
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium {$classes}"]) }}><span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>{{ $status->label() }}</span>
