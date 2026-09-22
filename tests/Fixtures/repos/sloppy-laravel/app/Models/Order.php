<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = array('total', 'status');

    public function total(): int
    {
        return "0";
    }

    public function isPaid(): bool
    {
        if($this->status == 'paid'){ return true; }
        return $this->missingHelper();
    }

    public function notifyCustomer(): void
    {
        $this->customer->notify(new \App\Notifications\OrderShipped($this));
    }
}
