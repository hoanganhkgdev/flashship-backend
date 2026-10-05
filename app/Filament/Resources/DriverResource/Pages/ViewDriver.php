<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\OrderResource;
use App\Models\DriverStatusLog;
use App\Services\DriverAccountService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    protected static string $view = 'filament.resources.driver-resource.pages.view-driver';

    private const RECENT_ORDERS = 8;

    private const STATUS_LABELS = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã huỷ'];

    private const STATUS_COLORS = ['pending' => 'warning', 'assigned' => 'info', 'processing' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Hồ sơ tài xế · '.$this->record->phone.' · '.($this->record->city?->name ?? 'Chưa có khu vực');
    }

    private function service(): DriverAccountService
    {
        return app(DriverAccountService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Duyệt tài xế')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => (int) $this->record->status === 0)
                ->requiresConfirmation()
                ->modalHeading('Duyệt tài xế')
                ->modalDescription(fn () => DriverResource::approvalDescription($this->service()->approvalCheck($this->record)))
                ->action(function (): void {
                    $result = $this->service()->approve($this->record, auth()->id());
                    Notification::make()->title($result['message'])
                        ->body($result['warnings'] ? 'Lưu ý: '.implode('; ', $result['warnings']) : null)
                        ->{$result['ok'] ? 'success' : 'danger'}()->send();
                    $this->record->refresh();
                }),

            Actions\Action::make('block')
                ->label('Khóa tài khoản')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (): bool => (int) $this->record->status === 1)
                ->modalHeading('Khóa tài khoản')
                ->modalDescription('Lý do được lưu vào lịch sử tài khoản.')
                ->form([Forms\Components\Textarea::make('reason')->label('Lý do khóa')->required()->minLength(3)->maxLength(500)->rows(2)])
                ->action(function (array $data): void {
                    $result = $this->service()->lock($this->record, $data['reason'], auth()->id());
                    Notification::make()->title($result['message'])->{$result['ok'] ? 'warning' : 'danger'}()->send();
                    $this->record->refresh();
                }),

            Actions\Action::make('unblock')
                ->label('Mở khóa')
                ->icon('heroicon-o-lock-open')
                ->color('info')
                ->visible(fn (): bool => (int) $this->record->status === 2)
                ->modalHeading('Mở khóa tài khoản')
                ->form([Forms\Components\Textarea::make('reason')->label('Ghi chú (tuỳ chọn)')->maxLength(500)->rows(2)])
                ->action(function (array $data): void {
                    $result = $this->service()->unlock($this->record, $data['reason'] ?? null, auth()->id());
                    Notification::make()->title($result['message'])->success()->send();
                    $this->record->refresh();
                }),

            Actions\EditAction::make()->label('Chỉnh sửa')->icon('heroicon-o-pencil-square'),

            // Tài xế đã có đơn, ví hoặc công nợ thì không được xóa hẳn: hãy khóa để giữ lịch sử tài chính.
            tap(
                Actions\DeleteAction::make()->label('Xóa tài xế')->successRedirectUrl(DriverResource::getUrl('index'))
                    ->visible(fn (): bool => ! $this->service()->hasHistory($this->record)),
                fn ($a) => DriverResource::configureDeleteAction($a)
            ),
        ];
    }

    /** Hoạt động 30 ngày gần nhất. */
    public function getSummary(): array
    {
        $since = now()->subDays(30);
        $id = $this->record->id;

        $orders = DB::table('orders')->where('delivery_man_id', $id)->where('created_at', '>=', $since)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(status = 'completed'), 0) as completed, COALESCE(SUM(status = 'cancelled'), 0) as cancelled")->first();

        $fees = (int) DB::table('orders')->where('delivery_man_id', $id)->where('status', 'completed')->where('completed_at', '>=', $since)
            ->sum(DB::raw('shipping_fee + bonus_fee + night_surcharge'));

        // Thưởng mưa và bù giảm giá đã ghi có vào ví cũng là thu nhập (cùng cách tính với trang Thu nhập tài xế).
        $credits = (int) DB::table('driver_wallet_transactions as t')->join('driver_wallets as w', 'w.id', '=', 't.wallet_id')
            ->where('w.driver_id', $id)->where('t.type', 'credit')->where('t.created_at', '>=', $since)
            ->where(fn ($q) => $q->where('t.reference', 'like', 'order\_%\_rain')->orWhere('t.reference', 'like', 'order\_%\_discount'))
            ->sum('t.amount');

        return [
            'completed' => (int) $orders->completed, 'total' => (int) $orders->total,
            'cancel_rate' => $orders->total ? (int) round($orders->cancelled / $orders->total * 100) : 0,
            'cancelled' => (int) $orders->cancelled,
            'earnings' => $fees + $credits,
            'lifetime' => (int) $this->record->completed_orders_count,
            'score' => (int) ($this->record->driver_score ?? 80),
            'low_score' => ($this->record->driver_score ?? 80) < DriverResource::LOW_SCORE,
        ];
    }

    /** Số dư ví, công nợ chưa thu và tiền đang xin rút. */
    public function getWallet(): array
    {
        $id = $this->record->id;
        $debts = DB::table('driver_debts')->where('driver_id', $id)->whereColumn('amount_due', '>', 'amount_paid')
            ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as amount, COUNT(*) as items')->first();
        $pending = DB::table('withdraw_requests')->where('driver_id', $id)->where('status', 'pending')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount, COUNT(*) as items')->first();

        return [
            'balance' => (int) DB::table('driver_wallets')->where('driver_id', $id)->value('balance'),
            'debt' => (int) $debts->amount, 'debt_items' => (int) $debts->items,
            'withdraw' => (int) $pending->amount, 'withdraw_items' => (int) $pending->items,
        ];
    }

    public function getApproval(): array
    {
        return $this->service()->approvalCheck($this->record);
    }

    /** Trạng thái giấy tờ mới nhất. */
    public function getDocuments(): array
    {
        $label = fn (?string $s): array => match ($s) {
            'approved' => ['Đã duyệt', 'success'], 'rejected' => ['Từ chối', 'danger'], 'pending' => ['Chờ duyệt', 'warning'], default => ['Chưa tải lên', 'gray'],
        };

        return [
            'CCCD / CMND' => $label($this->record->latestDriverCccdImage?->status),
            'Bằng lái' => $label($this->record->latestDriverLicense?->status),
        ];
    }

    public function getRecentOrders(): Collection
    {
        $services = ServiceType::pluck('label', 'key');

        return Order::query()->where('delivery_man_id', $this->record->id)->orderByDesc('created_at')->limit(self::RECENT_ORDERS)
            ->get(['id', 'code', 'service_type', 'status', 'pickup_address', 'delivery_address', 'shipping_fee', 'bonus_fee', 'night_surcharge', 'created_at'])
            ->map(fn (Order $o) => [
                'url' => OrderResource::getUrl('view', ['record' => $o->id]),
                'code' => $o->code ?: '#'.$o->id,
                'service' => $services[$o->service_type] ?? $o->service_type,
                'status' => self::STATUS_LABELS[$o->status] ?? $o->status,
                'color' => self::STATUS_COLORS[$o->status] ?? 'gray',
                'route' => Str::limit((string) $o->pickup_address, 36).' → '.Str::limit((string) $o->delivery_address, 36),
                'fee' => (int) $o->shipping_fee + (int) $o->bonus_fee + (int) $o->night_surcharge,
                'when' => $o->created_at,
            ]);
    }

    /** Lịch sử duyệt, khóa, mở khóa của tài khoản. */
    public function getLogs(): Collection
    {
        return DriverStatusLog::with('performer:id,name')->where('driver_id', $this->record->id)->orderByDesc('id')->limit(8)->get()
            ->map(fn (DriverStatusLog $l) => [
                'action' => DriverStatusLog::ACTION_LABELS[$l->action] ?? $l->action,
                'key' => $l->action,
                'reason' => $l->reason,
                'by' => $l->performer?->name ?? 'Hệ thống / không rõ',
                'when' => $l->created_at,
            ]);
    }
}
