<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $service) {}

    public function index(): Response
    {
        return Inertia::render('Payments/Index', ['limit' => $this->service->limit()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);
        $model = $this->service->create($data);

        return redirect()->route('Payments.show', $model);
    }
}
