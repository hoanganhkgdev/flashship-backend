<?php

namespace App\Filament\Pages;

use App\Filament\Traits\RestrictToFullAdmin;
use App\Filament\Widgets\OperationalSettingsOverview;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\RawJs;
use Illuminate\Support\Carbon;
use Modules\Core\Models\City;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\OperationalSettings;

class OperationalSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    use RestrictToFullAdmin;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Cấu hình vận hành';

    protected static ?string $title = 'Cấu hình vận hành';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.operational-settings';

    /** Ô form => key trong bảng settings (trừ streak_milestones và night_windows, xử lý riêng). */
    private const FIELDS = [
        'dispatch_max_road_distance_km' => 'dispatch.max_road_distance_km',
        'daily_bonus_cap' => 'driver_score.daily_bonus_cap',
        'max_score' => 'driver_score.max_score',
        'weekly_bonus_score' => 'driver_score.weekly_bonus_score',
        'weekly_penalty_score' => 'driver_score.weekly_penalty_score',
        'weekly_bonus_amount' => 'driver_score.weekly_bonus_amount',
        'weekly_penalty_amount' => 'driver_score.weekly_penalty_amount',
        'decline_penalty' => 'driver_score.decline_penalty',
        'viewed_timeout_penalty' => 'driver_score.viewed_timeout_penalty',
        'unviewed_penalty' => 'driver_score.unviewed_penalty',
        'unviewed_window_size' => 'driver_score.unviewed_window_size',
        'unviewed_limit' => 'driver_score.unviewed_limit',
        'shift_normal_min_percent' => 'driver_score.shift_normal_min_percent',
        'shift_reduced_min_percent' => 'driver_score.shift_reduced_min_percent',
        'shift_mid_min_percent' => 'driver_score.shift_mid_min_percent',
        'shift_low_min_percent' => 'driver_score.shift_low_min_percent',
        'shift_reduced_penalty' => 'driver_score.shift_reduced_penalty',
        'shift_mid_penalty' => 'driver_score.shift_mid_penalty',
        'shift_low_penalty' => 'driver_score.shift_low_penalty',
        'shift_critical_penalty' => 'driver_score.shift_critical_penalty',
        'rain_bonus_amount' => 'order.rain_bonus_amount',
        'completion_radius_meters' => 'order.completion_radius_meters',
        'auto_complete_grace_minutes' => 'order.auto_complete_grace_minutes',
        'max_order_distance_km' => 'order.max_distance_km',
        'rating_window_hours' => 'order.rating_window_hours',
        'delayed_reminder_minutes' => 'order.delayed_reminder_minutes',
        'max_active_orders_per_driver' => 'order.max_active_per_driver',
        'stack_max_pickup_km' => 'order.stack_max_pickup_km',
        'stack_max_delivery_km' => 'order.stack_max_delivery_km',
        'offer_open_seconds' => 'dispatch.offer_open_seconds',
        'offer_decision_seconds' => 'dispatch.offer_decision_seconds',
        'dispatch_timeout_minutes' => 'dispatch.total_timeout_minutes',
        'dispatch_retry_seconds' => 'dispatch.retry_seconds',
        'dispatch_score_weight' => 'dispatch.score_weight',
        'dispatch_wait_weight' => 'dispatch.wait_weight',
        'dispatch_distance_weight' => 'dispatch.distance_weight',
        'dispatch_wait_cap_minutes' => 'dispatch.wait_cap_minutes',
        'rain_mode_auto_off_hours' => 'rain_mode.auto_off_hours',
        'penalty_debt_overdue_hours' => 'debt.penalty_overdue_hours',
        'low_wallet_balance_threshold' => 'wallet.low_balance_threshold',
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
                        [3 => 1, 6 => 2, 10 => 4],
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
                    $this->dispatchTab(),
                    $this->driverScoreTab(),
                    $this->weeklyTab(),
                    $this->orderTab(),
                    $this->walletTab(),
                ]),
        ])->statePath('data');
    }

    private function dispatchTab(): Tab
    {
        return Tab::make('Phát đơn')
            ->icon('heroicon-o-signal')
            ->schema([
                Section::make('Phạm vi & ghép đơn')
                    ->icon('heroicon-o-map-pin')
                    ->description('Chỉ phát cho tài xế trong khoảng cách đường thực tế; tài xế đang có đơn chỉ nhận thêm đơn cùng tuyến.')
                    ->columns(['sm' => 2, 'lg' => 4])
                    ->schema([
                        $this->number('dispatch_max_road_distance_km', 'Khoảng cách phát tối đa', 'km', 0.5, 50, step: 0.1),
                        $this->integer('max_active_orders_per_driver', 'Đơn active tối đa', 'đơn', 1, 2)
                            ->helperText('1 = không ghép đơn'),
                        $this->number('stack_max_pickup_km', 'Lệch điểm lấy khi ghép', 'km', 0.1, 20, step: 0.1),
                        $this->number('stack_max_delivery_km', 'Lệch điểm giao khi ghép', 'km', 0.1, 20, step: 0.1),
                    ]),

                Section::make('Thời gian')
                    ->icon('heroicon-o-clock')
                    ->description('Thay đổi ảnh hưởng trực tiếp app tài xế — nên cập nhật ngoài giờ cao điểm.')
                    ->columns(['sm' => 2, 'lg' => 4])
                    ->schema([
                        $this->integer('offer_open_seconds', 'Chờ tài xế mở offer', 'giây', 5, 120),
                        $this->integer('offer_decision_seconds', 'Quyết định sau khi mở', 'giây', 5, 180),
                        $this->integer('dispatch_retry_seconds', 'Quét lại khi chưa có ai', 'giây', 5, 300),
                        $this->integer('dispatch_timeout_minutes', 'Dừng tìm tài xế sau', 'phút', 1, 120),
                    ]),

                Section::make('Trọng số xếp hạng tài xế')
                    ->icon('heroicon-o-scale')
                    ->description('Hệ thống dùng tỷ lệ tương đối giữa ba trọng số, không bắt buộc tổng bằng 100.')
                    ->collapsible()
                    ->collapsed()
                    ->columns(['sm' => 2, 'lg' => 4])
                    ->schema([
                        $this->weight('dispatch_score_weight', 'Điểm tài xế'),
                        $this->weight('dispatch_wait_weight', 'Thời gian chờ'),
                        $this->weight('dispatch_distance_weight', 'Khoảng cách'),
                        $this->integer('dispatch_wait_cap_minutes', 'Chờ đạt điểm tối đa', 'phút', 1, 2880),
                    ]),
            ]);
    }

    private function driverScoreTab(): Tab
    {
        return Tab::make('Điểm tài xế')
            ->icon('heroicon-o-star')
            ->schema([
                Section::make('Thưởng chuỗi đơn liên tiếp')
                    ->icon('heroicon-o-fire')
                    ->description('Đạt mốc cuối cùng thì chuỗi bắt đầu lại. Tổng điểm thưởng mỗi ngày không vượt trần.')
                    ->schema([
                        Repeater::make('streak_milestones')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('orders')->label('Liên tiếp')->numeric()->integer()->required()->minValue(1)->suffix('đơn'),
                                TextInput::make('points')->label('Thưởng')->numeric()->integer()->required()->minValue(1)->suffix('điểm'),
                            ])
                            ->itemLabel(fn (array $state): string => filled($state['orders'] ?? null) ? "Mốc {$state['orders']} đơn" : 'Mốc mới')
                            ->columns(2)
                            ->grid(['md' => 2, 'xl' => 3])
                            ->minItems(1)
                            ->maxItems(10)
                            ->reorderable(false)
                            ->addActionLabel('Thêm mốc'),
                        Grid::make(['sm' => 2, 'lg' => 4])->schema([
                            $this->integer('daily_bonus_cap', 'Trần điểm thưởng mỗi ngày', 'điểm', 1, 100),
                        ]),
                    ]),

                Section::make('Trừ điểm theo hành vi')
                    ->icon('heroicon-o-hand-thumb-down')
                    ->description('Nhập số âm hoặc 0. Bỏ lỡ đủ giới hạn thì tài xế bị trừ điểm và chuyển Offline.')
                    ->columns(['sm' => 2, 'lg' => 5])
                    ->schema([
                        $this->penalty('decline_penalty', 'Từ chối đơn'),
                        $this->penalty('viewed_timeout_penalty', 'Xem nhưng không nhận'),
                        $this->penalty('unviewed_penalty', 'Bỏ lỡ đủ giới hạn'),
                        $this->integer('unviewed_limit', 'Giới hạn bỏ lỡ', 'offer', 1, 20),
                        $this->integer('unviewed_window_size', 'Trong số offer gần nhất', 'offer', 2, 20),
                    ]),

                Section::make('Tỷ lệ online trong ca')
                    ->icon('heroicon-o-signal')
                    ->description('Chấm cuối mỗi ca theo % thời gian online. Ngưỡng phải giảm dần từ trên xuống.')
                    ->schema([
                        $this->shiftTier('Bình thường', 'shift_normal_min_percent', null, 100),
                        $this->shiftTier('Giảm nhẹ', 'shift_reduced_min_percent', 'shift_reduced_penalty', 99),
                        $this->shiftTier('Mức giữa', 'shift_mid_min_percent', 'shift_mid_penalty', 99),
                        $this->shiftTier('Mức thấp', 'shift_low_min_percent', 'shift_low_penalty', 99),
                        Grid::make(['default' => 1, 'sm' => 3])->schema([
                            Placeholder::make('critical_label')
                                ->label('Dưới mức thấp')
                                ->content(fn (Get $get) => 'Online dưới '.((int) $get('shift_low_min_percent')).'% ca'),
                            $this->penalty('shift_critical_penalty', 'Điểm trừ')->columnStart(['sm' => 3]),
                        ]),
                    ]),
            ]);
    }

    private function weeklyTab(): Tab
    {
        return Tab::make('Chốt tuần')
            ->icon('heroicon-o-trophy')
            ->schema([
                Section::make('Thang điểm')
                    ->icon('heroicon-o-chart-bar')
                    ->description('Điểm tài xế không vượt quá trần. Đầu tuần mọi tài xế về '.\Modules\Driver\Services\DriverScoreService::DEFAULT_SCORE.' điểm.')
                    ->columns(['sm' => 2, 'lg' => 4])
                    ->schema([
                        $this->integer('max_score', 'Trần điểm tài xế', 'điểm', 111, 1000)
                            ->live(onBlur: true),
                    ]),

                Section::make('Thưởng/phạt cuối tuần')
                    ->icon('heroicon-o-trophy')
                    ->description('Lệnh chốt tuần đọc cấu hình một lần lúc bắt đầu (Thứ Hai 00:02), cả kỳ dùng chung một chính sách.')
                    ->columns(2)
                    ->schema([
                        Section::make('Thưởng')
                            ->compact()
                            ->columnSpan(1)
                            ->schema([
                                $this->integer('weekly_bonus_score', 'Khi đạt từ', 'điểm', 111, 1000)
                                    ->helperText(fn (Get $get) => 'Tối đa bằng trần điểm ('.((int) $get('max_score')).')'),
                                $this->money('weekly_bonus_amount', 'Số tiền cộng vào ví', 1000),
                            ]),
                        Section::make('Phạt')
                            ->compact()
                            ->columnSpan(1)
                            ->schema([
                                $this->integer('weekly_penalty_score', 'Khi bằng hoặc dưới', 'điểm', 0, 89),
                                $this->money('weekly_penalty_amount', 'Số tiền ghi công nợ', 1000),
                            ]),
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
                        $this->money('low_wallet_balance_threshold', 'Cảnh báo số dư ví thấp dưới', 0, 999_999)
                            ->helperText('Tô đỏ số dư trong trang Ví tài xế'),
                        $this->integer('penalty_debt_overdue_hours', 'Công nợ phạt điểm quá hạn sau', 'giờ', 1, 720),
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

    private function penalty(string $name, string $label): TextInput
    {
        return $this->integer($name, $label, 'điểm', -50, 0);
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

    private function weight(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->required()->minValue(0)->maxValue(100)
            ->live(onBlur: true)
            ->helperText(function (Get $get) use ($name): string {
                $total = (float) $get('dispatch_score_weight') + (float) $get('dispatch_wait_weight') + (float) $get('dispatch_distance_weight');

                return $total > 0 ? 'Chiếm '.round((float) $get($name) / $total * 100).'%' : '—';
            });
    }

    private function shiftTier(string $label, string $threshold, ?string $penalty, int $max): Grid
    {
        return Grid::make(['default' => 1, 'sm' => 3])->schema([
            Placeholder::make("{$threshold}_label")->label($label)->content(fn (Get $get) => match ($threshold) {
                'shift_normal_min_percent' => 'Online '.((int) $get($threshold)).'–100% ca',
                'shift_reduced_min_percent' => 'Online '.((int) $get($threshold)).'–'.((int) $get('shift_normal_min_percent') - 1).'% ca',
                'shift_mid_min_percent' => 'Online '.((int) $get($threshold)).'–'.((int) $get('shift_reduced_min_percent') - 1).'% ca',
                default => 'Online '.((int) $get($threshold)).'–'.((int) $get('shift_mid_min_percent') - 1).'% ca',
            }),
            $this->integer($threshold, 'Từ', '%', 1, $max)->live(onBlur: true),
            $penalty
                ? $this->penalty($penalty, 'Điểm trừ')
                : Placeholder::make("{$threshold}_penalty")->label('Điểm trừ')->content('0 — không trừ'),
        ]);
    }

    private function fillFromCity(int $cityId): void
    {
        $this->fillFrom(
            fn (string $key) => OperationalSettings::value($key, $cityId),
            OperationalSettings::streakMilestones($cityId),
            OperationalSettings::nightWindows($cityId),
        );
    }

    /**
     * @param  callable(string): string  $value
     * @param  array<int, int>  $milestones
     * @param  list<array{from: string, to: string, amount: int}>  $nightWindows
     */
    private function fillFrom(callable $value, array $milestones, array $nightWindows): void
    {
        $state = [];
        foreach (self::FIELDS as $field => $key) {
            // "+ 0" giữ đúng kiểu int/float của chuỗi đã lưu, tránh 55 → 55.00000000000001.
            $state[$field] = $value($key) + 0;
        }

        $state['streak_milestones'] = collect($milestones)
            ->map(fn (int $points, int $orders) => ['orders' => $orders, 'points' => $points])
            ->values()->all();
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
        $milestones = collect($values['streak_milestones'])
            ->mapWithKeys(fn (array $row) => [(int) $row['orders'] => (int) $row['points']])
            ->sortKeys();

        if ($milestones->count() !== count($values['streak_milestones'])) {
            throw ValidationException::withMessages([
                'data.streak_milestones' => 'Số đơn của mỗi mốc thưởng không được trùng nhau.',
            ]);
        }

        if ((int) $values['weekly_bonus_score'] > (int) $values['max_score']) {
            throw ValidationException::withMessages([
                'data.weekly_bonus_score' => 'Mốc thưởng không được cao hơn trần điểm ('.((int) $values['max_score']).') — tài xế sẽ không bao giờ đạt được.',
            ]);
        }

        if ((int) $values['weekly_penalty_score'] >= (int) $values['weekly_bonus_score']) {
            throw ValidationException::withMessages([
                'data.weekly_penalty_score' => 'Mốc phạt phải thấp hơn mốc thưởng.',
            ]);
        }

        if ((int) $values['unviewed_limit'] > (int) $values['unviewed_window_size']) {
            throw ValidationException::withMessages([
                'data.unviewed_limit' => 'Số offer bỏ lỡ không được lớn hơn kích thước cửa sổ.',
            ]);
        }

        $shiftThresholds = [
            (int) $values['shift_normal_min_percent'],
            (int) $values['shift_reduced_min_percent'],
            (int) $values['shift_mid_min_percent'],
            (int) $values['shift_low_min_percent'],
        ];
        if (! ($shiftThresholds[0] > $shiftThresholds[1]
            && $shiftThresholds[1] > $shiftThresholds[2]
            && $shiftThresholds[2] > $shiftThresholds[3])) {
            throw ValidationException::withMessages([
                'data.shift_normal_min_percent' => 'Các ngưỡng online phải giảm dần và không được trùng nhau.',
            ]);
        }

        $weightTotal = (float) $values['dispatch_score_weight']
            + (float) $values['dispatch_wait_weight']
            + (float) $values['dispatch_distance_weight'];
        if ($weightTotal <= 0) {
            throw ValidationException::withMessages([
                'data.dispatch_score_weight' => 'Phải có ít nhất một trọng số lớn hơn 0.',
            ]);
        }

        $nightWindows = $this->validatedNightWindows($values['night_windows'] ?? []);

        $settings = [
            'driver_score.streak_milestones' => $milestones->map(fn ($points, $orders) => "{$orders}:{$points}")->implode(','),
            'pricing.night_windows' => json_encode($nightWindows),
        ];
        foreach (self::FIELDS as $field => $key) {
            $settings[$key] = $values[$field];
        }
        OperationalSettings::put($settings, $this->cityId());

        Notification::make()->title('Đã lưu cấu hình vận hành')->success()->send();

        // Thẻ tóm tắt là component riêng, không tự render lại cùng trang.
        $this->dispatch('operational-settings-saved');
    }
}
