<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'driver.active' => \App\Http\Middleware\EnsureDriverAccountActive::class,
            'user_type'     => \App\Http\Middleware\EnsureUserType::class,
        ]);

        // Trước đây toàn bộ API không throttle theo request — bật lại giới
        // hạn mặc định của Laravel (limiter 'api' đăng ký ở AppServiceProvider).
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
