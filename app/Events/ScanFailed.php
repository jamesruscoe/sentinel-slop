<?php

namespace App\Events;

use App\Models\Scan;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class ScanFailed
{
    use Dispatchable;

    public function __construct(public readonly Scan $scan, public readonly Throwable $exception) {}
}
