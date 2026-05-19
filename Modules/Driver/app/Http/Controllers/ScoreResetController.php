<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Driver\Models\DriverScoreResetRequest;
use Modules\Driver\Services\DriverScoreService;

class ScoreResetController extends Controller
{
    const RESET_TARGET    = DriverScoreService::DEFAULT_SCORE; // 80
    const FEE_PER_POINT   = 10_000;

    public function request(Request $request): JsonResponse
    {
        $user  = $request->user();
        $score = (int) ($user->driver_score ?? DriverScoreService::DEFAULT_SCORE);

        if ($score >= self::RESET_TARGET) {
            return response()->json(['success' => false, 'message' => 'Điểm của bạn đã đạt mức tối thiểu, không cần đặt lại.'], 400);
        }

        $hasPending = DriverScoreResetRequest::where('driver_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json(['success' => false, 'message' => 'Bạn đã có yêu cầu đang chờ duyệt.'], 409);
        }

        $pointsToRestore = self::RESET_TARGET - $score;
        $amount          = $pointsToRestore * self::FEE_PER_POINT;

        $req = DriverScoreResetRequest::create([
            'driver_id'        => $user->id,
            'current_score'    => $score,
            'points_to_restore'=> $pointsToRestore,
            'amount'           => $amount,
            'status'           => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Yêu cầu đặt lại điểm đã được gửi.',
            'data'    => [
                'id'               => $req->id,
                'current_score'    => $score,
                'points_to_restore'=> $pointsToRestore,
                'amount'           => $amount,
                'status'           => 'pending',
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $req = DriverScoreResetRequest::where('driver_id', $request->user()->id)
            ->latest()
            ->first();

        return response()->json(['success' => true, 'data' => $req]);
    }
}
