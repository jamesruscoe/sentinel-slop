@props(['status'])
@php
    $classes = match (true) {
        $status === \App\Enums\ScanStatus::Complete => 'border-emerald-800 bg-emerald-950 text-emerald-200',
        $status === \App\Enums\ScanStatus::Failed => 'border-red-800 bg-red-950 text-red-200',
        default => 'border-indigo-800 bg-indigo-950 text-indigo-200',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {$classes}"]) }}>{{ $status->label() }}</span>
