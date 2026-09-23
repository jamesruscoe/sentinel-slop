<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDogRequest;
use App\Services\DogService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DogController extends Controller
{
    public function __construct(private readonly DogService $service) {}

    public function index(): Response
    {
        return Inertia::render('Dogs/Index', ['limit' => $this->service->limit()]);
    }

    public function store(StoreDogRequest $request): RedirectResponse
    {
        $model = $this->service->create($request->validated());

        return redirect()->route('Dogs.show', $model)->with('success', 'Dog created.');
    }
}
