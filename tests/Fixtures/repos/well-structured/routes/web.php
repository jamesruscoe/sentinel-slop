<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\DogController;
use Illuminate\Support\Facades\Route;

Route::resource('dogs', DogController::class);
Route::resource('bookings', BookingController::class);
