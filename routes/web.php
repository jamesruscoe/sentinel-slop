<?php

use App\Http\Controllers\Auth\GitHubAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GitHub\GitHubAppController;
use App\Http\Controllers\GitHub\GitHubWebhookController;
use App\Http\Middleware\VerifyGitHubWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('home');

Route::get('/login', fn () => redirect()->route('auth.github'))->name('login');
Route::get('/auth/github', [GitHubAuthController::class, 'redirect'])->name('auth.github');
Route::get('/auth/github/callback', [GitHubAuthController::class, 'callback'])->name('auth.github.callback');
Route::post('/logout', [GitHubAuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/github/install', [GitHubAppController::class, 'install'])->name('github.install');
    Route::get('/github/setup', [GitHubAppController::class, 'setup'])->name('github.setup');
});

Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware(VerifyGitHubWebhookSignature::class)
    ->name('webhooks.github');
