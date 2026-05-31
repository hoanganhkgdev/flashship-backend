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

    public ?string $min_version    = null;
    public ?string $latest_version = null;
    public ?string $android_url    = null;
    public ?string $ios_url        = null;
    public bool    $force_update   = false;
    public ?string $force_message  = null;

    public function mount(): void
    {
        $s = AppVersionSetting::current();
        $this->min_version    = $s->min_version;
        $this->latest_version = $s->latest_version;
        $this->android_url    = $s->android_url;
        $this->ios_url        = $s->ios_url;
        $this->force_update   = $s->force_update;
        $this->force_message  = $s->force_message;
        $this->form->fill([
            'min_version'    => $s->min_version,
            'latest_version' => $s->latest_version,
            'android_url'    => $s->android_url,
            'ios_url'        => $s->ios_url,
            'force_update'   => $s->force_update,
            'force_message'  => $s->force_message,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([

            Section::make('Phiên bản')
                ->description('Quản lý phiên bản tối thiểu và mới nhất của app.')
                ->schema([
                    TextInput::make('min_version')
                        ->label('Phiên bản tối thiểu')
                        ->placeholder('1.0.0')
                        ->helperText('App thấp hơn version này sẽ bị bắt buộc cập nhật.')
                        ->required(),
                    TextInput::make('latest_version')
                        ->label('Phiên bản mới nhất')
                        ->placeholder('1.0.1')
                        ->required(),
                ])->columns(2),

            Section::make('Store URL')
                ->schema([
                    TextInput::make('android_url')
                        ->label('Google Play URL')
                        ->placeholder('https://play.google.com/store/apps/details?id=...')
                        ->url(),
                    TextInput::make('ios_url')
                        ->label('App Store URL')
                        ->placeholder('https://apps.apple.com/app/...')
                        ->url(),
                ])->columns(2),

            Section::make('Force Update')
                ->schema([
                    Toggle::make('force_update')
                        ->label('Bật force update')
                        ->helperText('Khi bật, user dùng app < min_version sẽ thấy dialog bắt buộc cập nhật.')
                        ->live(),
                    Textarea::make('force_message')
                        ->label('Nội dung thông báo')
                        ->placeholder('Vui lòng cập nhật ứng dụng để tiếp tục sử dụng.')
                        ->rows(3),
                ]),

        ])->statePath('data');
    }

    public array $data = [];

    public function save(): void
    {
        $values = $this->form->getState();
        $s      = AppVersionSetting::current();
        $s->update($values);

        Notification::make()
            ->title('Đã lưu cài đặt phiên bản')
            ->success()
            ->send();
    }
}
