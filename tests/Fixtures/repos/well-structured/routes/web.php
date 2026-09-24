<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\CapacityController;
use App\Http\Controllers\DogController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::resource('dogs', DogController::class);
Route::resource('bookings', BookingController::class);
Route::resource('owners', OwnerController::class);
Route::resource('capacity', CapacityController::class)->only(['index', 'store']);
Route::resource('payments', PaymentController::class)->only(['index', 'store']);
