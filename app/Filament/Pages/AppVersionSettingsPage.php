<?php

namespace App\Filament\Pages;

use App\Models\AppVersionSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppVersionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-device-phone-mobile';
    protected static ?string $navigationGroup = 'Cài đặt';
    protected static ?string $navigationLabel = 'Phiên bản App';
    protected static ?string $title           = 'Cài đặt phiên bản App';
    protected static ?int    $navigationSort  = 90;

    protected static string $view = 'filament.pages.app-version-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->user_type !== 'city_manager';
    }

    public array $data = [];

    public function mount(): void
    {
        $customer = AppVersionSetting::forPlatform('customer');
        $driver   = AppVersionSetting::forPlatform('driver');
        $shop     = AppVersionSetting::forPlatform('shop');

        $this->form->fill([
            // Customer
            'customer_android_min_version'    => $customer->android_min_version,
            'customer_android_latest_version' => $customer->android_latest_version,
            'customer_ios_min_version'        => $customer->ios_min_version,
            'customer_ios_latest_version'     => $customer->ios_latest_version,
            'customer_android_url'            => $customer->android_url,
            'customer_ios_url'                => $customer->ios_url,
            'customer_force_update'           => $customer->force_update,
            'customer_force_message'          => $customer->force_message,

            // Driver
            'driver_android_min_version'    => $driver->android_min_version,
            'driver_android_latest_version' => $driver->android_latest_version,
            'driver_ios_min_version'        => $driver->ios_min_version,
            'driver_ios_latest_version'     => $driver->ios_latest_version,
            'driver_android_url'            => $driver->android_url,
            'driver_ios_url'                => $driver->ios_url,
            'driver_force_update'           => $driver->force_update,
            'driver_force_message'          => $driver->force_message,

            // Shop
            'shop_android_min_version'    => $shop->android_min_version,
            'shop_android_latest_version' => $shop->android_latest_version,
            'shop_ios_min_version'        => $shop->ios_min_version,
            'shop_ios_latest_version'     => $shop->ios_latest_version,
            'shop_android_url'            => $shop->android_url,
            'shop_ios_url'                => $shop->ios_url,
            'shop_force_update'           => $shop->force_update,
            'shop_force_message'          => $shop->force_message,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([

            Section::make('App Khách hàng')
                ->icon('heroicon-o-user')
                ->schema([
                    Section::make('Android')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->schema([
                            TextInput::make('customer_android_min_version')
                                ->label('Phiên bản tối thiểu (force update)')
                                ->placeholder('1.0.0'),
                            TextInput::make('customer_android_latest_version')
                                ->label('Phiên bản mới nhất (soft update)')
                                ->placeholder('1.0.1'),
                            TextInput::make('customer_android_url')
                                ->label('Google Play URL')->url()->columnSpan(2),
                        ])->columns(2),

                    Section::make('iOS')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->schema([
                            TextInput::make('customer_ios_min_version')
                                ->label('Phiên bản tối thiểu (force update)')
                                ->placeholder('1.0.0'),
                            TextInput::make('customer_ios_latest_version')
                                ->label('Phiên bản mới nhất (soft update)')
                                ->placeholder('1.0.1'),
                            TextInput::make('customer_ios_url')
                                ->label('App Store URL')->url()->columnSpan(2),
                        ])->columns(2),

                    Toggle::make('customer_force_update')
                        ->label('Bật force update')
                        ->helperText('User dùng app < phiên bản tối thiểu sẽ bị bắt buộc cập nhật.'),
                    Textarea::make('customer_force_message')
                        ->label('Nội dung thông báo')->rows(2),
                ]),

            Section::make('App Tài xế')
                ->icon('heroicon-o-truck')
                ->schema([
                    Section::make('Android')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->schema([
                            TextInput::make('driver_android_min_version')
                                ->label('Phiên bản tối thiểu (force update)')
                                ->placeholder('1.0.0'),
                            TextInput::make('driver_android_latest_version')
                                ->label('Phiên bản mới nhất (soft update)')
                                ->placeholder('1.0.1'),
                            TextInput::make('driver_android_url')
                                ->label('Google Play URL')->url()->columnSpan(2),
                        ])->columns(2),

                    Section::make('iOS')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->schema([
                            TextInput::make('driver_ios_min_version')
                                ->label('Phiên bản tối thiểu (force update)')
                                ->placeholder('1.0.0'),
                            TextInput::make('driver_ios_latest_version')
                                ->label('Phiên bản mới nhất (soft update)')
                                ->placeholder('1.0.1'),
                            TextInput::make('driver_ios_url')
                                ->label('App Store URL')->url()->columnSpan(2),
                        ])->columns(2),

                    Toggle::make('driver_force_update')
                        ->label('Bật force update')
                        ->helperText('Tài xế dùng app < phiên bản tối thiểu sẽ bị bắt buộc cập nhật.'),
                    Textarea::make('driver_force_message')
                        ->label('Nội dung thông báo')->rows(2),
                ]),

            Section::make('App Shop')
                ->icon('heroicon-o-building-storefront')
                ->schema([
                    Section::make('Android')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->schema([
                            TextInput::make('shop_android_min_version')
                                ->label('Phiên bản tối thiểu (force update)')
                                ->placeholder('1.0.0'),
                            TextInput::make('shop_android_latest_version')
                                ->label('Phiên bản mới nhất (soft update)')
                                ->placeholder('1.0.1'),
                            TextInput::make('shop_android_url')
                                ->label('Google Play URL')->url()->columnSpan(2),
                        ])->columns(2),

                    Section::make('iOS')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->schema([
                            TextInput::make('shop_ios_min_version')
                                ->label('Phiên bản tối thiểu (force update)')
                                ->placeholder('1.0.0'),
                            TextInput::make('shop_ios_latest_version')
                                ->label('Phiên bản mới nhất (soft update)')
                                ->placeholder('1.0.1'),
                            TextInput::make('shop_ios_url')
                                ->label('App Store URL')->url()->columnSpan(2),
                        ])->columns(2),

                    Toggle::make('shop_force_update')
                        ->label('Bật force update')
                        ->helperText('Shop dùng app < phiên bản tối thiểu sẽ bị bắt buộc cập nhật.'),
                    Textarea::make('shop_force_message')
                        ->label('Nội dung thông báo')->rows(2),
                ]),

        ])->statePath('data');
    }

    public function save(): void
    {
        $values = $this->form->getState();

        AppVersionSetting::forPlatform('customer')->update([
            'android_min_version'    => $values['customer_android_min_version'] ?: null,
            'android_latest_version' => $values['customer_android_latest_version'] ?: null,
            'ios_min_version'        => $values['customer_ios_min_version'] ?: null,
            'ios_latest_version'     => $values['customer_ios_latest_version'] ?: null,
            'android_url'            => $values['customer_android_url'] ?: null,
            'ios_url'                => $values['customer_ios_url'] ?: null,
            'force_update'           => $values['customer_force_update'],
            'force_message'          => $values['customer_force_message'],
        ]);

        AppVersionSetting::forPlatform('driver')->update([
            'android_min_version'    => $values['driver_android_min_version'] ?: null,
            'android_latest_version' => $values['driver_android_latest_version'] ?: null,
            'ios_min_version'        => $values['driver_ios_min_version'] ?: null,
            'ios_latest_version'     => $values['driver_ios_latest_version'] ?: null,
            'android_url'            => $values['driver_android_url'] ?: null,
            'ios_url'                => $values['driver_ios_url'] ?: null,
            'force_update'           => $values['driver_force_update'],
            'force_message'          => $values['driver_force_message'],
        ]);

        AppVersionSetting::forPlatform('shop')->update([
            'android_min_version'    => $values['shop_android_min_version'] ?: null,
            'android_latest_version' => $values['shop_android_latest_version'] ?: null,
            'ios_min_version'        => $values['shop_ios_min_version'] ?: null,
            'ios_latest_version'     => $values['shop_ios_latest_version'] ?: null,
            'android_url'            => $values['shop_android_url'] ?: null,
            'ios_url'                => $values['shop_ios_url'] ?: null,
            'force_update'           => $values['shop_force_update'],
            'force_message'          => $values['shop_force_message'],
        ]);

        Notification::make()
            ->title('Đã lưu cài đặt phiên bản')
            ->success()
            ->send();
    }
}
