<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderMarketService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService, private OrderMarketService $orderMarket) {}

    public function market(Request $request): JsonResponse
    {
        $driver = $request->user();

        return response()->json([
            'success' => true,
            'market_enabled' => true,
            'city_id' => (int) $driver->city_id,
            'data' => $this->orderMarket->listForDriver($driver),
        ]);
    }

    public function claimMarket(Request $request, Order $order): JsonResponse
    {
        $result = $this->orderMarket->claim($order, $request->user());
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }

    /**
     * Không còn offer hệ thống (chỉ dùng Chợ đơn). Giữ endpoint để các bản app
     * cũ đang đồng bộ offer không gặp 404 — luôn trả về "không có offer".
     */
    public function pendingOffer(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['offer' => null, 'order' => null]]);
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

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        // Mốc cập nhật duy nhất trước khi hoàn thành là processing (đã lấy hàng).
        $data   = $request->validate(['status' => 'required|in:processing']);
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

        $requestedStop = collect($order->stops ?? [])->first(
            fn ($stop) => (int) ($stop['seq'] ?? 0) === $seq
        );
        if (! $requestedStop) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy điểm giao'], 404);
        }
        // Đơn gộp không kiểm tra khoảng cách tới điểm giao: tài xế tự bấm "Đã giao".

        // Khoá + đọc lại bản mới nhất TRONG transaction — nếu không, 2 request
        // bấm "Đã giao" liên tiếp nhanh cho 2 điểm khác nhau sẽ cùng đọc bản
        // stops cũ, mỗi request sửa xong ghi đè toàn bộ mảng, request chạy
        // sau xoá mất dấu "đã giao" mà request trước vừa đánh dấu.
        $error = null;
        $fresh = null;
        $stops = [];
        DB::transaction(function () use ($order, $seq, &$error, &$fresh, &$stops) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->first();
            if ($fresh->status !== 'processing') {
                $error = ['status' => 400, 'message' => 'Bạn cần xác nhận đã lấy hàng trước khi giao các điểm.'];
                return;
            }
            $stops = $fresh->stops ?? [];
            $found = false;
            foreach ($stops as $index => &$stop) {
                if ((int) $stop['seq'] === $seq) {
                    if (($stop['delivered_at'] ?? null) !== null) {
                        $error = ['status' => 400, 'message' => 'Điểm này đã được giao rồi'];
                        return;
                    }
                    $hasUndeliveredBefore = collect(array_slice($stops, 0, $index))
                        ->contains(fn ($previous) => ($previous['delivered_at'] ?? null) === null);
                    if ($hasUndeliveredBefore) {
                        $error = ['status' => 409, 'message' => 'Bạn cần giao các điểm trước theo đúng thứ tự.'];
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
        $allDone = count($stops) > 0 && collect($stops)->every(fn($s) => ($s['delivered_at'] ?? null) !== null);
        if ($allDone) {
            $completion = $this->orderService->completeOrder($fresh, $driver, true);
            if (! $completion['success']) {
                return response()->json(
                    ['success' => false, 'message' => $completion['message']],
                    $completion['status'],
                );
            }
            return response()->json([
                'success'   => true,
                'message'   => "Đã giao điểm $seq — Tất cả điểm đã giao, đơn hoàn thành!",
                'completed' => true,
                'stops'     => $stops,
            ]);
        }

        $remaining = collect($stops)->filter(fn($s) => ($s['delivered_at'] ?? null) === null)->count();
        return response()->json([
            'success'   => true,
            'message'   => "Đã giao điểm $seq — còn $remaining điểm",
            'completed' => false,
            'stops'     => $stops,
        ]);
    }
}
