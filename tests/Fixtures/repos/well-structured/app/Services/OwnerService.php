<?php

namespace App\Services;

use App\Models\Owner;
use App\Support\AuditTrail;

class OwnerService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Owner
    {
        $model = Owner::query()->create($attributes);
        $this->audit->record('owner.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.owner_limit', 10);
    }
}
