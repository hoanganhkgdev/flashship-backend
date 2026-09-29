<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * OTP chỉ gửi qua Zalo ZNS (không còn SMS dự phòng) — gửi không được thì
 * báo thẳng cho app thay vì trả "đã gửi" rồi để người dùng chờ mã không bao
 * giờ tới. Tự trả JSON cùng dạng các lỗi khác nên controller không cần bắt.
 */
class OtpDeliveryException extends RuntimeException
{
    public static function fromZaloError(?int $zaloError): self
    {
        return new self(match ($zaloError) {
            -118 => 'Số điện thoại này chưa đăng ký Zalo. FlashShip chỉ gửi mã OTP qua Zalo — vui lòng dùng số điện thoại có Zalo.',
            -108 => 'Số điện thoại không hợp lệ. Vui lòng kiểm tra lại.',
            default => 'Không gửi được mã OTP qua Zalo. Vui lòng thử lại sau ít phút.',
        });
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()], 422);
    }

    /** Đã ghi log ở OtpService — không cần Laravel log thêm stack trace. */
    public function report(): void {}
}
