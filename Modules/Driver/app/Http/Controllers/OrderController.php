<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function pendingOffer(Request $request): JsonResponse
    {
        $driverId = $request->user()->id;

        $log = OrderDispatchLog::where('driver_id', $driverId)
            ->where('result', 'pending')
            ->latest('offered_at')
            ->first();

        $order = null;
        if ($log) {
            $order = Order::with('city')
                ->where('id', $log->order_id)
                ->where('status', 'pending')
                ->first();

            if (!$order) {
                $log->update(['result' => 'expired']);
            }
        }

        return response()->json(['success' => true, 'data' => ['order' => $order]]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $data = $this->orderService->getDriverOrders($request->user());
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function completedOrders(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $data = $this->orderService->getCompletedOrders($request->user(), $page);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $stats = $this->orderService->getDashboardStats($request->user());
        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function viewOffer(Request $request, Order $order): JsonResponse
    {
        $driver = $request->user();

        // Chỉ xử lý nếu đơn đang pending và đang được phát cho driver này
        if ($order->status !== 'pending' || (int) $order->dispatching_to_driver_id !== $driver->id) {
            return response()->json(['success' => false], 200);
        }

        // Đặt offer_viewed_at để callkit-timeout job biết driver đã mở app
        if ($order->offer_viewed_at === null) {
            DB::table('orders')->where('id', $order->id)->update([
                'offer_viewed_at' => now(),
                'updated_at'      => now(),
            ]);

            // Dispatch job mới đếm 30s từ lúc driver MỞ APP (không phải từ lúc dispatch)
            DispatchOrderJob::dispatch($order->id, $driver->id, true)
                ->delay(now()->addSeconds(app(DispatchService::class)::DRIVER_OFFER_SECS));
        }

        return response()->json(['success' => true], 200);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $result = $this->orderService->acceptOrder($order, $request->user());
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }

    public function decline(Request $request, Order $order): JsonResponse
    {
        $result = $this->orderService->declineOrder($order, $request->user());
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data   = $request->validate(['status' => 'required|in:processing,on_the_way']);
        $result = $this->orderService->updateOrderStatus($order, $request->user(), $data['status']);
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        $result = $this->orderService->completeOrder($order, $request->user());
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }
}
