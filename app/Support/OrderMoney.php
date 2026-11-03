<?php

namespace App\Support;

/**
 * Công thức tiền của một đơn hoàn thành, viết dạng SQL để dùng trong SUM(). Một nơi duy nhất, khớp với
 * OrderService::completeOrder() — nơi thật sự cộng tiền vào ví tài xế.
 *
 * - shipping_fee ĐÃ GỒM phụ phí đêm và ĐÃ TRỪ voucher (app khách, app shop, đơn gộp, tổng đài đều lưu như vậy);
 *   night_surcharge chỉ ghi phần tách ra để xem, không được cộng thêm lần nữa.
 * - Tài xế thu shipping_fee trực tiếp từ khách. Riêng freeship của tổng đài (không voucher) khách trả 0,
 *   công ty trả nguyên shipping_fee cho tài xế.
 * - bonus_fee, bù voucher (discount_amount) và thưởng mưa là tiền công ty trả thêm cho tài xế — chi phí, không phải doanh thu.
 */
final class OrderMoney
{
    /** Freeship do tổng đài bật: không voucher, không giảm giá — công ty trả nguyên phí ship (xem completeOrder()). */
    public const PLATFORM_FREESHIP = "(is_freeship = 1 AND (voucher_code IS NULL OR voucher_code = '') AND COALESCE(discount_amount, 0) <= 0)";

    /** Tiền khách thật sự trả cho chuyến (đã gồm phụ phí đêm, đã trừ voucher). */
    public const COLLECTED = 'CASE WHEN '.self::PLATFORM_FREESHIP.' THEN 0 ELSE COALESCE(shipping_fee, 0) END';

    /** Phụ phí đêm — đã nằm trong COLLECTED, chỉ để hiển thị phần tách. */
    public const NIGHT_INCLUDED = 'COALESCE(night_surcharge, 0)';

    public const BONUS_COST = 'COALESCE(bonus_fee, 0)';

    public const DISCOUNT_COST = 'COALESCE(discount_amount, 0)';

    public const FREESHIP_COST = 'CASE WHEN '.self::PLATFORM_FREESHIP.' THEN COALESCE(shipping_fee, 0) ELSE 0 END';

    /** Thưởng mưa chỉ trả khi đơn đủ điều kiện; số tiền chưa chốt trên đơn thì lấy mức của khu vực (như completeOrder()). */
    public static function rainCost(int $cityDefault): string
    {
        return 'CASE WHEN rain_bonus_eligible = 1 THEN COALESCE(rain_bonus_amount, '.$cityDefault.') ELSE 0 END';
    }

    /** Thu nhập tài xế của một đơn = khách trả + công ty chi (khớp tiền completeOrder() cộng vào ví). */
    public static function driverEarnings(int $rainDefault): string
    {
        return '('.self::COLLECTED.' + '.self::FREESHIP_COST.' + '.self::BONUS_COST.' + '.self::DISCOUNT_COST.' + '.self::rainCost($rainDefault).')';
    }

    /** Tổng công ty chi cho tài xế ngoài tiền khách trả. */
    public static function platformCost(int $rainDefault): string
    {
        return '('.self::BONUS_COST.' + '.self::DISCOUNT_COST.' + '.self::FREESHIP_COST.' + '.self::rainCost($rainDefault).')';
    }
}
