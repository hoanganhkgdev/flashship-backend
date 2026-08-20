<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn API theo đúng loại tài khoản (user_type) — `auth:sanctum` một mình
 * chỉ xác thực token có hợp lệ hay không, KHÔNG kiểm tra token đó có thuộc
 * đúng loại tài khoản (driver/customer/shop/admin...) được phép gọi route
 * này hay không. Thiếu middleware này, 1 tài khoản bất kỳ (vd customer) đã
 * đăng nhập vẫn gọi được nguyên vẹn API của module khác (vd admin/driver)
 * bằng chính token của họ, miễn route chỉ khai báo `auth:sanctum`.
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$allowedTypes): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->user_type, $allowedTypes, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 403);
        }

        return $next($request);
    }
}
