<?php

use Illuminate\Support\Facades\Route;
use Modules\Driver\Http\Controllers\DriverController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('drivers', DriverController::class)->names('driver');
});
