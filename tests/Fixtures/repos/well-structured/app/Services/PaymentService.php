<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\AuditTrail;
use Illuminate\Support\Facades\Http;

class PaymentService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Payment
    {
        $model = Payment::query()->create($attributes);
        $this->audit->record('payment.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.payment_limit', 10);
    }

    public function charge(int $amountInPence): bool
    {
        try {
            $response = Http::withToken((string) config('services.stripe.secret'))->post('https://api.stripe.test/charges', ['amount' => $amountInPence]);

            return $response->successful();
        } catch (\Throwable $e) {
            $this->audit->failure('payment.charge.failed', $e);

            return false;
        }
    }
}
