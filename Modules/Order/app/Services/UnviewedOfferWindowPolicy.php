<?php

namespace Modules\Order\Services;

use Modules\Core\Services\OperationalSettings;

class UnviewedOfferWindowPolicy
{
    /**
     * Đầu vào phải được sắp từ offer mới nhất đến cũ nhất và chỉ gồm các
     * offer điện thoại đã ACK. Offer hết hạn mà chưa từng mở mới là bỏ lỡ.
     *
     * @return array{total: int, unviewed: int, should_warn: bool, should_offline: bool}
     */
    public static function evaluate(iterable $offers): array
    {
        $total = 0;
        $unviewed = 0;

        foreach ($offers as $offer) {
            if ($total >= OperationalSettings::unviewedWindowSize()) {
                break;
            }

            $total++;
            $viewedAt = is_array($offer) ? ($offer['viewed_at'] ?? null) : ($offer->viewed_at ?? null);
            $result = is_array($offer) ? ($offer['result'] ?? null) : ($offer->result ?? null);

            if ($viewedAt === null && $result === 'expired') {
                $unviewed++;
            }
        }

        return [
            'total' => $total,
            'unviewed' => $unviewed,
            'should_warn' => $unviewed === OperationalSettings::unviewedLimit() - 1,
            'should_offline' => $unviewed >= OperationalSettings::unviewedLimit(),
        ];
    }
}
