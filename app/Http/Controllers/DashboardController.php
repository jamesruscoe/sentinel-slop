<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $repositories = $request->user()
            ->repositories()
            ->whereNull('repositories.removed_at')
            ->with(['installation', 'latestScan', 'latestCompletedScan'])
            ->orderBy('repositories.full_name')
            ->get();

        return view('dashboard', [
            'repositories' => $repositories,
            'models' => (array) config('sentinel.synthesis.models', []),
        ]);
    }
}
