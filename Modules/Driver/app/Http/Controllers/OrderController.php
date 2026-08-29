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
use Modules\Order\Services\DispatchOfferSender;
use Modules\Order\Services\OrderService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function pendingOffer(Request $request): JsonResponse
    {
        $driverId = $request->user()->id;

        // orders.dispatching_to_driver_id mới là con trỏ offer hiện hành.
        // Không lấy "log pending mới nhất": log là lịch sử và nếu callback
        // timeout/afterResponse từng lỗi, một dòng cũ có thể còn pending rồi
        // làm app khôi phục nhầm đơn đã phát trước đó.
        $order = Order::with('city')
            ->where('status', 'pending')
            ->where('dispatching_to_driver_id', $driverId)
            ->latest('updated_at')
            ->first();

        $activeLog = $order
            ? OrderDispatchLog::where('order_id', $order->id)
                ->where('driver_id', $driverId)
                ->where('result', 'pending')
                ->exists()
            : false;

        // Tự chữa lịch sử của riêng tài xế mỗi lần app đồng bộ offer. Chỉ
        // giữ đúng log khớp đồng thời cả order + driver + trạng thái pending.
        OrderDispatchLog::where('driver_id', $driverId)
            ->where('result', 'pending')
            ->when($order && $activeLog, fn ($query) => $query->where('order_id', '!=', $order->id))
            ->update(['result' => 'expired', 'responded_at' => now()]);

        if (!$activeLog) {
            $order = null;
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

        // Cập nhật có điều kiện trong một câu SQL: request xem cũ đến
        // muộn không được reset offer của tài xế mới; hai request trùng
        // cũng chỉ có đúng một request được gia hạn 30 giây.
        $viewedAt = now();
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->where('dispatching_to_driver_id', $driver->id)
            ->whereNull('offer_viewed_at')
            ->update([
                'offer_viewed_at' => now(),
                'updated_at'      => $viewedAt,
            ]);

        $effectiveExpiresAt = null;
        if ($updated) {
            $expiresAt = $viewedAt->copy()->addSeconds(DispatchOfferSender::APP_DECISION_SECS);
            $effectiveExpiresAt = $expiresAt->timestamp;

            // Ghi thêm vào đúng dòng log offer này (khác cột chung ở trên) —
            // hạ tầng tính % offer bị bỏ lỡ (không mở xem) mỗi ca sau này.
            DB::table('order_dispatch_logs')
                ->where('order_id', $order->id)
                ->where('driver_id', $driver->id)
                ->where('result', 'pending')
                ->whereNull('viewed_at')
                ->update(['received_at' => DB::raw('COALESCE(received_at, CURRENT_TIMESTAMP)'), 'viewed_at' => $viewedAt]);

            // Đã thật sự mở xem thì chuỗi "nhận nhưng không xem" bị ngắt.
            DB::table('users')->where('id', $driver->id)->update(['unviewed_offer_count' => 0]);

            // Reset đồng hồ RTDB về APP_DECISION_SECS — giống ShopeeFood
            RTDBService::updateDriverOfferExpiry($driver->id, $order->id, $expiresAt->timestamp);

            // Job timeout tính từ lúc driver MỞ APP, dùng APP_DECISION_SECS (30s)
            DispatchOrderJob::dispatch($order->id, $driver->id, true)
                ->delay($expiresAt);
        } else {
            // Request retry: trả lại đúng deadline đã cấp ở request đầu,
            // không tự cộng thêm 30 giây lần nữa.
            $fresh = Order::where('id', $order->id)
                ->where('status', 'pending')
                ->where('dispatching_to_driver_id', $driver->id)
                ->first();
            if ($fresh?->offer_viewed_at) {
                $effectiveExpiresAt = $fresh->offer_viewed_at
                    ->copy()
                    ->addSeconds(DispatchOfferSender::APP_DECISION_SECS)
                    ->timestamp;
            }
        }

        return response()->json([
            'success' => true,
            'data' => ['expires_at' => $effectiveExpiresAt],
        ], 200);
    }

    /** App xác nhận thiết bị đã nhận và xử lý thông báo offer. */
    public function receiveOffer(Request $request, Order $order): JsonResponse
    {
        $driverId = (int) $request->user()->id;

        $updated = DB::table('order_dispatch_logs')
            ->where('order_id', $order->id)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->whereNull('received_at')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.id', 'order_dispatch_logs.order_id')
                ->where('orders.status', 'pending')
                ->where('orders.dispatching_to_driver_id', $driverId))
            ->update(['received_at' => now(), 'updated_at' => now()]);

        // Idempotent: retry sau ACK đầu vẫn thành công nếu offer còn hiệu lực.
        $active = $updated > 0 || DB::table('order_dispatch_logs')
            ->where('order_id', $order->id)
            ->where('driver_id', $driverId)
            ->where('result', 'pending')
            ->whereNotNull('received_at')
            ->exists();

        return response()->json(['success' => $active], $active ? 200 : 409);
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
            $this->orderService->completeOrder($fresh, $driver);
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
