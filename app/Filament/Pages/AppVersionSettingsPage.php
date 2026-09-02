<?php

namespace App\Filament\Pages;

use App\Models\AppVersionSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppVersionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Phiên bản App';

    protected static ?string $title = 'Cài đặt phiên bản App';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.app-version-settings';

    public function getSubheading(): ?string
    {
        return 'Quản lý phiên bản tối thiểu, liên kết tải và chính sách bắt buộc cập nhật cho từng ứng dụng.';
    }

    public static function canAccess(): bool
    {
        return ! in_array(auth()->user()?->user_type, ['city_manager', 'call_center']);
    }

    public array $data = [];

    public function mount(): void
    {
        $customer = AppVersionSetting::forPlatform('customer');
        $driver = AppVersionSetting::forPlatform('driver');
        $shop = AppVersionSetting::forPlatform('shop');

        $this->form->fill([
            // Customer
            'customer_min_version' => $customer->min_version ?? $customer->android_min_version,
            'customer_android_url' => $customer->android_url,
            'customer_ios_url' => $customer->ios_url,
            'customer_force_update' => $customer->force_update,
            'customer_force_message' => $customer->force_message,

            // Driver
            'driver_min_version' => $driver->min_version ?? $driver->android_min_version,
            'driver_android_url' => $driver->android_url,
            'driver_ios_url' => $driver->ios_url,
            'driver_force_update' => $driver->force_update,
            'driver_force_message' => $driver->force_message,

            // Shop
            'shop_min_version' => $shop->min_version ?? $shop->android_min_version,
            'shop_android_url' => $shop->android_url,
            'shop_ios_url' => $shop->ios_url,
            'shop_force_update' => $shop->force_update,
            'shop_force_message' => $shop->force_message,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([

            Section::make('App Khách hàng')
                ->description('Cấu hình phiên bản cho ứng dụng đặt dịch vụ của khách hàng')
                ->icon('heroicon-o-user')
                ->schema([
                    TextInput::make('customer_min_version')
                        ->label('Phiên bản tối thiểu (iOS & Android)')
                        ->placeholder('3.0.2')
                        ->helperText('Áp dụng cho cả Android và iOS — dùng app thấp hơn sẽ bị bắt cập nhật.'),
                    Toggle::make('customer_force_update')
                        ->label('Bật force update'),
                    TextInput::make('customer_android_url')
                        ->label('Google Play URL')->url(),
                    TextInput::make('customer_ios_url')
                        ->label('App Store URL')->url(),
                    Textarea::make('customer_force_message')
                        ->label('Nội dung thông báo')->rows(2)->columnSpan(2),
                ])->columns(2),

            Section::make('App Tài xế')
                ->description('Cấu hình phiên bản cho ứng dụng nhận và giao đơn của tài xế')
                ->icon('heroicon-o-truck')
                ->schema([
                    TextInput::make('driver_min_version')
                        ->label('Phiên bản tối thiểu (iOS & Android)')
                        ->placeholder('1.0.0')
                        ->helperText('Áp dụng cho cả Android và iOS — dùng app thấp hơn sẽ bị bắt cập nhật.'),
                    Toggle::make('driver_force_update')
                        ->label('Bật force update'),
                    TextInput::make('driver_android_url')
                        ->label('Google Play URL')->url(),
                    TextInput::make('driver_ios_url')
                        ->label('App Store URL')->url(),
                    Textarea::make('driver_force_message')
                        ->label('Nội dung thông báo')->rows(2)->columnSpan(2),
                ])->columns(2),

            Section::make('App Shop')
                ->description('Cấu hình phiên bản cho ứng dụng tạo đơn của cửa hàng')
                ->icon('heroicon-o-building-storefront')
                ->schema([
                    TextInput::make('shop_min_version')
                        ->label('Phiên bản tối thiểu (iOS & Android)')
                        ->placeholder('1.0.0')
                        ->helperText('Áp dụng cho cả Android và iOS — dùng app thấp hơn sẽ bị bắt cập nhật.'),
                    Toggle::make('shop_force_update')
                        ->label('Bật force update'),
                    TextInput::make('shop_android_url')
                        ->label('Google Play URL')->url(),
                    TextInput::make('shop_ios_url')
                        ->label('App Store URL')->url(),
                    Textarea::make('shop_force_message')
                        ->label('Nội dung thông báo')->rows(2)->columnSpan(2),
                ])->columns(2),

        ])->statePath('data');
    }

    public function save(): void
    {
        $values = $this->form->getState();

        $customerMin = $values['customer_min_version'] ?: null;
        AppVersionSetting::forPlatform('customer')->update([
            'min_version' => $customerMin,
            'android_min_version' => $customerMin,
            'ios_min_version' => $customerMin,
            'android_url' => $values['customer_android_url'] ?: null,
            'ios_url' => $values['customer_ios_url'] ?: null,
            'force_update' => $values['customer_force_update'],
            'force_message' => $values['customer_force_message'],
        ]);

        $driverMin = $values['driver_min_version'] ?: null;
        AppVersionSetting::forPlatform('driver')->update([
            'min_version' => $driverMin,
            'android_min_version' => $driverMin,
            'ios_min_version' => $driverMin,
            'android_url' => $values['driver_android_url'] ?: null,
            'ios_url' => $values['driver_ios_url'] ?: null,
            'force_update' => $values['driver_force_update'],
            'force_message' => $values['driver_force_message'],
        ]);

        $shopMin = $values['shop_min_version'] ?: null;
        AppVersionSetting::forPlatform('shop')->update([
            'min_version' => $shopMin,
            'android_min_version' => $shopMin,
            'ios_min_version' => $shopMin,
            'android_url' => $values['shop_android_url'] ?: null,
            'ios_url' => $values['shop_ios_url'] ?: null,
            'force_update' => $values['shop_force_update'],
            'force_message' => $values['shop_force_message'],
        ]);

        Notification::make()
            ->title('Đã lưu cài đặt phiên bản')
            ->success()
            ->send();
    }
}
