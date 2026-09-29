<?php

namespace App\Filament\Pages;

use App\Filament\Traits\RestrictToFullAdmin;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'dispatch_max_road_distance_km' => OperationalSettings::dispatchMaxRoadDistanceKm(),
            'streak_milestones' => collect(OperationalSettings::streakMilestones())
                ->map(fn (int $points, int $orders) => ['orders' => $orders, 'points' => $points])
                ->values()->all(),
            'daily_bonus_cap' => OperationalSettings::dailyBonusCap(),
            'weekly_bonus_score' => OperationalSettings::weeklyBonusScore(),
            'weekly_penalty_score' => OperationalSettings::weeklyPenaltyScore(),
            'weekly_bonus_amount' => OperationalSettings::weeklyBonusAmount(),
            'weekly_penalty_amount' => OperationalSettings::weeklyPenaltyAmount(),
            'decline_penalty' => OperationalSettings::scoreDeclinePenalty(),
            'viewed_timeout_penalty' => OperationalSettings::scoreViewedTimeoutPenalty(),
            'unviewed_penalty' => OperationalSettings::scoreUnviewedPenalty(),
            'unviewed_window_size' => OperationalSettings::unviewedWindowSize(),
            'unviewed_limit' => OperationalSettings::unviewedLimit(),
            // Đọc thẳng số nguyên đã lưu — nhân ngược từ tỷ lệ bị sai số số thực
            // (55 → 55.00000000000001) và làm validate integer() chặn nút lưu.
            'shift_normal_min_percent' => (int) OperationalSettings::value('driver_score.shift_normal_min_percent'),
            'shift_reduced_min_percent' => (int) OperationalSettings::value('driver_score.shift_reduced_min_percent'),
            'shift_mid_min_percent' => (int) OperationalSettings::value('driver_score.shift_mid_min_percent'),
            'shift_low_min_percent' => (int) OperationalSettings::value('driver_score.shift_low_min_percent'),
            'shift_reduced_penalty' => OperationalSettings::shiftOnlinePenalties()['reduced'],
            'shift_mid_penalty' => OperationalSettings::shiftOnlinePenalties()['mid'],
            'shift_low_penalty' => OperationalSettings::shiftOnlinePenalties()['low'],
            'shift_critical_penalty' => OperationalSettings::shiftOnlinePenalties()['critical'],
            'rain_bonus_amount' => OperationalSettings::rainBonusAmount(),
            'completion_radius_meters' => OperationalSettings::completionRadiusMeters(),
            'auto_complete_grace_minutes' => OperationalSettings::autoCompleteGraceMinutes(),
            'max_order_distance_km' => OperationalSettings::maxOrderDistanceKm(),
            'rating_window_hours' => OperationalSettings::ratingWindowHours(),
            'delayed_reminder_minutes' => OperationalSettings::delayedReminderMinutes(),
            'max_active_orders_per_driver' => OperationalSettings::maxActiveOrdersPerDriver(),
            'stack_max_pickup_km' => OperationalSettings::stackMaxPickupKm(),
            'stack_max_delivery_km' => OperationalSettings::stackMaxDeliveryKm(),
            'night_23_00_amount' => OperationalSettings::nightSurcharge(23),
            'night_01_03_amount' => OperationalSettings::nightSurcharge(1),
            'offer_open_seconds' => OperationalSettings::offerOpenSeconds(),
            'offer_decision_seconds' => OperationalSettings::offerDecisionSeconds(),
            'dispatch_timeout_minutes' => OperationalSettings::dispatchTimeoutMinutes(),
            'dispatch_retry_seconds' => OperationalSettings::dispatchRetrySeconds(),
            'dispatch_score_weight' => OperationalSettings::dispatchWeights()['score'],
            'dispatch_wait_weight' => OperationalSettings::dispatchWeights()['wait'],
            'dispatch_distance_weight' => OperationalSettings::dispatchWeights()['distance'],
            'dispatch_wait_cap_minutes' => OperationalSettings::dispatchWaitCapMinutes(),
            'rain_mode_auto_off_hours' => OperationalSettings::rainModeAutoOffHours(),
            'penalty_debt_overdue_hours' => OperationalSettings::penaltyDebtOverdueHours(),
            'low_wallet_balance_threshold' => OperationalSettings::lowWalletBalanceThreshold(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Phát đơn')
                ->description('Áp dụng ngay cho các lượt tìm tài xế mới, dựa trên khoảng cách đường thực tế.')
                ->schema([
                    TextInput::make('dispatch_max_road_distance_km')
                        ->label('Khoảng cách phát đơn tối đa')
                        ->numeric()->required()->minValue(0.5)->maxValue(50)->step(0.1)
                        ->suffix('km'),
                ]),

            Section::make('Thưởng điểm theo chuỗi đơn')
                ->description('Khi đạt mốc, tài xế được cộng điểm và chuỗi bắt đầu lại sau mốc cuối cùng.')
                ->schema([
                    Repeater::make('streak_milestones')
                        ->label('Các mốc thưởng')
                        ->schema([
                            TextInput::make('orders')->label('Số đơn liên tiếp')->numeric()->integer()->required()->minValue(1),
                            TextInput::make('points')->label('Điểm cộng')->numeric()->integer()->required()->minValue(1),
                        ])
                        ->columns(2)->minItems(1)->maxItems(10)->reorderable(false)
                        ->addActionLabel('Thêm mốc thưởng'),
                    TextInput::make('daily_bonus_cap')
                        ->label('Trần điểm thưởng mỗi ngày')
                        ->numeric()->integer()->required()->minValue(1)->maxValue(100)
                        ->suffix('điểm'),
                ]),

            Section::make('Chốt thưởng/phạt hàng tuần')
                ->description('Cấu hình được đọc một lần khi lệnh chốt tuần bắt đầu, đảm bảo cả kỳ dùng cùng một chính sách.')
                ->columns(2)
                ->schema([
                    TextInput::make('weekly_bonus_score')->label('Thưởng khi đạt từ')->numeric()->integer()->required()->minValue(111)->maxValue(140)->suffix('điểm'),
                    TextInput::make('weekly_bonus_amount')->label('Số tiền thưởng')->numeric()->integer()->required()->minValue(1000)->prefix('₫'),
                    TextInput::make('weekly_penalty_score')->label('Phạt khi bằng hoặc dưới')->numeric()->integer()->required()->minValue(0)->maxValue(89)->suffix('điểm'),
                    TextInput::make('weekly_penalty_amount')->label('Số tiền phạt')->numeric()->integer()->required()->minValue(1000)->prefix('₫'),
                ]),

            Section::make('Điểm trừ theo hành vi')
                ->description('Nhập số âm hoặc 0. Các thay đổi chỉ áp dụng cho hành vi phát sinh sau khi lưu.')
                ->columns(3)
                ->schema([
                    TextInput::make('decline_penalty')->label('Từ chối đơn')->numeric()->integer()->required()->minValue(-50)->maxValue(0)->suffix('điểm'),
                    TextInput::make('viewed_timeout_penalty')->label('Xem nhưng không nhận')->numeric()->integer()->required()->minValue(-50)->maxValue(0)->suffix('điểm'),
                    TextInput::make('unviewed_penalty')->label('Bỏ lỡ đủ giới hạn')->numeric()->integer()->required()->minValue(-50)->maxValue(0)->suffix('điểm'),
                    TextInput::make('unviewed_window_size')->label('Số offer trong cửa sổ')->numeric()->integer()->required()->minValue(2)->maxValue(20),
                    TextInput::make('unviewed_limit')->label('Số offer bỏ lỡ để phạt')->numeric()->integer()->required()->minValue(1)->maxValue(20),
                ]),

            Section::make('Chấm điểm tỷ lệ online trong ca')
                ->description('Các ngưỡng phần trăm phải giảm dần. Mức dưới ngưỡng thấp nhất dùng điểm phạt nghiêm trọng.')
                ->columns(4)
                ->schema([
                    TextInput::make('shift_normal_min_percent')->label('Bình thường từ')->numeric()->integer()->required()->minValue(1)->maxValue(100)->suffix('%'),
                    TextInput::make('shift_reduced_min_percent')->label('Giảm nhẹ từ')->numeric()->integer()->required()->minValue(1)->maxValue(99)->suffix('%'),
                    TextInput::make('shift_reduced_penalty')->label('Điểm giảm nhẹ')->numeric()->integer()->required()->minValue(-50)->maxValue(0),
                    TextInput::make('shift_mid_min_percent')->label('Mức giữa từ')->numeric()->integer()->required()->minValue(1)->maxValue(99)->suffix('%'),
                    TextInput::make('shift_mid_penalty')->label('Điểm mức giữa')->numeric()->integer()->required()->minValue(-50)->maxValue(0),
                    TextInput::make('shift_low_min_percent')->label('Mức thấp từ')->numeric()->integer()->required()->minValue(1)->maxValue(99)->suffix('%'),
                    TextInput::make('shift_low_penalty')->label('Điểm mức thấp')->numeric()->integer()->required()->minValue(-50)->maxValue(0),
                    TextInput::make('shift_critical_penalty')->label('Điểm dưới mức thấp')->numeric()->integer()->required()->minValue(-50)->maxValue(0),
                ]),

            Section::make('Thưởng và phụ phí')
                ->columns(3)
                ->schema([
                    TextInput::make('rain_bonus_amount')->label('Thưởng tài xế khi trời mưa')->numeric()->integer()->required()->minValue(0)->prefix('₫'),
                    TextInput::make('night_23_00_amount')->label('Phụ phí đêm 23:00–00:59')->numeric()->integer()->required()->minValue(0)->prefix('₫'),
                    TextInput::make('night_01_03_amount')->label('Phụ phí đêm 01:00–03:59')->numeric()->integer()->required()->minValue(0)->prefix('₫'),
                    TextInput::make('rain_mode_auto_off_hours')->label('Tự tắt chế độ mưa sau')->numeric()->integer()->required()->minValue(1)->maxValue(24)->suffix('giờ'),
                ]),

            Section::make('Hoàn thành, đánh giá và nhắc đơn')
                ->columns(3)
                ->schema([
                    TextInput::make('completion_radius_meters')->label('Bán kính được hoàn thành')->numeric()->integer()->required()->minValue(20)->maxValue(2000)->suffix('m'),
                    TextInput::make('auto_complete_grace_minutes')->label('Tự hoàn thành sau khi đến')->numeric()->integer()->required()->minValue(1)->maxValue(60)->suffix('phút'),
                    TextInput::make('max_order_distance_km')->label('Quãng đường đặt đơn tối đa')->numeric()->required()->minValue(1)->maxValue(200)->suffix('km'),
                    TextInput::make('rating_window_hours')->label('Thời hạn đánh giá')->numeric()->integer()->required()->minValue(1)->maxValue(720)->suffix('giờ'),
                    TextInput::make('delayed_reminder_minutes')->label('Nhắc đơn giao chậm sau')->numeric()->integer()->required()->minValue(1)->maxValue(1440)->suffix('phút'),
                    TextInput::make('penalty_debt_overdue_hours')->label('Phạt điểm quá hạn sau')->numeric()->integer()->required()->minValue(1)->maxValue(720)->suffix('giờ'),
                    TextInput::make('low_wallet_balance_threshold')->label('Cảnh báo số dư ví thấp dưới')->numeric()->integer()->required()->minValue(0)->maxValue(999999)->prefix('₫'),
                ]),

            Section::make('Ghép đơn')
                ->description('Điều kiện để một tài xế đang có đơn được nhận thêm đơn phù hợp.')
                ->columns(3)
                ->schema([
                    TextInput::make('max_active_orders_per_driver')->label('Số đơn active tối đa')->numeric()->integer()->required()->minValue(1)->maxValue(2),
                    TextInput::make('stack_max_pickup_km')->label('Lệch điểm lấy tối đa')->numeric()->required()->minValue(0.1)->maxValue(20)->step(0.1)->suffix('km'),
                    TextInput::make('stack_max_delivery_km')->label('Lệch điểm giao tối đa')->numeric()->required()->minValue(0.1)->maxValue(20)->step(0.1)->suffix('km'),
                ]),

            Section::make('Thời gian phát đơn')
                ->description('Thay đổi timeout có thể ảnh hưởng trải nghiệm app tài xế; nên cập nhật ngoài giờ cao điểm.')
                ->columns(4)
                ->schema([
                    TextInput::make('offer_open_seconds')->label('Thời gian mở offer')->numeric()->integer()->required()->minValue(5)->maxValue(120)->suffix('giây'),
                    TextInput::make('offer_decision_seconds')->label('Thời gian quyết định')->numeric()->integer()->required()->minValue(5)->maxValue(180)->suffix('giây'),
                    TextInput::make('dispatch_timeout_minutes')->label('Tổng thời gian tìm tài xế')->numeric()->integer()->required()->minValue(1)->maxValue(120)->suffix('phút'),
                    TextInput::make('dispatch_retry_seconds')->label('Quét lại sau')->numeric()->integer()->required()->minValue(5)->maxValue(300)->suffix('giây'),
                ]),

            Section::make('Trọng số xếp hạng tài xế')
                ->description('Không bắt buộc tổng bằng 100; hệ thống dùng tỷ lệ tương đối giữa ba trọng số.')
                ->columns(4)
                ->collapsed()
                ->schema([
                    TextInput::make('dispatch_score_weight')->label('Trọng số điểm')->numeric()->required()->minValue(0)->maxValue(100),
                    TextInput::make('dispatch_wait_weight')->label('Trọng số chờ')->numeric()->required()->minValue(0)->maxValue(100),
                    TextInput::make('dispatch_distance_weight')->label('Trọng số khoảng cách')->numeric()->required()->minValue(0)->maxValue(100),
                    TextInput::make('dispatch_wait_cap_minutes')->label('Thời gian chờ đạt điểm tối đa')->numeric()->integer()->required()->minValue(1)->maxValue(2880)->suffix('phút'),
                ]),
        ])->statePath('data');
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

        OperationalSettings::put([
            'dispatch.max_road_distance_km' => $values['dispatch_max_road_distance_km'],
            'driver_score.streak_milestones' => $milestones->map(fn ($points, $orders) => "{$orders}:{$points}")->implode(','),
            'driver_score.daily_bonus_cap' => $values['daily_bonus_cap'],
            'driver_score.weekly_bonus_score' => $values['weekly_bonus_score'],
            'driver_score.weekly_penalty_score' => $values['weekly_penalty_score'],
            'driver_score.weekly_bonus_amount' => $values['weekly_bonus_amount'],
            'driver_score.weekly_penalty_amount' => $values['weekly_penalty_amount'],
            'driver_score.decline_penalty' => $values['decline_penalty'],
            'driver_score.viewed_timeout_penalty' => $values['viewed_timeout_penalty'],
            'driver_score.unviewed_penalty' => $values['unviewed_penalty'],
            'driver_score.unviewed_window_size' => $values['unviewed_window_size'],
            'driver_score.unviewed_limit' => $values['unviewed_limit'],
            'driver_score.shift_normal_min_percent' => $values['shift_normal_min_percent'],
            'driver_score.shift_reduced_min_percent' => $values['shift_reduced_min_percent'],
            'driver_score.shift_mid_min_percent' => $values['shift_mid_min_percent'],
            'driver_score.shift_low_min_percent' => $values['shift_low_min_percent'],
            'driver_score.shift_reduced_penalty' => $values['shift_reduced_penalty'],
            'driver_score.shift_mid_penalty' => $values['shift_mid_penalty'],
            'driver_score.shift_low_penalty' => $values['shift_low_penalty'],
            'driver_score.shift_critical_penalty' => $values['shift_critical_penalty'],
            'order.rain_bonus_amount' => $values['rain_bonus_amount'],
            'order.completion_radius_meters' => $values['completion_radius_meters'],
            'order.auto_complete_grace_minutes' => $values['auto_complete_grace_minutes'],
            'order.max_distance_km' => $values['max_order_distance_km'],
            'order.rating_window_hours' => $values['rating_window_hours'],
            'order.delayed_reminder_minutes' => $values['delayed_reminder_minutes'],
            'order.max_active_per_driver' => $values['max_active_orders_per_driver'],
            'order.stack_max_pickup_km' => $values['stack_max_pickup_km'],
            'order.stack_max_delivery_km' => $values['stack_max_delivery_km'],
            'pricing.night_23_00_amount' => $values['night_23_00_amount'],
            'pricing.night_01_03_amount' => $values['night_01_03_amount'],
            'dispatch.offer_open_seconds' => $values['offer_open_seconds'],
            'dispatch.offer_decision_seconds' => $values['offer_decision_seconds'],
            'dispatch.total_timeout_minutes' => $values['dispatch_timeout_minutes'],
            'dispatch.retry_seconds' => $values['dispatch_retry_seconds'],
            'dispatch.score_weight' => $values['dispatch_score_weight'],
            'dispatch.wait_weight' => $values['dispatch_wait_weight'],
            'dispatch.distance_weight' => $values['dispatch_distance_weight'],
            'dispatch.wait_cap_minutes' => $values['dispatch_wait_cap_minutes'],
            'rain_mode.auto_off_hours' => $values['rain_mode_auto_off_hours'],
            'debt.penalty_overdue_hours' => $values['penalty_debt_overdue_hours'],
            'wallet.low_balance_threshold' => $values['low_wallet_balance_threshold'],
        ]);

        Notification::make()->title('Đã lưu cấu hình vận hành')->success()->send();
    }
}
