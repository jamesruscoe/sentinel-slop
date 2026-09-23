<?php

namespace App\Services;

use App\Models\Capacity;
use App\Support\AuditTrail;

class CapacityService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Capacity
    {
        $model = Capacity::query()->create($attributes);
        $this->audit->record('capacity.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.capacity_limit', 10);
    }
}
