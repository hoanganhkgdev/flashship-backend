<?php

namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Core\Services\OperationalSettings;

class UnviewedOfferWindowPolicy
{
    /**
     * Đầu vào phải được sắp từ offer mới nhất đến cũ nhất và chỉ gồm các
     * offer điện thoại đã ACK. Offer hết hạn mà chưa từng mở mới là bỏ lỡ.
     *
     * $cityId là khu vực của tài xế — cửa sổ và giới hạn theo cấu hình khu vực đó.
     *
     * @return array{total: int, unviewed: int, should_warn: bool, should_offline: bool}
     */
    public static function evaluate(iterable $offers, ?int $cityId): array
    {
        $windowSize = OperationalSettings::unviewedWindowSize($cityId);
        $limit = OperationalSettings::unviewedLimit($cityId);
        $total = 0;
        $unviewed = 0;

        foreach ($offers as $offer) {
            if ($total >= $windowSize) {
                break;
            }

            $viewedAt = is_array($offer) ? ($offer['viewed_at'] ?? null) : ($offer->viewed_at ?? null);
            $result = is_array($offer) ? ($offer['result'] ?? null) : ($offer->result ?? null);
            $expiresAt = is_array($offer) ? ($offer['expires_at'] ?? null) : ($offer->expires_at ?? null);
            $respondedAt = is_array($offer) ? ($offer['responded_at'] ?? null) : ($offer->responded_at ?? null);

            // "expired" còn được dùng khi người khác trong wave nhận trước,
            // tài xế vừa bận/không đủ điều kiện hoặc lỗi hạ tầng. Những offer
            // bị đóng trước deadline không phải một lần bỏ lỡ và cũng không
            // được chiếm chỗ trong cửa sổ đánh giá.
            if ($result === 'expired' && ! self::expiredAtDeadline($expiresAt, $respondedAt)) {
                continue;
            }

            $total++;

            if ($viewedAt === null && $result === 'expired') {
                $unviewed++;
            }
        }

        return [
            'total' => $total,
            'unviewed' => $unviewed,
            'should_warn' => $unviewed === $limit - 1,
            'should_offline' => $unviewed >= $limit,
        ];
    }

    private static function expiredAtDeadline(mixed $expiresAt, mixed $respondedAt): bool
    {
        if (! $expiresAt || ! $respondedAt) {
            // Không đủ bằng chứng thì không phạt tài xế.
            return false;
        }

        return Carbon::parse($respondedAt)->greaterThanOrEqualTo(Carbon::parse($expiresAt));
    }
}
