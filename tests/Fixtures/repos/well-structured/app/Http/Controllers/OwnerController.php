<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOwnerRequest;
use App\Services\OwnerService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OwnerController extends Controller
{
    public function __construct(private readonly OwnerService $service) {}

    public function index(): Response
    {
        return Inertia::render('Owners/Index', ['limit' => $this->service->limit()]);
    }

    public function store(StoreOwnerRequest $request): RedirectResponse
    {
        $model = $this->service->create($request->validated());

        return redirect()->route('Owners.show', $model)->with('success', 'Owner created.');
    }
}
