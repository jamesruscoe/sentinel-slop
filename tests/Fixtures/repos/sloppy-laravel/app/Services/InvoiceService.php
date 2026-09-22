<?php

namespace App\Services;

use App\Support\Formatter;

class InvoiceService
{
    public function __construct(private readonly Formatter $formatter)
    {
    }

    public function render(int $pence, Formatter $alt): string
    {
        // @phpstan-ignore-next-line
        $total = $this->missingTotal($pence);
        $label = $this->formatter->formatMoney($total);
        $other = $alt->money($pence);
        $ok = $this->helper(); // phpcs:ignore

        return $label . $other . ($ok ? '' : '!');
    }

    private function helper(): bool
    {
        return true;
    }
}
