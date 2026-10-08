<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyAccountPage;
use App\Filament\Widgets\OnlineDriversWidget;
use App\Filament\Widgets\OpsAlertsWidget;
use App\Filament\Widgets\OrdersByHourWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Core\Models\City;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Menu người dùng có mục "Tài khoản của tôi" (trang riêng trong khu vực đang chọn) để tự đổi tên, email, mật khẩu.
            ->userMenuItems([
                MenuItem::make()
                    ->label('Tài khoản của tôi')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (): string => MyAccountPage::getUrl()),
            ])
            // Chuông thông báo trong panel — nơi đơn không có tài xế báo cho admin/tổng đài.
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')
            ->tenant(City::class)
            ->sidebarCollapsibleOnDesktop()
            ->defaultThemeMode(ThemeMode::Light)
            ->brandName('FlashShip Admin')
            ->font('Inter', url: asset('css/inter.css').'?v='.filemtime(public_path('css/inter.css')), provider: LocalFontProvider::class)
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString(
                    '<link rel="stylesheet" href="'.asset('css/admin-theme.css').'?v='.filemtime(public_path('css/admin-theme.css')).'">',
                ),
            )
            ->colors([
                'primary' => Color::Orange,
            ])
            ->navigationGroups([
                NavigationGroup::make('Tổng quan'),
                NavigationGroup::make('Vận hành đơn hàng'),
                NavigationGroup::make('Báo cáo'),
                NavigationGroup::make('Khách hàng'),
                NavigationGroup::make('Cửa hàng'),
                NavigationGroup::make('Tài xế'),
                NavigationGroup::make('Giá & khu vực')->collapsed(),
                NavigationGroup::make('Hệ thống')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                OpsAlertsWidget::class,
                OnlineDriversWidget::class,
                OrdersByHourWidget::class,
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
                fn () => new HtmlString(
                    '<script src="https://maps.googleapis.com/maps/api/js?key='.config('services.google_maps.api_key').'&libraries=places"></script>'
                    .'<script src="https://unpkg.com/pusher-js@8.4.0/dist/web/pusher.min.js"></script>'
                    .'<script src="https://unpkg.com/laravel-echo@1.16.1/dist/echo.iife.js"></script>'
                    .'<script>
                        try {
                            window.Echo = new Echo({
                                broadcaster: "pusher",
                                key: "'.env('REVERB_APP_KEY').'",
                                cluster: "mt1",
                                wsHost: "'.env('REVERB_HOST', 'localhost').'",
                                wsPort: '.(int) env('REVERB_PORT', 8080).',
                                wssPort: '.(int) env('REVERB_PORT', 8080).',
                                forceTLS: '.(env('REVERB_SCHEME', 'http') === 'https' ? 'true' : 'false').',
                                disableStats: true,
                                enabledTransports: ["ws", "wss"],
                            });
                        } catch(e) { console.warn("Echo init failed:", e); }
                    </script>'
                )
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => view('filament.components.topbar-controls')->render(),
            );
    }
}
