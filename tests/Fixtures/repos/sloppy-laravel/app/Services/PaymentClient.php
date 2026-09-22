<?php

namespace App\Services;

class PaymentClient
{
    private string $apiKey = 'Qm7xLp2ZtR9vHs4KwN8bYc3JfD6gTe1A';

    public function charge(int $amount): bool
    {
        return $amount > 0 && $this->apiKey !== '';
    }
}
