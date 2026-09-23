<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\AuditTrail;

class InvoiceService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Invoice
    {
        $model = Invoice::query()->create($attributes);
        $this->audit->record('invoice.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.invoice_limit', 10);
    }
}
