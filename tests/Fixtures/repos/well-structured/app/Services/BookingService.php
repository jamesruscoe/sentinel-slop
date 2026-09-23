<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\AuditTrail;

class BookingService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Booking
    {
        $model = Booking::query()->create($attributes);
        $this->audit->record('booking.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.booking_limit', 10);
    }
}
