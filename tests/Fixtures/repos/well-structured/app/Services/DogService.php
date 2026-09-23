<?php

namespace App\Services;

use App\Models\Dog;
use App\Support\AuditTrail;

class DogService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Dog
    {
        $model = Dog::query()->create($attributes);
        $this->audit->record('dog.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.dog_limit', 10);
    }
}
