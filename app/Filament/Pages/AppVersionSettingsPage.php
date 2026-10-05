<?php

namespace App\Filament\Pages;

use App\Models\AppVersionSetting;
use App\Services\AppVersionPolicy;
use App\Services\CatalogService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppVersionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Phiên bản app';

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
            'customer_android_latest_version' => $customer->android_latest_version ?? $customer->latest_version,
            'customer_ios_latest_version' => $customer->ios_latest_version ?? $customer->latest_version,
            'customer_android_url' => $customer->android_url,
            'customer_ios_url' => $customer->ios_url,
            'customer_force_update' => $customer->force_update,
            'customer_force_message' => $customer->force_message,

            // Driver
            'driver_min_version' => $driver->min_version ?? $driver->android_min_version,
            'driver_android_latest_version' => $driver->android_latest_version ?? $driver->latest_version,
            'driver_ios_latest_version' => $driver->ios_latest_version ?? $driver->latest_version,
            'driver_android_url' => $driver->android_url,
            'driver_ios_url' => $driver->ios_url,
            'driver_force_update' => $driver->force_update,
            'driver_force_message' => $driver->force_message,

            // Shop
            'shop_min_version' => $shop->min_version ?? $shop->android_min_version,
            'shop_android_latest_version' => $shop->android_latest_version ?? $shop->latest_version,
            'shop_ios_latest_version' => $shop->ios_latest_version ?? $shop->latest_version,
            'shop_android_url' => $shop->android_url,
            'shop_ios_url' => $shop->ios_url,
            'shop_force_update' => $shop->force_update,
            'shop_force_message' => $shop->force_message,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Ứng dụng')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Khách hàng')
                        ->icon('heroicon-o-user')
                        ->schema($this->versionFields('customer')),
                    Forms\Components\Tabs\Tab::make('Tài xế')
                        ->icon('heroicon-o-truck')
                        ->schema($this->versionFields('driver')),
                    Forms\Components\Tabs\Tab::make('Cửa hàng')
                        ->icon('heroicon-o-building-storefront')
                        ->schema($this->versionFields('shop')),
                ])
                ->persistTabInQueryString(),
        ])->statePath('data');
    }

    private function versionFields(string $prefix): array
    {
        return [
            Section::make('Chính sách phiên bản')
                ->description('Phiên bản mới nhất dùng để nhắc cập nhật; phiên bản tối thiểu dùng để chặn các bản quá cũ.')
                ->columns(2)
                ->schema([
                    TextInput::make("{$prefix}_android_latest_version")
                        ->label('Bản mới nhất Android')
                        ->placeholder('1.2.0')
                        ->regex('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/')
                        ->helperText('Ví dụ: 1.2.0 hoặc 1.2.0+15'),
                    TextInput::make("{$prefix}_ios_latest_version")
                        ->label('Bản mới nhất iOS')
                        ->placeholder('1.2.0')
                        ->regex('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/'),
                    TextInput::make("{$prefix}_min_version")
                        ->label('Phiên bản tối thiểu')
                        ->placeholder('1.0.0')
                        ->regex('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/')
                        ->required(fn (Forms\Get $get) => (bool) $get("{$prefix}_force_update"))
                        ->helperText('Áp dụng cho cả Android và iOS; có thể dùng dãy phiên bản khác với phiên bản phát hành.'),
                    Toggle::make("{$prefix}_force_update")
                        ->label('Bắt buộc cập nhật')
                        ->live()
                        ->helperText('Chỉ bật sau khi phiên bản mới đã có trên cửa hàng ứng dụng.')
                        ->columnSpanFull(),
                ]),
            Section::make('Liên kết tải ứng dụng')
                ->columns(2)
                ->schema([
                    TextInput::make("{$prefix}_android_url")->label('Google Play URL')->url(),
                    TextInput::make("{$prefix}_ios_url")->label('App Store URL')->url(),
                    Textarea::make("{$prefix}_force_message")
                        ->label('Nội dung thông báo cập nhật')
                        ->rows(3)
                        ->maxLength(500)
                        ->required(fn (Forms\Get $get) => (bool) $get("{$prefix}_force_update"))
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [$this->saveAction()];
    }

    /** Xác nhận nêu rõ app nào sẽ bị bắt cập nhật và phiên bản tối thiểu. */
    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Lưu cài đặt')
            ->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalHeading('Lưu chính sách phiên bản?')
            ->modalDescription(function (): string {
                $forced = collect(AppVersionPolicy::PLATFORMS)
                    ->filter(fn ($label, $p) => ! empty($this->data["{$p}_force_update"]))
                    ->map(fn ($label, $p) => $label.' (tối thiểu '.($this->data["{$p}_min_version"] ?: 'chưa đặt').')')
                    ->implode(', ');

                return $forced
                    ? 'Bắt buộc cập nhật sẽ chặn người dùng chạy bản thấp hơn mức tối thiểu của: '.$forced.'. Chỉ lưu khi bản đó đã có trên Google Play và App Store.'
                    : 'Không app nào bị bắt buộc cập nhật.';
            })
            ->action(fn () => $this->save());
    }

    public function save(): void
    {
        $values = $this->form->getState();

        // Tối thiểu không được cao hơn bản mới nhất trên cửa hàng.
        foreach (array_keys(AppVersionPolicy::PLATFORMS) as $platform) {
            $errors = AppVersionPolicy::conflicts(
                $values["{$platform}_min_version"] ?: null,
                $values["{$platform}_android_latest_version"] ?: null,
                $values["{$platform}_ios_latest_version"] ?: null,
            );
            if ($errors) {
                throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ["data.{$platform}_{$k}_version" => $m])->all());
            }
        }

        $before = collect(array_keys(AppVersionPolicy::PLATFORMS))->mapWithKeys(fn ($p) => [$p => AppVersionSetting::forPlatform($p)->only([
            'min_version', 'android_latest_version', 'ios_latest_version', 'android_url', 'ios_url', 'force_update', 'force_message',
        ])])->all();

        foreach (['customer', 'driver', 'shop'] as $platform) {
            if (($values["{$platform}_force_update"] ?? false) && empty($values["{$platform}_android_url"])) {
                throw ValidationException::withMessages([
                    "data.{$platform}_android_url" => 'Cần liên kết Google Play trước khi bật bắt buộc cập nhật.',
                ]);
            }
            if (($values["{$platform}_force_update"] ?? false) && empty($values["{$platform}_ios_url"])) {
                throw ValidationException::withMessages([
                    "data.{$platform}_ios_url" => 'Cần liên kết App Store trước khi bật bắt buộc cập nhật.',
                ]);
            }
        }

        DB::transaction(function () use ($values) {
            foreach (['customer', 'driver', 'shop'] as $platform) {
                $minimum = $values["{$platform}_min_version"] ?: null;
                $androidLatest = $values["{$platform}_android_latest_version"] ?: null;
                $iosLatest = $values["{$platform}_ios_latest_version"] ?: null;
                AppVersionSetting::forPlatform($platform)->update([
                    'min_version' => $minimum,
                    'android_min_version' => $minimum,
                    'ios_min_version' => $minimum,
                    'android_latest_version' => $androidLatest,
                    'ios_latest_version' => $iosLatest,
                    'android_url' => $values["{$platform}_android_url"] ?: null,
                    'ios_url' => $values["{$platform}_ios_url"] ?: null,
                    'latest_version' => AppVersionPolicy::legacyLatest($androidLatest, $iosLatest) ?? AppVersionSetting::forPlatform($platform)->latest_version,
                    'force_update' => $values["{$platform}_force_update"],
                    // Cột không cho null: để trống thì dùng câu mặc định (trước đây lưu khi tắt bắt buộc cập nhật mà để trống nội dung sẽ lỗi 500).
                    'force_message' => $values["{$platform}_force_message"] ?: 'Vui lòng cập nhật ứng dụng để tiếp tục sử dụng.',
                ]);
            }
        });

        $labels = ['min_version' => 'Tối thiểu', 'android_latest_version' => 'Mới nhất Android', 'ios_latest_version' => 'Mới nhất iOS', 'android_url' => 'Link Google Play', 'ios_url' => 'Link App Store', 'force_update' => 'Bắt buộc cập nhật', 'force_message' => 'Nội dung'];
        foreach (AppVersionPolicy::PLATFORMS as $platform => $label) {
            $after = AppVersionSetting::forPlatform($platform)->only(array_keys($labels));
            CatalogService::logChanges('app_version', array_search($platform, array_keys(AppVersionPolicy::PLATFORMS)) + 1, $before[$platform], $after, collect($labels)->map(fn ($l) => $label.' · '.$l)->all(), auth()->id());
        }

        Notification::make()
            ->title('Đã lưu cài đặt phiên bản')
            ->success()
            ->send();
    }

    public function getVersionSummariesProperty(): array
    {
        return collect(['customer' => 'Khách hàng', 'driver' => 'Tài xế', 'shop' => 'Cửa hàng'])
            ->map(function (string $label, string $platform) {
                $setting = AppVersionSetting::forPlatform($platform);

                return [
                    'label' => $label,
                    'minimum' => $setting->min_version ?: 'Chưa đặt',
                    'android_latest' => $setting->android_latest_version ?: ($setting->latest_version ?: 'Chưa đặt'),
                    'ios_latest' => $setting->ios_latest_version ?: ($setting->latest_version ?: 'Chưa đặt'),
                    'forced' => (bool) $setting->force_update,
                    'issues' => AppVersionPolicy::issues($setting),
                    'updated_at' => $setting->updated_at?->format('d/m/Y H:i'),
                ];
            })->values()->all();
    }
}
