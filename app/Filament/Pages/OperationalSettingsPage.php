<?php

namespace App\Filament\Pages;

use App\Filament\PageWidgets\OperationalHistoryWidget;
use App\Filament\Widgets\OperationalSettingsOverview;
use App\Services\CatalogService;
use App\Support\AdminAccess;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\RawJs;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\City;
use Modules\Core\Services\OperationalSettings;

class OperationalSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::OPERATIONS_MANAGE);
    }

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Cấu hình vận hành';

    protected static ?string $title = 'Cấu hình vận hành';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.operational-settings';

    /** Ô form => key trong bảng settings (trừ night_windows, xử lý riêng). */
    private const FIELDS = [
        'shift_offline_limit_minutes' => 'shift.offline_limit_minutes',
        'shift_offline_penalty_amount' => 'shift.offline_penalty_amount',
        'rain_bonus_amount' => 'order.rain_bonus_amount',
        'completion_radius_meters' => 'order.completion_radius_meters',
        'auto_complete_grace_minutes' => 'order.auto_complete_grace_minutes',
        'max_order_distance_km' => 'order.max_distance_km',
        'rating_window_hours' => 'order.rating_window_hours',
        'delayed_reminder_minutes' => 'order.delayed_reminder_minutes',
        'rain_mode_auto_off_hours' => 'rain_mode.auto_off_hours',
        'debt_enabled' => 'debt.enabled',
        'penalty_debt_overdue_hours' => 'debt.penalty_overdue_hours',
        'low_wallet_balance_threshold' => 'wallet.low_balance_threshold',
        'min_withdraw_amount' => 'wallet.min_withdraw',
        'referral_reward_amount' => 'referral.reward_amount',
        'referral_min_orders' => 'referral.min_orders',
        'shop_referral_points' => 'shop_referral.points',
        'shop_referral_min_orders' => 'shop_referral.min_orders',
        'shop_referral_welcome_amount' => 'shop_referral.welcome_amount',
        'shop_referral_welcome_days' => 'shop_referral.welcome_days',
    ];

    public array $data = [];

    public function mount(): void
    {
        $this->fillFromCity($this->cityId());
    }

    /** Khu vực đang chọn trên thanh trên cùng — mỗi khu vực một bộ cấu hình riêng. */
    public function cityId(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    public function getSubheading(): ?string
    {
        $updatedAt = OperationalSettings::lastUpdatedAt($this->cityId());

        return 'Riêng khu vực '.Filament::getTenant()->name
            .($updatedAt ? ' · cập nhật '.Carbon::parse($updatedAt)->format('H:i d/m/Y') : '');
    }

    protected function getHeaderWidgets(): array
    {
        return [OperationalSettingsOverview::class];
    }

    protected function getFooterWidgets(): array
    {
        return [OperationalHistoryWidget::class];
    }

    /** Nhãn tiếng Việt của từng khóa cấu hình, lấy từ chính nhãn trên form. */
    private function settingLabels(): array
    {
        $labels = [];
        foreach ($this->form->getFlatFields(withHidden: true) as $name => $field) {
            $key = self::FIELDS[$name] ?? null;
            if ($key) {
                $labels[$key] = $field->getLabel();
            }
        }

        return $labels + ['pricing.night_windows' => 'Khung giờ phụ phí đêm'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetDefaults')
                ->label('Khôi phục mặc định')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Điền lại form bằng giá trị mặc định. Chưa lưu cho tới khi bạn bấm "Lưu cấu hình".')
                ->action(function (): void {
                    $this->fillFrom(
                        fn (string $key) => OperationalSettings::DEFAULTS[$key],
                        OperationalSettings::parseNightWindows(OperationalSettings::DEFAULTS['pricing.night_windows']),
                    );
                    Notification::make()->title('Đã điền giá trị mặc định — kiểm tra rồi bấm Lưu')->info()->send();
                }),
            Action::make('copyFromCity')
                ->label('Sao chép từ khu vực khác')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->modalDescription('Điền form bằng cấu hình của khu vực được chọn. Chưa lưu cho tới khi bạn bấm "Lưu cấu hình".')
                ->modalSubmitActionLabel('Điền vào form')
                ->form([
                    Select::make('source_city_id')
                        ->label('Lấy cấu hình từ khu vực')
                        ->options(fn () => City::whereKeyNot($this->cityId())->orderBy('name')->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $source = City::find($data['source_city_id']);
                    $this->fillFromCity((int) $source->id);
                    Notification::make()->title("Đã điền cấu hình của {$source->name} — kiểm tra rồi bấm Lưu")->info()->send();
                }),
            $this->saveAction(),
        ];
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Lưu cấu hình')
            ->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalHeading('Lưu cấu hình vận hành?')
            ->modalDescription(fn () => 'Các lượt phát đơn, chấm điểm và tính phí tiếp theo tại khu vực '.Filament::getTenant()->name.' sẽ dùng giá trị mới. Khu vực khác không đổi.')
            ->modalSubmitActionLabel('Lưu')
            ->action(fn () => $this->save());
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('settings')
                ->persistTabInQueryString()
                ->tabs([
                    $this->orderTab(),
                    $this->shiftTab(),
                    $this->walletTab(),
                ]),
        ])->statePath('data');
    }

    private function shiftTab(): Tab
    {
        return Tab::make('Ca làm việc')
            ->icon('heroicon-o-clock')
            ->schema([
                Section::make('Offline trong ca')
                    ->icon('heroicon-o-power')
                    ->description('Mỗi ca tài xế được Offline tối đa số phút dưới đây (cộng dồn các lần off). Vượt quá thì tự động ghi công nợ phạt, một lần cho mỗi ca mỗi ngày. Đặt số phút = 0 để phạt ngay khi Offline.')
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->schema([
                        $this->integer('shift_offline_limit_minutes', 'Được Offline tối đa', 'phút', 0, 600),
                        $this->money('shift_offline_penalty_amount', 'Tiền phạt khi vượt', 0, 10_000_000),
                    ]),
            ]);
    }

    private function orderTab(): Tab
    {
        return Tab::make('Đơn & phụ phí')
            ->icon('heroicon-o-receipt-percent')
            ->schema([
                Section::make('Hoàn thành & đánh giá')
                    ->icon('heroicon-o-check-badge')
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->schema([
                        $this->integer('completion_radius_meters', 'Bán kính được hoàn thành', 'm', 20, 2000),
                        $this->integer('auto_complete_grace_minutes', 'Tự hoàn thành sau khi đến', 'phút', 1, 60),
                        $this->integer('delayed_reminder_minutes', 'Nhắc đơn giao chậm sau', 'phút', 1, 1440),
                        $this->number('max_order_distance_km', 'Quãng đường đặt đơn tối đa', 'km', 1, 200),
                        $this->integer('rating_window_hours', 'Thời hạn khách đánh giá', 'giờ', 1, 720),
                    ]),

                Section::make('Trời mưa')
                    ->icon('heroicon-o-cloud')
                    ->description('Mức thưởng được khoá theo từng đơn ngay khi tài xế nhận.')
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->schema([
                        $this->money('rain_bonus_amount', 'Thưởng tài xế / đơn', 0),
                        $this->integer('rain_mode_auto_off_hours', 'Tự tắt chế độ mưa sau', 'giờ', 1, 24),
                    ]),

                Section::make('Phụ phí đêm')
                    ->icon('heroicon-o-moon')
                    ->description('Cộng vào phí ship của khách và cửa hàng khi đặt đơn trong khung giờ. Giờ kết thúc không tính; khung được vắt qua nửa đêm (ví dụ 23:00 → 01:00).')
                    ->schema([
                        Repeater::make('night_windows')
                            ->hiddenLabel()
                            ->schema([
                                TimePicker::make('from')->label('Từ')->seconds(false)->format('H:i')->required(),
                                TimePicker::make('to')->label('Đến trước')->seconds(false)->format('H:i')->required(),
                                $this->money('amount', 'Phụ phí', 0),
                            ])
                            ->itemLabel(fn (array $state): string => filled($state['from'] ?? null) && filled($state['to'] ?? null)
                                ? substr($state['from'], 0, 5).' → '.substr($state['to'], 0, 5)
                                : 'Khung mới')
                            ->columns(3)
                            ->grid(['lg' => 2])
                            ->defaultItems(0)
                            ->maxItems(6)
                            ->reorderable(false)
                            ->addActionLabel('Thêm khung giờ'),
                    ]),
            ]);
    }

    private function walletTab(): Tab
    {
        return Tab::make('Ví & công nợ')
            ->icon('heroicon-o-wallet')
            ->schema([
                Section::make('Ví & công nợ tài xế')
                    ->icon('heroicon-o-wallet')
                    ->columns(2)
                    ->schema([
                        Toggle::make('debt_enabled')->label('Bật công nợ tài xế')
                            ->helperText('Tắt: khu vực này không tạo phí tuần / phạt Offline mới và không chặn tài xế vì công nợ quá hạn. Công nợ đã có được giữ nguyên.')
                            ->columnSpanFull(),
                        $this->money('low_wallet_balance_threshold', 'Cảnh báo số dư ví thấp dưới', 0, 999_999)
                            ->helperText('Tô đỏ số dư trong trang Ví tài xế'),
                        $this->money('min_withdraw_amount', 'Số tiền rút tối thiểu', 1_000, 10_000_000)
                            ->helperText('Tài xế không tạo được yêu cầu rút thấp hơn mức này'),
                        $this->integer('penalty_debt_overdue_hours', 'Công nợ phạt điểm quá hạn sau', 'giờ', 1, 720),
                    ]),

                Section::make('Giới thiệu shop')
                    ->icon('heroicon-o-gift')
                    ->description('Tài xế được cộng vào ví khi shop nhập mã giới thiệu của họ lúc đăng ký và đủ số đơn hoàn thành.')
                    ->columns(2)
                    ->schema([
                        $this->money('referral_reward_amount', 'Thưởng tài xế / shop', 0, 10_000_000)
                            ->helperText('Đặt 0 để tắt thưởng'),
                        $this->integer('referral_min_orders', 'Trả thưởng sau số đơn hoàn thành', 'đơn', 1, 100)
                            ->helperText('Chỉ tính đơn không do chính tài xế giới thiệu giao'),
                    ]),

                Section::make('Shop giới thiệu shop')
                    ->icon('heroicon-o-user-plus')
                    ->description('Shop nhập mã của shop khác lúc đăng ký. Khi shop mới hoàn thành đủ số đơn, shop giới thiệu được cộng điểm (đổi voucher trong Danh mục đổi điểm) và shop mới nhận voucher chào mừng.')
                    ->columns(2)
                    ->schema([
                        $this->integer('shop_referral_points', 'Điểm cho shop giới thiệu', 'điểm', 0, 100_000)
                            ->helperText('Đặt 0 để không cộng điểm'),
                        $this->integer('shop_referral_min_orders', 'Cộng điểm sau số đơn hoàn thành', 'đơn', 1, 100)
                            ->helperText('Tính trên đơn của shop mới được giới thiệu'),
                        $this->money('shop_referral_welcome_amount', 'Voucher chào mừng cho shop mới', 0, 1_000_000)
                            ->helperText('Giảm phí ship cố định. Đặt 0 để không tặng'),
                        $this->integer('shop_referral_welcome_days', 'Hạn dùng voucher chào mừng', 'ngày', 1, 365),
                    ]),
            ]);
    }

    private function integer(string $name, string $label, string $suffix, int $min, int $max): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->integer()->required()
            ->minValue($min)->maxValue($max)->suffix($suffix);
    }

    private function number(string $name, string $label, string $suffix, float $min, float $max, float $step = 0.1): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->required()
            ->minValue($min)->maxValue($max)->step($step)->suffix($suffix);
    }

    private function money(string $name, string $label, int $min, ?int $max = null): TextInput
    {
        return TextInput::make($name)->label($label)->required()
            // Không dùng numeric()/integer(): chúng ép type="number", trình duyệt
            // chặn dấu "." của mask. Rule integer vẫn khiến min/max so theo giá trị số.
            ->type('text')
            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
            ->stripCharacters('.')
            ->inputMode('numeric')
            ->rule('integer')->minValue($min)->maxValue($max)
            ->suffix('đ');
    }

    private function fillFromCity(int $cityId): void
    {
        $this->fillFrom(
            fn (string $key) => OperationalSettings::value($key, $cityId),
            OperationalSettings::nightWindows($cityId),
        );
    }

    /**
     * @param  callable(string): string  $value
     * @param  list<array{from: string, to: string, amount: int}>  $nightWindows
     */
    private function fillFrom(callable $value, array $nightWindows): void
    {
        $state = [];
        foreach (self::FIELDS as $field => $key) {
            // "+ 0" giữ đúng kiểu int/float của chuỗi đã lưu, tránh 55 → 55.00000000000001.
            $state[$field] = $value($key) + 0;
        }

        $state['night_windows'] = $nightWindows;

        $this->form->fill($state);
    }

    /**
     * Chuẩn hoá "HH:MM", chặn khung rỗng (từ = đến) và khung chồng nhau —
     * nếu chồng, một thời điểm sẽ khớp nhiều mức phí.
     *
     * @return list<array{from: string, to: string, amount: int}>
     */
    private function validatedNightWindows(array $rows): array
    {
        $windows = [];
        $covered = array_fill(0, 1440, false);

        foreach (array_values($rows) as $row) {
            $from = substr((string) $row['from'], 0, 5);
            $to = substr((string) $row['to'], 0, 5);
            $fromMinute = OperationalSettings::toMinute($from);
            $toMinute = OperationalSettings::toMinute($to);

            if ($fromMinute === $toMinute) {
                throw ValidationException::withMessages([
                    'data.night_windows' => "Khung {$from} → {$to}: giờ bắt đầu và kết thúc phải khác nhau.",
                ]);
            }

            for ($minute = 0; $minute < 1440; $minute++) {
                if (! OperationalSettings::minuteInWindow($minute, $fromMinute, $toMinute)) {
                    continue;
                }
                if ($covered[$minute]) {
                    throw ValidationException::withMessages([
                        'data.night_windows' => "Khung {$from} → {$to} bị chồng lên khung khác.",
                    ]);
                }
                $covered[$minute] = true;
            }

            $windows[] = ['from' => $from, 'to' => $to, 'amount' => (int) $row['amount']];
        }

        // Giữ thứ tự admin nhập — khung đêm thường đọc 23:00 rồi mới 01:00.
        return $windows;
    }

    public function save(): void
    {
        $values = $this->form->getState();
        $nightWindows = $this->validatedNightWindows($values['night_windows'] ?? []);

        $settings = [
            'pricing.night_windows' => json_encode($nightWindows),
        ];
        foreach (self::FIELDS as $field => $key) {
            $settings[$key] = is_bool($values[$field]) ? (int) $values[$field] : $values[$field];
        }
        $before = collect($settings)->mapWithKeys(fn ($v, $k) => [$k => OperationalSettings::value($k, $this->cityId())])->all();
        OperationalSettings::put($settings, $this->cityId());
        $this->logChanges($before, $settings);

        Notification::make()->title('Đã lưu cấu hình vận hành')->success()->send();

        // Thẻ tóm tắt là component riêng, không tự render lại cùng trang.
        $this->dispatch('operational-settings-saved');
    }

    /** Ghi một dòng nhật ký liệt kê các thông số đã đổi (giá trị cũ → mới) của khu vực. */
    private function logChanges(array $before, array $after): void
    {
        $labels = $this->settingLabels();
        $changes = [];
        foreach ($after as $key => $new) {
            $old = $before[$key] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[] = ($labels[$key] ?? $key).': '.($old === null || $old === '' ? '—' : $old).' → '.($new === '' ? '—' : $new);
            }
        }
        if ($changes) {
            CatalogService::log('operational', $this->cityId(), 'updated', auth()->id(), mb_substr(implode('; ', $changes), 0, 4000));
        }
    }
}
