@props(['score', 'size' => 'sm'])
@php
    $colour = match (true) {
        $score === null => 'text-zinc-500',
        $score >= 80 => 'text-emerald-300',
        $score >= 50 => 'text-amber-300',
        default => 'text-red-300',
    };
    $text = $size === 'lg' ? 'text-6xl font-bold tracking-tight' : 'text-sm font-semibold';
@endphp
<span {{ $attributes->merge(['class' => "{$text} {$colour} tabular-nums"]) }}>{{ $score ?? '–' }}</span>
