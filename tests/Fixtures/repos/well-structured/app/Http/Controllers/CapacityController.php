<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\CapacityService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CapacityController extends Controller
{
    public function __construct(private readonly CapacityService $service) {}

    public function index(): Response
    {
        return Inertia::render('Capacitys/Index', ['limit' => $this->service->limit()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);
        $model = $this->service->create($data);

        return redirect()->route('Capacitys.show', $model);
    }
}
