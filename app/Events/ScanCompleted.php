<?php

namespace App\Events;

use App\Models\Scan;
use Illuminate\Foundation\Events\Dispatchable;

class ScanCompleted
{
    use Dispatchable;

    public function __construct(public readonly Scan $scan) {}
}
