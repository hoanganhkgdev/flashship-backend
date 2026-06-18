<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Core\Models\User;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('FlashShip Admin')
            ->font('Roboto', provider: GoogleFontProvider::class)
            ->colors([
                'primary' => Color::Orange,
            ])
            ->navigationGroups([
                NavigationGroup::make('Tổng quan'),
                NavigationGroup::make('Đơn hàng'),
                NavigationGroup::make('Người dùng'),
                NavigationGroup::make('Quản lý ví'),
                NavigationGroup::make('Công nợ'),
                NavigationGroup::make('Marketing'),
                NavigationGroup::make('Cấu hình'),
                NavigationGroup::make('Cài đặt'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\StatsOverviewWidget::class,
                \App\Filament\Widgets\OnlineDriversWidget::class,
                \App\Filament\Widgets\OrdersByHourWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                'panels::head.end',
                fn () => new \Illuminate\Support\HtmlString(
                    '<script src="https://maps.googleapis.com/maps/api/js?key=' . config('services.google_maps.api_key') . '&libraries=places"></script>'
                    . '<script src="https://unpkg.com/pusher-js@8.4.0/dist/web/pusher.min.js"></script>'
                    . '<script src="https://unpkg.com/laravel-echo@1.16.1/dist/echo.iife.js"></script>'
                    . '<script>
                        try {
                            window.Echo = new Echo({
                                broadcaster: "pusher",
                                key: "' . env('REVERB_APP_KEY') . '",
                                cluster: "mt1",
                                wsHost: "' . env('REVERB_HOST', 'localhost') . '",
                                wsPort: ' . (int) env('REVERB_PORT', 8080) . ',
                                wssPort: ' . (int) env('REVERB_PORT', 8080) . ',
                                forceTLS: ' . (env('REVERB_SCHEME', 'http') === 'https' ? 'true' : 'false') . ',
                                disableStats: true,
                                enabledTransports: ["ws", "wss"],
                            });
                        } catch(e) { console.warn("Echo init failed:", e); }
                    </script>'
                )
            );
    }
}
