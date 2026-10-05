<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ShopResource;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;

class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    protected static string $view = 'filament.resources.shop-resource.pages.view-shop';

    private const RECENT_ORDERS = 8;

    private const STATUS_LABELS = ['pending' => 'Chờ tài xế', 'assigned' => 'Đã phân công', 'processing' => 'Đã lấy hàng', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã huỷ'];

    private const STATUS_COLORS = ['pending' => 'warning', 'assigned' => 'info', 'processing' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Hồ sơ cửa hàng · '.$this->record->phone.' · '.($this->record->city?->name ?? 'Chưa có khu vực');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggle_lock')
                ->label(fn (): string => (int) $this->record->status === 2 ? 'Mở khóa' : 'Khóa tài khoản')
                ->icon(fn (): string => (int) $this->record->status === 2 ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color(fn (): string => (int) $this->record->status === 2 ? 'success' : 'danger')
                ->visible(fn (): bool => auth()->user()?->user_type === 'admin')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => (int) $this->record->status === 2 ? 'Mở khóa tài khoản shop?' : 'Khóa tài khoản shop?')
                ->action(function (): void {
                    $this->record->update(['status' => (int) $this->record->status === 2 ? 1 : 2]);
                    $this->record->refresh();
                }),
            Actions\EditAction::make()->label('Chỉnh sửa')->icon('heroicon-o-pencil-square'),
        ];
    }

    private function orders(): Builder
    {
        return Order::query()->where('sender_platform_id', $this->record->id)->where('platform', 'shop_app');
    }

    /** Tổng hợp hoạt động của shop trên app (đơn đã đặt, hoàn thành, phí đã chi...). */
    public function getSummary(): array
    {
        $row = $this->orders()->selectRaw("
            COUNT(*) as total,
            COALESCE(SUM(status = 'completed'), 0) as completed,
            COALESCE(SUM(status = 'cancelled'), 0) as cancelled,
            COALESCE(SUM(status IN ('pending','assigned','processing')), 0) as active,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN shipping_fee + bonus_fee + night_surcharge END), 0) as spent,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN discount_amount END), 0) as discount,
            MIN(created_at) as first_at
        ")->first();

        $completed = (int) $row->completed;
        $last = $this->record->last_activity_at;

        return [
            'total' => (int) $row->total, 'completed' => $completed, 'cancelled' => (int) $row->cancelled, 'active' => (int) $row->active,
            'spent' => (int) $row->spent, 'discount' => (int) $row->discount,
            'avg' => $completed ? (int) round($row->spent / $completed) : 0,
            'cancel_rate' => $row->total ? (int) round($row->cancelled / $row->total * 100) : 0,
            'first_at' => $row->first_at ? Carbon::parse($row->first_at) : null,
            // Hoạt động gần nhất tính cả đơn tổng đài khớp số điện thoại (giá trị đã tính sẵn trong truy vấn của bảng).
            'last_at' => $last && ! str_starts_with((string) $last, '1970') ? Carbon::parse($last) : null,
            'call_center' => (int) ($this->record->call_center_orders ?? 0),
        ];
    }

    public function getRecentOrders(): Collection
    {
        $services = ServiceType::pluck('label', 'key');

        return $this->orders()->orderByDesc('created_at')->limit(self::RECENT_ORDERS)
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

    /** Mã giảm giá shop đã dùng. */
    public function getVoucherUsages(): Collection
    {
        return DB::table('voucher_usages as u')
            ->join('vouchers as v', 'v.id', '=', 'u.voucher_id')
            ->leftJoin('orders as o', 'o.id', '=', 'u.order_id')
            ->where('u.user_id', $this->record->id)
            ->orderByDesc('u.used_at')->limit(6)
            ->get(['v.code', 'u.used_at', 'o.code as order_code', 'o.discount_amount']);
    }

    public function getVoucherUsageTotal(): int
    {
        return DB::table('voucher_usages')->where('user_id', $this->record->id)->count();
    }

    public function sharesPhoneWithDriver(): bool
    {
        return (bool) $this->record->shares_driver_phone;
    }

    /** Người giới thiệu shop vào hệ thống (tài xế hoặc shop khác), nếu có. */
    public function getReferrer(): ?array
    {
        if ($this->record->referred_by_driver_id) {
            $u = User::find($this->record->referred_by_driver_id, ['id', 'name', 'phone']);

            return $u ? ['type' => 'Tài xế', 'name' => $u->name, 'phone' => $u->phone] : null;
        }
        if ($this->record->referred_by_shop_id) {
            $u = User::find($this->record->referred_by_shop_id, ['id', 'name', 'phone']);

            return $u ? ['type' => 'Cửa hàng', 'name' => $u->name, 'phone' => $u->phone] : null;
        }

        return null;
    }

    /** Đơn tổng đài có số lấy hàng trùng số điện thoại của shop (chỉ khoảng 9% đơn tổng đài có ghi số). */
    public function getCallCenterMatches(): array
    {
        $digits = preg_replace('/\D/', '', (string) $this->record->phone);
        if (strlen($digits) < 9) {
            return ['count' => 0, 'latest' => collect()];
        }
        $last9 = substr($digits, -9);

        $query = Order::query()->where('platform', 'call_center')
            ->whereIn('pickup_phone', ['0'.$last9, '84'.$last9, '+84'.$last9, $last9]);

        return [
            'count' => (clone $query)->count(),
            'latest' => $query->orderByDesc('created_at')->limit(5)->get(['id', 'code', 'created_at'])
                ->map(fn (Order $o) => ['url' => OrderResource::getUrl('view', ['record' => $o->id]), 'code' => $o->code ?: '#'.$o->id, 'when' => $o->created_at]),
        ];
    }
}
