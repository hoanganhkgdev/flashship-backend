<?php

namespace Modules\Order\Services;

final class DispatchOfferCooldownPolicy
{
    public const DECLINED_SECONDS = 60;

    public const SINGLE_EXPIRED_SECONDS = 90;

    public const REPEATED_EXPIRED_SECONDS = 300;

    /**
     * @param  array<int, string>  $results  Newest result first.
     */
    public static function seconds(array $results): int
    {
        $latest = $results[0] ?? null;

        if ($latest === 'declined') {
            return self::DECLINED_SECONDS;
        }

        if ($latest !== 'expired') {
            return 0;
        }

        return ($results[1] ?? null) === 'expired'
            ? self::REPEATED_EXPIRED_SECONDS
            : self::SINGLE_EXPIRED_SECONDS;
    }
}
