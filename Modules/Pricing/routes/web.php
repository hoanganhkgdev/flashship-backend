<?php

use Illuminate\Support\Facades\Route;
use Modules\Pricing\Http\Controllers\PricingController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('pricings', PricingController::class)->names('pricing');
});
