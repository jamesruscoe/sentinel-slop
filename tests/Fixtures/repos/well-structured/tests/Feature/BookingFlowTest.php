<?php

namespace Tests\Feature;

use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    public function test_a_booking_can_be_created(): void
    {
        $this->post('/bookings', ['name' => 'Rex'])->assertRedirect();
    }
}
