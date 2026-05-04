<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Broadcasting auth endpoint dưới prefix /api để các mobile app dùng được
        Broadcast::routes(['middleware' => ['auth:sanctum'], 'prefix' => 'api']);
    }
}
