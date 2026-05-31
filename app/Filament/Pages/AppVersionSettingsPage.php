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

    public array $data = [];

    public function mount(): void
    {
        $customer = AppVersionSetting::forPlatform('customer');
        $driver   = AppVersionSetting::forPlatform('driver');

        $this->form->fill([
            'customer_min_version'    => $customer->min_version,
            'customer_latest_version' => $customer->latest_version,
            'customer_android_url'    => $customer->android_url,
            'customer_ios_url'        => $customer->ios_url,
            'customer_force_update'   => $customer->force_update,
            'customer_force_message'  => $customer->force_message,

            'driver_min_version'    => $driver->min_version,
            'driver_latest_version' => $driver->latest_version,
            'driver_android_url'    => $driver->android_url,
            'driver_ios_url'        => $driver->ios_url,
            'driver_force_update'   => $driver->force_update,
            'driver_force_message'  => $driver->force_message,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([

            Section::make('App Khách hàng')
                ->icon('heroicon-o-user')
                ->schema([
                    TextInput::make('customer_min_version')
                        ->label('Phiên bản tối thiểu')
                        ->placeholder('1.0.0')->required(),
                    TextInput::make('customer_latest_version')
                        ->label('Phiên bản mới nhất')
                        ->placeholder('1.0.1')->required(),
                    TextInput::make('customer_android_url')
                        ->label('Google Play URL')->url()->columnSpan(2),
                    TextInput::make('customer_ios_url')
                        ->label('App Store URL')->url()->columnSpan(2),
                    Toggle::make('customer_force_update')
                        ->label('Bật force update')
                        ->helperText('User dùng app < min_version sẽ bị bắt buộc cập nhật.')
                        ->columnSpan(2),
                    Textarea::make('customer_force_message')
                        ->label('Nội dung thông báo')
                        ->rows(2)->columnSpan(2),
                ])->columns(2),

            Section::make('App Tài xế')
                ->icon('heroicon-o-truck')
                ->schema([
                    TextInput::make('driver_min_version')
                        ->label('Phiên bản tối thiểu')
                        ->placeholder('1.0.0')->required(),
                    TextInput::make('driver_latest_version')
                        ->label('Phiên bản mới nhất')
                        ->placeholder('1.0.1')->required(),
                    TextInput::make('driver_android_url')
                        ->label('Google Play URL')->url()->columnSpan(2),
                    TextInput::make('driver_ios_url')
                        ->label('App Store URL')->url()->columnSpan(2),
                    Toggle::make('driver_force_update')
                        ->label('Bật force update')
                        ->helperText('Tài xế dùng app < min_version sẽ bị bắt buộc cập nhật.')
                        ->columnSpan(2),
                    Textarea::make('driver_force_message')
                        ->label('Nội dung thông báo')
                        ->rows(2)->columnSpan(2),
                ])->columns(2),

        ])->statePath('data');
    }

    public function save(): void
    {
        $values = $this->form->getState();

        AppVersionSetting::forPlatform('customer')->update([
            'min_version'    => $values['customer_min_version'],
            'latest_version' => $values['customer_latest_version'],
            'android_url'    => $values['customer_android_url'] ?: null,
            'ios_url'        => $values['customer_ios_url'] ?: null,
            'force_update'   => $values['customer_force_update'],
            'force_message'  => $values['customer_force_message'],
        ]);

        AppVersionSetting::forPlatform('driver')->update([
            'min_version'    => $values['driver_min_version'],
            'latest_version' => $values['driver_latest_version'],
            'android_url'    => $values['driver_android_url'] ?: null,
            'ios_url'        => $values['driver_ios_url'] ?: null,
            'force_update'   => $values['driver_force_update'],
            'force_message'  => $values['driver_force_message'],
        ]);

        Notification::make()
            ->title('Đã lưu cài đặt phiên bản')
            ->success()
            ->send();
    }
}
