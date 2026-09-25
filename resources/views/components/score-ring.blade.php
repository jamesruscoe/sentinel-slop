@props(['score', 'size' => 'md'])
@php
    [$box, $stroke, $text] = match ($size) {
        'lg' => [132, 9, 'text-4xl'],
        'sm' => [40, 4, 'text-xs'],
        default => [56, 5, 'text-sm'],
    };
    $radius = ($box - $stroke) / 2;
    $circumference = 2 * M_PI * $radius;
    $fraction = $score === null ? 0 : max(0, min(100, (int) $score)) / 100;
    [$from, $to, $label] = match (true) {
        $score === null => ['#5b5379', '#5b5379', 'text-ink-400'],
        $score >= 80 => ['#34d399', '#6ee7b7', 'text-emerald-300'],
        $score >= 50 => ['#fbbf24', '#fcd34d', 'text-amber-300'],
        default => ['#fb7185', '#f472b6', 'text-rose-300'],
    };
    $id = 'ring-'.uniqid();
@endphp
<span {{ $attributes->merge(['class' => 'relative inline-grid shrink-0 place-items-center']) }} style="width: {{ $box }}px; height: {{ $box }}px">
    <svg width="{{ $box }}" height="{{ $box }}" viewBox="0 0 {{ $box }} {{ $box }}" class="-rotate-90" aria-hidden="true">
        <defs>
            <linearGradient id="{{ $id }}" x1="0" y1="0" x2="1" y2="1">
                <stop stop-color="{{ $from }}" /><stop offset="1" stop-color="{{ $to }}" />
            </linearGradient>
        </defs>
        <circle cx="{{ $box / 2 }}" cy="{{ $box / 2 }}" r="{{ $radius }}" stroke="rgb(255 255 255 / 0.07)" stroke-width="{{ $stroke }}" fill="none" />
        @if ($score !== null)
            <circle cx="{{ $box / 2 }}" cy="{{ $box / 2 }}" r="{{ $radius }}" stroke="url(#{{ $id }})" stroke-width="{{ $stroke }}" fill="none" stroke-linecap="round"
                stroke-dasharray="{{ round($circumference, 2) }}" stroke-dashoffset="{{ round($circumference * (1 - $fraction), 2) }}" />
        @endif
    </svg>
    <span class="absolute font-semibold tracking-tight tabular-nums {{ $text }} {{ $label }}">{{ $score ?? '–' }}</span>
</span>
