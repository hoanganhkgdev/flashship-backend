<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Driver\Models\DriverDebt;

class DebtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // unpaid=1: mọi khoản chưa trả (không phân trang). Khoản quá hạn thường là khoản CŨ — lấy 20 khoản mới
        // nhất (gồm cả đã trả) thì đúng chúng rơi khỏi danh sách, app không biết tài xế đang bị khoá nhận đơn.
        if ($request->boolean('unpaid')) {
            $unpaid = DriverDebt::where('driver_id', $request->user()->id)
                ->whereIn('status', ['pending', 'overdue'])->orderBy('created_at')->orderBy('id')->get();

            return response()->json(['success' => true, 'data' => $unpaid, 'meta' => ['has_more' => false, 'total' => $unpaid->count()]]);
        }

        $debts = DriverDebt::where('driver_id', $request->user()->id)
            ->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $debts->items(),
            'meta'    => ['has_more' => $debts->hasMorePages(), 'total' => $debts->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $debt = DriverDebt::where('driver_id', $request->user()->id)->find($id);
        if (!$debt) return response()->json(['success' => false, 'message' => 'Không tìm thấy công nợ'], 404);
        return response()->json(['success' => true, 'data' => $debt]);
    }

}
