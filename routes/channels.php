<?php

use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('scans.{scan}', fn (User $user, Scan $scan): bool => $user->can('view', $scan));
