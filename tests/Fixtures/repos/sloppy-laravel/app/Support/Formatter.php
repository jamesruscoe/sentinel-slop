<?php

namespace App\Support;

final class Formatter
{
    public function money(int $pence): string
    {
        return number_format($pence / 100, 2);
    }
}
