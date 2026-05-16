<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Order\Services\OrderService;

class EarningController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function weekly(Request $request): JsonResponse
    {
        $data = $this->orderService->getWeeklyEarnings($request->user()->id);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $data = $this->orderService->getMonthlyEarnings($request->user()->id);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function kpi(Request $request): JsonResponse
    {
        $data = $this->orderService->getKpi($request->user());
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function summary(Request $request): JsonResponse
    {
        $id = $request->user()->id;
        return response()->json([
            'success' => true,
            'data'    => [
                'weekly'  => $this->orderService->getEarningsSummary($id, 'week'),
                'monthly' => $this->orderService->getEarningsSummary($id, 'month'),
            ],
        ]);
    }

    public function recentOrders(Request $request): JsonResponse
    {
        $data = $this->orderService->getRecentOrders($request->user()->id);
        return response()->json(['success' => true, 'data' => $data]);
    }
}
