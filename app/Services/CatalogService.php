<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\City;
use Modules\Core\Models\ConfigLog;
use Modules\Core\Models\ServiceType;

/** Sức khỏe khu vực, độ phủ giá theo dịch vụ, bật/tắt có nhật ký. */
class CatalogService
{
    public static function log(string $entity, int $id, string $action, ?int $by, ?string $detail = null): void
    {
        ConfigLog::create(['entity' => $entity, 'entity_id' => $id, 'action' => $action, 'performed_by' => $by, 'detail' => $detail]);
    }

    /** Ghi các thay đổi giữa hai bản dữ liệu (chỉ các trường được theo dõi). */
    public static function logChanges(string $entity, int $id, array $before, array $after, array $labels, ?int $by): void
    {
        $diff = [];
        foreach ($labels as $key => $label) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ((string) $a !== (string) $b) {
                $diff[] = $label.': '.($a === null || $a === '' ? '—' : (is_bool($a) ? ($a ? 'bật' : 'tắt') : $a)).' → '.($b === null || $b === '' ? '—' : (is_bool($b) ? ($b ? 'bật' : 'tắt') : $b));
            }
        }
        if ($diff) {
            self::log($entity, $id, 'updated', $by, implode('; ', $diff));
        }
    }

    public static function normalizeSlug(string $slug, string $fallbackName = ''): string
    {
        return Str::slug($slug !== '' ? $slug : $fallbackName);
    }

    /** Dịch vụ đang hiển thị: key => nhãn. */
    public static function activeServices(): Collection
    {
        return ServiceType::active()->get(['key', 'label', 'sort_order'])->mapWithKeys(fn ($s) => [$s->key => $s->label]);
    }

    /** Ma trận giá: [city_id][service_key] = true nếu có bảng giá đang hoạt động. */
    public static function pricingMatrix(): array
    {
        $m = [];
        foreach (DB::table('pricing_configs')->where('is_active', true)->whereNotNull('city_id')->get(['city_id', 'service_type']) as $r) {
            $m[(int) $r->city_id][$r->service_type] = true;
        }

        return $m;
    }

    /** Sức khỏe từng khu vực, theo id. */
    public static function health(): Collection
    {
        $cities = City::orderBy('name')->get();
        $services = self::activeServices();
        $matrix = self::pricingMatrix();

        $orders = DB::table('orders')->whereNotNull('city_id')
            ->selectRaw('city_id, COUNT(*) total, SUM(created_at >= ?) d30, SUM(DATE(created_at) = CURDATE()) today', [now()->subDays(30)])
            ->groupBy('city_id')->get()->keyBy('city_id');
        $users = DB::table('users')->whereIn('user_type', ['driver', 'customer', 'shop'])->whereNotNull('city_id')
            ->selectRaw("city_id, SUM(user_type='driver' AND status=1) drivers, SUM(user_type='driver' AND status=1 AND is_online=1) online, SUM(user_type='customer') customers, SUM(user_type='shop') shops")
            ->groupBy('city_id')->get()->keyBy('city_id');
        $shifts = DB::table('shifts')->selectRaw('city_id, COUNT(*) total, SUM(is_active=1) active')->groupBy('city_id')->get()->keyBy('city_id');

        return $cities->mapWithKeys(function (City $c) use ($orders, $users, $shifts, $services, $matrix) {
            $o = $orders[$c->id] ?? null;
            $u = $users[$c->id] ?? null;
            $s = $shifts[$c->id] ?? null;
            $priced = $services->keys()->filter(fn ($k) => $matrix[$c->id][$k] ?? false);
            $missing = $services->keys()->diff($priced)->map(fn ($k) => $services[$k])->values();

            $h = [
                'city' => $c,
                'orders30' => (int) ($o->d30 ?? 0), 'ordersToday' => (int) ($o->today ?? 0), 'ordersTotal' => (int) ($o->total ?? 0),
                'drivers' => (int) ($u->drivers ?? 0), 'online' => (int) ($u->online ?? 0),
                'customers' => (int) ($u->customers ?? 0), 'shops' => (int) ($u->shops ?? 0),
                'shifts' => (int) ($s->total ?? 0), 'activeShifts' => (int) ($s->active ?? 0),
                'priced' => $priced->count(), 'services' => $services->count(), 'missing' => $missing,
            ];
            $h['flags'] = self::flags($c, $h);

            return [$c->id => $h];
        });
    }

    /** @return array<int, array{key:string,label:string,level:string}> */
    public static function flags(City $c, array $h): array
    {
        $flags = [];
        if ($c->is_test) {
            $flags[] = ['key' => 'test', 'label' => 'Khu vực thử (ẩn khỏi app)', 'level' => 'info'];
        }
        if (! $c->is_active && ($h['customers'] + $h['shops'] + $h['drivers']) > 0) {
            $flags[] = ['key' => 'inactive_data', 'label' => 'Đã tắt nhưng còn '.$h['customers'].' khách, '.$h['shops'].' shop, '.$h['drivers'].' tài xế', 'level' => 'warning'];
        }
        if ($c->is_active && ($c->lat === null || $c->lng === null)) {
            $flags[] = ['key' => 'no_coords', 'label' => 'Thiếu tọa độ', 'level' => 'warning'];
        }
        if ($c->is_active && $h['drivers'] > 0 && $h['activeShifts'] === 0) {
            $flags[] = ['key' => 'no_shift', 'label' => 'Chưa có ca nào đang bật', 'level' => 'warning'];
        }
        if ($c->is_active && ! $c->is_test && $h['missing']->isNotEmpty()) {
            $flags[] = ['key' => 'missing_price', 'label' => 'Thiếu giá: '.$h['missing']->implode(', '), 'level' => 'danger'];
        }
        if ($c->is_active && ! $c->is_test && $h['drivers'] > 0 && (int) $c->weekly_fee === 0) {
            $flags[] = ['key' => 'zero_fee', 'label' => 'Phí tuần 0₫ dù có tài xế', 'level' => 'info'];
        }

        return $flags;
    }

    /** Số người bị ảnh hưởng nếu tắt khu vực. */
    public static function cityImpact(City $c): array
    {
        $u = DB::table('users')->where('city_id', $c->id)
            ->selectRaw("SUM(user_type='customer') customers, SUM(user_type='shop') shops, SUM(user_type='driver' AND status=1) drivers")->first();

        return ['customers' => (int) $u->customers, 'shops' => (int) $u->shops, 'drivers' => (int) $u->drivers];
    }

    public static function setCityActive(City $c, bool $active, ?int $by): void
    {
        $impact = self::cityImpact($c);
        $c->update(['is_active' => $active]);
        self::log('city', $c->id, $active ? 'activated' : 'deactivated', $by,
            $impact['customers'].' khách, '.$impact['shops'].' shop, '.$impact['drivers'].' tài xế hoạt động');
    }

    public static function serviceOrders(): array
    {
        $rows = DB::table('orders')->where('created_at', '>=', now()->subDays(30))->selectRaw('service_type, COUNT(*) c')->groupBy('service_type')->pluck('c', 'service_type');

        return ['by' => $rows->all(), 'total' => (int) $rows->sum()];
    }

    public static function setServiceActive(ServiceType $s, bool $active, ?int $by): int
    {
        $d30 = (int) DB::table('orders')->where('service_type', $s->key)->where('created_at', '>=', now()->subDays(30))->count();
        $s->update(['is_active' => $active]);
        self::log('service_type', $s->id, $active ? 'activated' : 'deactivated', $by, $d30.' đơn trong 30 ngày');

        return $d30;
    }

    /** @return Collection<int, ConfigLog> */
    public static function logs(string $entity, int $id, int $limit = 15): Collection
    {
        return ConfigLog::where('entity', $entity)->where('entity_id', $id)->latest('id')->limit($limit)->get();
    }
}
