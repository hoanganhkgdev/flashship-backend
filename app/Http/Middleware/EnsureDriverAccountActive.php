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

        if ($user && ((int) $user->status === 2 || $user->delete_requested_at)) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => $user->delete_requested_at
                    ? 'Tài khoản đang chờ xóa'
                    : 'Tài khoản đã bị khóa',
                'code'    => $user->delete_requested_at ? 'account_delete_pending' : 'account_locked',
            ], 403);
        }

        return $next($request);
    }
}
