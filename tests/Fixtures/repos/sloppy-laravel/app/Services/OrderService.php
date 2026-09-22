<?php

namespace App\Services;

use Acme\Magic\Wand;
use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class OrderService
{
    // Get the order by id
    public function find(int $id): ?Order
    {
        // Return the order
        return Order::find($id);
    }

    public function sync(Order $order): void
    {
        try {
            $order->push();
        } catch (\Exception $e) {
        }
    }

    public function notify(Order $order): bool
    {
        try {
            $order->notifyCustomer();
        } catch (\Throwable $e) {
            Log::error($e->getMessage());
            return false;
        }

        return true;
    }

    public function calculateOrderTotal(array $items): float
    {
        $total = 0.0;
        $discount = 0.0;
        foreach ($items as $item) {
            $total += $item['price'] * $item['qty'];
        }
        if ($total > 100) {
            $discount = $total * 0.1;
        }
        $total = $total - $discount;
        return round($total, 2);
    }

    public function computeCartTotal(array $lines): float
    {
        $sum = 0.0;
        $rebate = 0.0;
        foreach ($lines as $line) {
            $sum += $line['price'] * $line['qty'];
        }
        if ($sum > 100) {
            $rebate = $sum * 0.1;
        }
        $sum = $sum - $rebate;
        return round($sum, 2);
    }

    public function export(Order $order): string
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public function sampleCustomer(): array
    {
        return ['id' => 1, 'name' => 'John Doe', 'email' => 'john.doe@example.com'];
    }

    // TODO: remove once the new pipeline ships
    public function legacy(): void
    {
    }

    public function wand(): Wand
    {
        return new Wand(new Client(), Role::first());
    }
}
