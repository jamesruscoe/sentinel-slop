<?php

namespace Tests\Unit\Services;

use App\Services\PaymentService;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    public function test_limit_defaults_to_ten(): void
    {
        $this->assertSame(10, app(PaymentService::class)->limit());
    }
}
