<?php

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Link đổi khu vực giữ nguyên trang đang xem (Đơn hàng vẫn ở Đơn hàng, giữ cả tab đang chọn)
 * thay vì luôn đưa về trang Tổng quan như mặc định của Filament.
 */
class TenantSwitcher
{
    public static function urlFor(Model $tenant): string
    {
        $fallback = Filament::getUrl($tenant);
        $route = request()->route();
        $name = $route?->getName();

        if (! $route || ! $name || ! str_starts_with($name, 'filament.'.Filament::getCurrentPanel()?->getId().'.')) {
            return $fallback;
        }

        $parameters = $route->parameters();
        if (! array_key_exists('tenant', $parameters)) {
            return $fallback;
        }

        // Trang xem/sửa một bản ghi cụ thể: bản ghi đó thuộc khu vực cũ, nên chuyển về danh sách cùng loại.
        if (count($parameters) > 1) {
            $name = preg_replace('/\.(view|edit|create)$/', '.index', $name);
            $parameters = ['tenant' => $parameters['tenant']];
            if (! Route::has($name) || str_ends_with($name, '.edit') || str_ends_with($name, '.view')) {
                return $fallback;
            }
        }

        try {
            $url = route($name, ['tenant' => $tenant]);
        } catch (\Throwable) {
            return $fallback;
        }

        $query = request()->getQueryString();

        return $query ? $url.'?'.$query : $url;
    }
}
