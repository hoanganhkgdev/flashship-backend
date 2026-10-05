<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\VoucherResource;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Voucher;

class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;

    protected static string $view = 'filament.resources.voucher-resource.pages.view-voucher';

    private const DAYS = 30;

    private const RECENT = 10;

    public function getTitle(): string
    {
        return 'Mã '.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return Voucher::describeOffer($this->record->type, $this->record->value, $this->record->max_discount);
    }

    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            Actions\Action::make('toggle_active')
                ->label(fn (): string => $this->record->is_active ? 'Tắt mã' : 'Bật lại mã')
                ->icon(fn (): string => $this->record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color('gray')
                ->action(function (): void {
                    $this->record->update(['is_active' => ! $this->record->is_active]);
                    $this->record->refresh();
                }),
            Actions\ReplicateAction::make()
                ->label('Nhân bản')
                ->color('gray')
                ->excludeAttributes(['used_count'])
                ->beforeReplicaSaved(fn (Voucher $replica) => $resource::prepareReplica($replica))
                ->successRedirectUrl(fn (Voucher $replica): string => $resource::getUrl('edit', ['record' => $replica])),
            Actions\EditAction::make()->label('Chỉnh sửa')->icon('heroicon-o-pencil-square'),
        ];
    }

    /** Tổng hợp hiệu quả của mã: lượt dùng, số người, đơn, chi phí. */
    public function getSummary(): array
    {
        $usages = DB::table('voucher_usages')->where('voucher_id', $this->record->id)
            ->selectRaw('COUNT(*) as uses, COUNT(DISTINCT user_id) as users, MAX(used_at) as last_at')->first();

        $orders = DB::table('orders')->where('voucher_code', $this->record->code)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(status = 'completed'), 0) as completed, COALESCE(SUM(status = 'cancelled'), 0) as cancelled,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN discount_amount END), 0) as cost")->first();

        $completed = (int) $orders->completed;

        return [
            'uses' => (int) $usages->uses, 'users' => (int) $usages->users,
            'last_at' => $usages->last_at ? Carbon::parse($usages->last_at) : null,
            'orders' => (int) $orders->total, 'completed' => $completed, 'cancelled' => (int) $orders->cancelled,
            'cost' => (int) $orders->cost,
            'avg' => $completed ? (int) round($orders->cost / $completed) : 0,
            'limit_pct' => $this->record->usage_limit ? min(100, (int) round($this->record->used_count / $this->record->usage_limit * 100)) : null,
        ];
    }

    /** Lượt dùng theo ngày trong 30 ngày gần nhất. */
    public function getDaily(): array
    {
        $from = now()->subDays(self::DAYS - 1)->startOfDay();
        $rows = DB::table('voucher_usages')->where('voucher_id', $this->record->id)->where('used_at', '>=', $from)
            ->selectRaw('DATE(used_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');

        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $date = $from->copy()->addDays($i);
            $days[] = ['label' => $date->format('d/m'), 'count' => (int) ($rows[$date->toDateString()] ?? 0)];
        }
        $max = max(1, ...array_column($days, 'count'));

        return ['days' => array_map(fn ($d) => $d + ['pct' => $d['count'] ? max(4, (int) round($d['count'] / $max * 100)) : 0], $days), 'max' => $max, 'total' => array_sum(array_column($days, 'count'))];
    }

    public function getRecentUsages(): Collection
    {
        return DB::table('voucher_usages as u')
            ->leftJoin('users', 'users.id', '=', 'u.user_id')
            ->leftJoin('orders as o', 'o.id', '=', 'u.order_id')
            ->where('u.voucher_id', $this->record->id)
            ->orderByDesc('u.used_at')->limit(self::RECENT)
            ->get(['users.name', 'users.phone', 'u.used_at', 'u.order_id', 'o.code as order_code', 'o.status as order_status', 'o.discount_amount'])
            ->map(fn ($r) => (array) $r + ['url' => $r->order_id ? OrderResource::getUrl('view', ['record' => $r->order_id]) : null]);
    }

    /** Điều kiện của mã dưới dạng danh sách dòng, để admin nhìn một chỗ là hiểu. */
    public function getRules(): array
    {
        $v = $this->record;
        $money = fn ($x) => number_format((float) $x, 0, ',', '.').'₫';
        $labels = ['delivery' => static::getResource()::audience() === 'shop' ? 'Giao hàng cửa hàng' : 'Lấy Hộ', 'shopping' => 'Mua Hộ', 'topup' => 'Nạp Tiền', 'bike' => 'Xe Ôm', 'motor' => 'Lái Xe Máy', 'car' => 'Lái Xe Hơi'];

        return array_filter([
            'Dịch vụ' => collect($v->service_types ?? [])->map(fn ($s) => $labels[$s] ?? $s)->implode(', ') ?: 'Tất cả dịch vụ',
            'Khu vực' => $v->city?->name ?? 'Mọi khu vực',
            'Áp dụng riêng' => $v->user ? $v->user->name.' ('.$v->user->phone.')' : null,
            'Phí tối thiểu' => $v->min_order_value ? $money($v->min_order_value) : null,
            'Cự ly tối đa' => $v->max_distance_km ? rtrim(rtrim(number_format($v->max_distance_km, 1, ',', '.'), '0'), ',').' km' : null,
            'Mỗi người' => $v->per_user_limit ? $v->per_user_limit.' lần' : 'Không giới hạn',
            'Tổng lượt' => $v->usage_limit ? number_format($v->usage_limit, 0, ',', '.').' lượt' : 'Không giới hạn',
            'Chỉ đơn đầu tiên' => $v->first_order_only ? 'Có' : null,
            'Hết hạn' => $v->expires_at ? $v->expires_at->format('d/m/Y') : 'Không hết hạn',
        ]);
    }
}
