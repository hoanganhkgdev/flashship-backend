<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn API ngay khi tài xế bị khoá (status=2) hoặc đang chờ xoá tài khoản —
 * trước đây chỉ chặn được ở bước đăng nhập, còn token đã cấp trước đó vẫn
 * gọi được mọi API bình thường cho tới khi app tự nhận tín hiệu RTDB và
 * đăng xuất (best-effort phía client, không phải chặn cứng phía server).
 * Thu hồi token luôn để lần gọi kế tiếp không cần đi qua middleware này nữa.
 */
class EnsureDriverAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (int) $user->status === 2) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã bị khóa',
                'code'    => 'account_locked',
            ], 403);
        }

        if ($user?->delete_requested_at) {
            $allowed = $request->is('api/auth/me')
                || $request->is('api/auth/logout')
                || ($request->is('api/driver/profile') && $request->isMethod('GET'))
                || $request->is('api/driver/delete-account/cancel');

            if (!$allowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản đang chờ xóa. Hãy hủy yêu cầu để tiếp tục sử dụng.',
                    'code'    => 'account_delete_pending',
                ], 403);
            }
        }

        // Tài khoản vừa đăng ký cần giữ phiên để theo dõi xét duyệt và nộp
        // giấy tờ, nhưng tuyệt đối chưa được dùng đơn hàng, ví, GPS/online,
        // ca hoặc điểm. Trước đây token trả về sau đăng ký đi xuyên qua toàn
        // bộ API vì middleware chỉ chặn status=2.
        if ($user && (int) $user->status === 0) {
            $allowed = $request->is('api/auth/me')
                || $request->is('api/auth/logout')
                || $request->is('api/auth/firebase-token')
                || ($request->is('api/driver/profile') && $request->isMethod('GET'))
                || $request->is('api/driver/profile/license')
                || $request->is('api/driver/profile/cccd-image');

            if (!$allowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản đang chờ admin duyệt',
                    'code'    => 'account_pending',
                ], 403);
            }
        }

        return $next($request);
    }
}
