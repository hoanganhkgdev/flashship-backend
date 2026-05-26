<?php
namespace Modules\Admin\Http\Controllers;

use App\Services\ZaloTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ZaloTokenController extends Controller
{
    public function show(): JsonResponse
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        if (!$row) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'has_tokens'    => false,
                    'expires_at'    => null,
                    'minutes_left'  => null,
                    'status'        => 'missing',
                ],
            ]);
        }

        $minutesLeft = $row->expires_at ? now()->diffInMinutes($row->expires_at, false) : null;

        $status = match (true) {
            $minutesLeft === null        => 'unknown',
            $minutesLeft <= 0            => 'expired',
            $minutesLeft <= 30           => 'expiring_soon',
            default                      => 'valid',
        };

        return response()->json([
            'success' => true,
            'data'    => [
                'has_tokens'   => true,
                'expires_at'   => $row->expires_at,
                'minutes_left' => $minutesLeft,
                'status'       => $status,
                'updated_at'   => $row->updated_at,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_token'  => 'required|string|min:10',
            'refresh_token' => 'required|string|min:10',
            'expires_in'    => 'nullable|integer|min:60',
        ]);

        $expiresIn = $data['expires_in'] ?? 86400;

        $payload = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at'    => now()->addSeconds($expiresIn),
            'updated_at'    => now(),
        ];

        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        if ($row) {
            DB::table('zalo_tokens')->where('id', $row->id)->update($payload);
        } else {
            DB::table('zalo_tokens')->insert($payload + ['created_at' => now()]);
        }

        $minutesLeft = now()->diffInMinutes(now()->addSeconds($expiresIn), false);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật token thành công',
            'data'    => [
                'expires_at'   => now()->addSeconds($expiresIn),
                'minutes_left' => $minutesLeft,
                'status'       => 'valid',
            ],
        ]);
    }

    public function refresh(): JsonResponse
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Chưa có token trong DB'], 422);
        }

        if (!ZaloTokenService::refresh($row)) {
            return response()->json(['success' => false, 'message' => 'Refresh thất bại. Refresh token có thể đã hết hạn — cần nhập token mới.'], 422);
        }

        $newRow      = DB::table('zalo_tokens')->orderByDesc('id')->first();
        $minutesLeft = $newRow?->expires_at ? now()->diffInMinutes($newRow->expires_at, false) : null;

        return response()->json([
            'success' => true,
            'message' => 'Refresh thành công',
            'data'    => [
                'expires_at'   => $newRow?->expires_at,
                'minutes_left' => $minutesLeft,
                'status'       => 'valid',
            ],
        ]);
    }
}
