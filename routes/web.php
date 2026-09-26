<?php

use App\Http\Controllers\Auth\IdTokenController;
use App\Http\Controllers\Billing\WelcomeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PricingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Add the "subscribed" middleware to the routes a plan pays for...
    Route::get('/', HomeController::class)->name('home');

    // Shopify App Pricing: the page that opens Shopify's plan selection, and
    // the welcome link Shopify sends merchants back to once they have chosen.
    Route::get('/pricing', PricingController::class)->name('pricing');
    Route::get('/billing/welcome', WelcomeController::class)->name('billing.welcome');
});

Route::get('/auth/id-token', IdTokenController::class)->name('auth.id-token');
