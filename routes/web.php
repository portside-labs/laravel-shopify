<?php

use App\Http\Controllers\Auth\IdTokenController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');
});

Route::get('/auth/id-token', IdTokenController::class)->name('auth.id-token');
