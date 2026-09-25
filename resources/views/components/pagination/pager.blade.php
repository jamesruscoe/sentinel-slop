@props(['paginator', 'elements', 'livewire' => false])
@php
    $base = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2.5 text-sm tabular-nums transition';
    $idle = $base.' text-ink-300 hover:bg-white/[0.06] hover:text-white';
    $off = $base.' text-ink-600 cursor-default';
    $current = $base.' bg-gradient-to-b from-violet-500 to-violet-600 font-medium text-white shadow-[0_4px_14px_-4px_rgb(139_92_246/0.8)]';
    $prev = '<svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>';
    $link = function (int $page, string $label, string $class, string $aria) use ($paginator, $livewire): string {
        $attributes = 'class="'.$class.'" aria-label="'.e($aria).'"';
        if (! $livewire) {
            return '<a href="'.e($paginator->url($page)).'" '.$attributes.'>'.$label.'</a>';
        }
        $pageName = e($paginator->getPageName());

        return '<button type="button" wire:click="gotoPage('.$page.', \''.$pageName.'\')" x-on:click="$el.closest(\'section\')?.scrollIntoView({ behavior: \'smooth\' })" wire:loading.attr="disabled" '.$attributes.'>'.$label.'</button>';
    };
    $next = '<svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';
@endphp
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-500">
            Showing <span class="text-ink-300">{{ $paginator->firstItem() }}</span> to <span class="text-ink-300">{{ $paginator->lastItem() }}</span> of <span class="text-ink-300">{{ number_format($paginator->total()) }}</span> results
        </p>
        <div class="flex items-center gap-0.5 rounded-xl border border-white/[0.07] bg-ink-900/70 p-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">{!! $prev !!}</span>
            @else
                {!! $link($paginator->currentPage() - 1, $prev, $idle, __('pagination.previous')) !!}
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $off }}" aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $current }}" aria-current="page">{{ $page }}</span>
                        @else
                            <span class="hidden sm:inline-flex">{!! $link($page, (string) $page, $idle, __('Go to page :page', ['page' => $page])) !!}</span>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                {!! $link($paginator->currentPage() + 1, $next, $idle, __('pagination.next')) !!}
            @else
                <span class="{{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.next') }}">{!! $next !!}</span>
            @endif
        </div>
    </nav>
@endif
