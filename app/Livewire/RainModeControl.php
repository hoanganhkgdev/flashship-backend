<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Modules\Core\Models\City;

/**
 * Nút "Chế độ trời mưa" trên thanh header, cạnh avatar user — bật/tắt theo
 * đúng khu vực (tenant) đang đứng. Admin muốn điều khiển thành phố khác thì
 * chuyển tenant qua bộ chọn khu vực trên topbar. Các user_type khác không
 * thấy component này (ẩn hẳn, xem canManage()).
 *
 * Bật lên: mỗi đơn tài xế NHẬN trong lúc đang bật được cộng thêm 5.000đ vào
 * ví lúc hoàn thành (xem OrderService::completeOrder(), rain_bonus_eligible
 * được chụp lại ngay lúc nhận đơn — không đổi theo trạng thái mưa hiện tại
 * nữa dù tắt/bật lại giữa chừng), đồng thời tạm miễn phạt điểm "lơ đơn" cho
 * thành phố đó (xem DispatchService::trackMissedOffer()).
 */
class RainModeControl extends Component
{
    public function canManage(): bool
    {
        $user = auth()->user();
        return in_array($user?->user_type, ['admin', 'city_manager', 'call_center']);
    }

    public function getCitiesProperty()
    {
        if (!auth()->user()) return collect();

        // Chỉ điều khiển đúng khu vực (tenant) đang đứng — đổi khu vực thì
        // dùng bộ chuyển tenant trên topbar, không cần danh sách riêng ở đây.
        $tenantId = \Filament\Facades\Filament::getTenant()?->id;
        if (!$tenantId) return collect();

        return City::where('id', $tenantId)->get(['id', 'name', 'is_rain_mode', 'rain_mode_started_at']);
    }

    public function toggleCity(int $cityId): void
    {
        $user = auth()->user();
        if (!$this->canManage()) return;

        // Chặn cứng phía server: chỉ được đụng đúng khu vực (tenant) đang
        // đứng, không chỉ ẩn UI (UI có thể bị qua mặt).
        if (\Filament\Facades\Filament::getTenant()?->id !== $cityId) {
            return;
        }

        $city = City::find($cityId);
        if (!$city) return;

        if ($city->is_rain_mode) {
            $city->update([
                'is_rain_mode'          => false,
                'rain_mode_started_at'  => null,
                'rain_mode_by'          => null,
            ]);
        } else {
            $city->update([
                'is_rain_mode'          => true,
                'rain_mode_started_at'  => now(),
                'rain_mode_by'          => $user->id,
            ]);
        }
    }

    public function render()
    {
        return view('livewire.rain-mode-control');
    }
}
