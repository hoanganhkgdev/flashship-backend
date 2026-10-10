<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\RTDBService;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderMarketService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService, private OrderMarketService $orderMarket) {}

    public function market(Request $request): JsonResponse
    {
        $driver = $request->user();
        $enabled = OperationalSettings::orderMarketEnabled($driver->city_id);

        return response()->json([
            'success' => true,
            'market_enabled' => $enabled,
            'city_id' => (int) $driver->city_id,
            'bundle_session' => $enabled ? $this->orderMarket->bundleSession($driver) : null,
            'data' => $enabled ? $this->orderMarket->listForDriver($driver) : [],
        ]);
    }

    public function claimMarket(Request $request, Order $order): JsonResponse
    {
        $result = $this->orderMarket->claim($order, $request->user());
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }

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

    public function receiveSignedOffer(OrderDispatchLog $dispatchLog): JsonResponse
    {
        $updated = DB::table('order_dispatch_logs')
            ->where('id', $dispatchLog->id)
            ->where('result', 'pending')
            ->whereNull('received_at')
            ->update(['received_at' => now(), 'updated_at' => now()]);

        $received = $updated > 0 || DB::table('order_dispatch_logs')
            ->where('id', $dispatchLog->id)
            ->whereNotNull('received_at')
            ->exists();

        return response()->json(['success' => $received], $received ? 200 : 409);
    }

    /** Popup Android báo tài xế đã chủ động bấm xem trước khi Flutter mở xong. */
    public function viewSignedOffer(OrderDispatchLog $dispatchLog): JsonResponse
    {
        $view = $this->markOfferViewed(
            (int) $dispatchLog->order_id,
            (int) $dispatchLog->driver_id,
            (int) $dispatchLog->id,
        );
        if (! $view) {
            return response()->json(['success' => false], 409);
        }

        if ($view['updated']) {
            RTDBService::updateDriverOfferExpiry(
                (int) $dispatchLog->driver_id,
                (int) $dispatchLog->order_id,
                $view['expires_at']->timestamp,
            );
            DispatchOrderJob::dispatch($dispatchLog->order_id, $dispatchLog->driver_id, true)
                ->delay($view['expires_at']);
        }

        return response()->json([
            'success' => true,
            'data' => ['expires_at' => $view['expires_at']->timestamp],
        ]);
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
        $view = $this->markOfferViewed((int) $order->id, (int) $driver->id);
        if (! $view) {
            return response()->json(['success' => false], 200);
        }

        if ($view['updated']) {
            RTDBService::updateDriverOfferExpiry($driver->id, $order->id, $view['expires_at']->timestamp);
            DispatchOrderJob::dispatch($order->id, $driver->id, true)
                ->delay($view['expires_at']);
        }

        return response()->json([
            'success' => true,
            'data' => ['expires_at' => $view['expires_at']->timestamp],
        ], 200);
    }

    /**
     * @return array{updated: bool, expires_at: \Illuminate\Support\Carbon}|null
     */
    private function markOfferViewed(int $orderId, int $driverId, ?int $dispatchLogId = null): ?array
    {
        return DB::transaction(function () use ($orderId, $driverId, $dispatchLogId) {
            $log = OrderDispatchLog::query()
                ->when($dispatchLogId, fn ($query) => $query->whereKey($dispatchLogId))
                ->where('order_id', $orderId)
                ->where('driver_id', $driverId)
                ->where('result', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $log || ($log->expires_at && $log->expires_at->isPast())) {
                return null;
            }

            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'pending'
                || (int) $order->dispatching_to_driver_id !== $driverId) {
                return null;
            }

            if ($order->offer_viewed_at) {
                return [
                    'updated' => false,
                    'expires_at' => $log->expires_at
                        ?? $order->offer_viewed_at->copy()->addSeconds(OperationalSettings::offerDecisionSeconds($order->city_id)),
                ];
            }

            $viewedAt = now();
            $expiresAt = $viewedAt->copy()->addSeconds(OperationalSettings::offerDecisionSeconds($order->city_id));
            $order->forceFill(['offer_viewed_at' => $viewedAt, 'updated_at' => $viewedAt])->save();
            $log->update([
                'received_at' => $log->received_at ?? $viewedAt,
                'viewed_at' => $viewedAt,
                'expires_at' => $expiresAt,
                'updated_at' => $viewedAt,
            ]);

            return ['updated' => true, 'expires_at' => $expiresAt];
        });
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

        $requestedStop = collect($order->stops ?? [])->first(
            fn ($stop) => (int) ($stop['seq'] ?? 0) === $seq
        );
        if (! $requestedStop) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy điểm giao'], 404);
        }
        $proximityError = $this->orderService->checkDriverProximity(
            $driver,
            $requestedStop['lat'] ?? null,
            $requestedStop['lng'] ?? null,
            $order->city_id,
        );
        if ($proximityError) {
            $status = $proximityError['status'];
            unset($proximityError['status']);

            return response()->json($proximityError, $status);
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
