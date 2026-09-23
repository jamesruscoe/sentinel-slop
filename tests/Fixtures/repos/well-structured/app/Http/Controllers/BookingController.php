<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $service) {}

    public function index(): Response
    {
        return Inertia::render('Bookings/Index', ['limit' => $this->service->limit()]);
    }

    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $model = $this->service->create($request->validated());

        return redirect()->route('Bookings.show', $model)->with('success', 'Booking created.');
    }
}
