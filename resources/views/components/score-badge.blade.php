@props(['score', 'size' => 'sm'])
@php
    $colour = match (true) {
        $score === null => 'text-ink-400 bg-white/[0.04] ring-white/10',
        $score >= 80 => 'text-emerald-300 bg-emerald-400/10 ring-emerald-400/25',
        $score >= 50 => 'text-amber-300 bg-amber-400/10 ring-amber-400/25',
        default => 'text-rose-300 bg-rose-400/10 ring-rose-400/25',
    };
    $text = $size === 'lg' ? 'text-6xl font-bold tracking-tight' : 'min-w-9 justify-center rounded-md px-1.5 py-0.5 text-sm font-semibold ring-1 ring-inset '.$colour;
@endphp
<span {{ $attributes->merge(['class' => "inline-flex {$text} tabular-nums"]) }}>{{ $score ?? '–' }}</span>
