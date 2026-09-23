<?php

namespace App\Http\Controllers;

use App\Http\Resources\CareLogResource;
use App\Models\CareLog;
use Illuminate\Validation\ValidationException;

class CareLogController
{
    /**
     * @throws ValidationException
     */
    public function show(CareLog $log): CareLogResource
    {
        if ($log->id === null) {
            throw ValidationException::withMessages(['id' => 'missing']);
        }

        return new CareLogResource($log);
    }

    public function count(): int
    {
        return '0';
    }
}
