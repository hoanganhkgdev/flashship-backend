<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\RTDBService;
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
        $page    = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 10);
        $data = $this->orderService->getCompletedOrders($request->user(), $page, $perPage);
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
            $expiresAt = now()->addSeconds(DispatchService::APP_DECISION_SECS);

            DB::table('orders')->where('id', $order->id)->update([
                'offer_viewed_at' => now(),
                'updated_at'      => now(),
            ]);

            // Ghi thêm vào đúng dòng log offer này (khác cột chung ở trên) —
            // hạ tầng tính % offer bị bỏ lỡ (không mở xem) mỗi ca sau này.
            DB::table('order_dispatch_logs')
                ->where('order_id', $order->id)
                ->where('driver_id', $driver->id)
                ->where('result', 'pending')
                ->whereNull('viewed_at')
                ->update(['viewed_at' => now()]);

            // Reset đồng hồ RTDB về APP_DECISION_SECS — giống ShopeeFood
            RTDBService::updateDriverOfferExpiry($driver->id, $expiresAt->timestamp);

            // Job timeout tính từ lúc driver MỞ APP, dùng APP_DECISION_SECS (30s)
            DispatchOrderJob::dispatch($order->id, $driver->id, true)
                ->delay($expiresAt);
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

    /**
     * Driver đánh dấu đã giao 1 điểm trong đơn gộp.
     * Khi tất cả stops delivered → tự hoàn thành đơn.
     */
    public function deliverStop(Request $request, Order $order, int $seq): JsonResponse
    {
        $driver = $request->user();

        if ((int) $order->delivery_man_id !== $driver->id) {
            return response()->json(['success' => false, 'message' => 'Không phải đơn của bạn'], 403);
        }
        if (!$order->is_batch) {
            return response()->json(['success' => false, 'message' => 'Không phải đơn gộp'], 400);
        }

        // Khoá + đọc lại bản mới nhất TRONG transaction — nếu không, 2 request
        // bấm "Đã giao" liên tiếp nhanh cho 2 điểm khác nhau sẽ cùng đọc bản
        // stops cũ, mỗi request sửa xong ghi đè toàn bộ mảng, request chạy
        // sau xoá mất dấu "đã giao" mà request trước vừa đánh dấu.
        $error = null;
        $fresh = null;
        $stops = [];
        DB::transaction(function () use ($order, $seq, &$error, &$fresh, &$stops) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->first();
            $stops = $fresh->stops ?? [];
            $found = false;
            foreach ($stops as &$stop) {
                if ((int) $stop['seq'] === $seq) {
                    if ($stop['delivered_at'] !== null) {
                        $error = ['status' => 400, 'message' => 'Điểm này đã được giao rồi'];
                        return;
                    }
                    $stop['delivered_at'] = now()->toIso8601String();
                    $found = true;
                    break;
                }
            }
            unset($stop);
            if (!$found) {
                $error = ['status' => 404, 'message' => 'Không tìm thấy điểm giao'];
                return;
            }
            $fresh->update(['stops' => $stops]);
        });

        if ($error) {
            return response()->json(['success' => false, 'message' => $error['message']], $error['status']);
        }

        // Nếu tất cả stops đã delivered → hoàn thành đơn
        $allDone = collect($stops)->every(fn($s) => $s['delivered_at'] !== null);
        if ($allDone) {
            $this->orderService->completeOrder($fresh, $driver);
            return response()->json([
                'success'   => true,
                'message'   => "Đã giao điểm $seq — Tất cả điểm đã giao, đơn hoàn thành!",
                'completed' => true,
                'stops'     => $stops,
            ]);
        }

        $remaining = collect($stops)->filter(fn($s) => $s['delivered_at'] === null)->count();
        return response()->json([
            'success'   => true,
            'message'   => "Đã giao điểm $seq — còn $remaining điểm",
            'completed' => false,
            'stops'     => $stops,
        ]);
    }
}
