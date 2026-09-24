<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\AuditTrail;

class BookingService
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly NotificationService $notifications,
        private readonly InvoiceService $invoices,
        private readonly ReportService $reports,
    ) {}

    public function create(array $attributes): Booking
    {
        $model = Booking::query()->create($attributes);
        $this->audit->record('booking.created', ['id' => $model->id]);
        $this->notifications->create(['booking_id' => $model->id]);
        $this->invoices->create(['booking_id' => $model->id]);
        $this->reports->create(['booking_id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.booking_limit', 10);
    }
}
