<?php

namespace App\Support;

use Modules\Order\Models\Order;

/** Định dạng hiển thị cho danh sách đơn: địa chỉ rút gọn, khách, SĐT. Chỉ đổi cách hiển thị, không đổi dữ liệu. */
class OrderListPresenter
{
    /** Tỉnh/thành hay lặp ở cuối địa chỉ do Google trả về. */
    private const TAIL_PROVINCES = ['an giang', 'kiên giang', 'cần thơ', 'cà mau', 'bạc liêu', 'hậu giang', 'sóc trăng', 'đồng tháp', 'vĩnh long'];

    /** Bỏ ", Việt Nam", tỉnh và tên khu vực lặp lại ở cuối; luôn giữ lại ít nhất phần đầu địa chỉ. */
    public static function shortAddress(?string $address, ?string $cityName = null): string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return '—';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));
        $norm = fn (string $s) => mb_strtolower($s);

        while (count($parts) > 1 && in_array($norm(end($parts)), ['việt nam', 'vietnam'], true)) {
            array_pop($parts);
        }
        while (count($parts) > 1 && in_array($norm(end($parts)), self::TAIL_PROVINCES, true)) {
            array_pop($parts);
        }
        if ($cityName && count($parts) > 1 && $norm(end($parts)) === $norm($cityName)) {
            array_pop($parts);
        }

        return implode(', ', $parts);
    }

    public static function phone(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if ($d === '') {
            return '';
        }

        return strlen($d) === 10 ? substr($d, 0, 4).' '.substr($d, 4, 3).' '.substr($d, 7) : $d;
    }

    /** SĐT liên hệ của khách tuỳ dịch vụ (cùng quy ước với trang Tổng đài). */
    public static function contactPhone(Order $o): ?string
    {
        if ($o->sender?->phone) {
            return $o->sender->phone;
        }

        return match ($o->service_type) {
            'shopping' => $o->delivery_phone ?: $o->pickup_phone,
            'delivery' => $o->pickup_phone ?: $o->delivery_phone,
            default => $o->pickup_phone ?: $o->delivery_phone,
        };
    }

    public static function customerName(Order $o): string
    {
        return $o->sender?->name ?: ($o->sender_name ?: ($o->receiver_name ?: 'Khách gọi tổng đài'));
    }
}
