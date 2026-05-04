<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Services\DriverWalletService;

class DebtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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

    public function payWithWallet(Request $request, int $id): JsonResponse
    {
        $debt = DriverDebt::where('driver_id', $request->user()->id)
            ->where('status', 'pending')->find($id);

        if (!$debt) return response()->json(['success' => false, 'message' => 'Không tìm thấy công nợ'], 404);

        $remaining = $debt->amount_due - $debt->amount_paid;

        try {
            DriverWalletService::adjust($request->user()->id, $remaining, 'debit', "Thanh toán công nợ #{$debt->id}", "debt_{$debt->id}");
            $debt->update(['amount_paid' => $debt->amount_due, 'status' => 'paid']);
            return response()->json(['success' => true, 'message' => 'Thanh toán công nợ thành công']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
