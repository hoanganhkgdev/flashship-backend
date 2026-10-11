<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Services\RTDBService;
use Modules\Core\Services\OperationalSettings;
use Modules\Core\Models\User;
use Modules\Order\Jobs\DispatchOrderJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderMarketService;
use Modules\Order\Services\DispatchOfferPayload;
use Modules\Order\Services\DispatchService;

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
        $driverId = (int) $request->user()->id;
        $state = DB::transaction(function () use ($driverId) {
            User::whereKey($driverId)->lockForUpdate()->firstOrFail();
            $orderIds = OrderDispatchLog::where('driver_id', $driverId)
                ->where('result', 'pending')->pluck('order_id');
            $orders = Order::whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $logs = OrderDispatchLog::where('driver_id', $driverId)
                ->where('result', 'pending')
                ->orderByDesc('offered_at')
                ->lockForUpdate()
                ->get();
            $active = null;
            $due = [];
            $stale = [];

            foreach ($logs as $log) {
                $order = $orders->get($log->order_id);
                if ($order?->status !== 'pending') {
                    $stale[] = $log;
                } elseif ($log->expires_at && $log->expires_at->isPast()) {
                    $due[] = $log;
                } elseif ($active === null) {
                    $active = $log;
                } else {
                    // Một tài xế chỉ được giữ một offer. Nếu lịch sử lỗi để
                    // sót nhiều log hợp lệ, giữ offer mới nhất và thu hồi phần
                    // còn lại mà không quy lỗi cho tài xế.
                    $stale[] = $log;
                }
            }

            $now = now();
            foreach ($stale as $log) {
                $log->update([
                    'result' => 'expired',
                    'responded_at' => $now,
                    'close_reason' => OrderDispatchLog::CLOSE_STALE_RECOVERY,
                ]);
                $order = $orders->get($log->order_id);
                if ($order?->status === 'pending') {
                    $order->update([
                        'dispatching_to_driver_id' => OrderDispatchLog::where('order_id', $order->id)
                            ->where('result', 'pending')->value('driver_id'),
                    ]);
                }
            }

            return [
                'active_id' => $active?->id,
                'due' => collect($due)->map(fn ($log) => [(int) $log->order_id, (int) $log->driver_id])->all(),
                'stale_order_ids' => collect($stale)->pluck('order_id')->map(fn ($id) => (int) $id)->all(),
            ];
        });

        foreach ($state['stale_order_ids'] as $orderId) {
            RTDBService::clearDriverOffer($driverId, $orderId);
            Redis::eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                1,
                "dispatch:lock:driver:{$driverId}",
                (string) $orderId,
            );
            $staleOrder = Order::find($orderId);
            if ($staleOrder?->status === 'pending') {
                app(DispatchService::class)->sendToNextDriver($staleOrder);
            }
        }
        foreach ($state['due'] as [$orderId, $dueDriverId]) {
            $dueOrder = Order::find($orderId);
            if ($dueOrder?->status === 'pending') {
                app(DispatchService::class)->handleTimeout($dueOrder, $dueDriverId, true);
            }
        }

        $activeLog = $state['active_id']
            ? OrderDispatchLog::whereKey($state['active_id'])->where('result', 'pending')->first()
            : null;
        $order = $activeLog ? Order::with('sender')->find($activeLog->order_id) : null;
        if (! $activeLog || ! $order || $order->status !== 'pending'
            || ($activeLog->expires_at && $activeLog->expires_at->isPast())) {
            // Dọn node Firebase bị sót từ một log đã đóng trước lần sync này.
            // clearDriverOffer() chỉ xoá khi order_id vẫn khớp nên không thể
            // xóa nhầm một offer mới vừa được phát đồng thời.
            $lastOrderId = (int) OrderDispatchLog::where('driver_id', $driverId)
                ->latest('offered_at')->value('order_id');
            if ($lastOrderId > 0) {
                dispatch(fn () => RTDBService::clearDriverOffer($driverId, $lastOrderId))
                    ->afterResponse();
            }

            return response()->json(['success' => true, 'data' => ['offer' => null, 'order' => null]]);
        }

        $payload = DispatchOfferPayload::make($order, $activeLog);
        // API là nguồn đối chiếu, đồng thời ghi lại RTDB để listener realtime
        // tự phục hồi nếu node bị mất trong lúc mạng/Firebase chập chờn.
        RTDBService::writeDriverOffer($driverId, $payload);

        $stillActive = OrderDispatchLog::whereKey($activeLog->id)
            ->where('result', 'pending')
            ->whereHas('order', fn ($query) => $query->where('status', 'pending'))
            ->exists();
        if (! $stillActive) {
            RTDBService::clearDriverOffer($driverId, $order->id);

            return response()->json(['success' => true, 'data' => ['offer' => null, 'order' => null]]);
        }

        return response()->json(['success' => true, 'data' => ['offer' => $payload, 'order' => $payload]]);
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
            if (! $order || $order->status !== 'pending') {
                return null;
            }

            if ($log->viewed_at) {
                return [
                    'updated' => false,
                    'expires_at' => $log->expires_at,
                ];
            }

            $viewedAt = now();
            // Giai đoạn 1 cho thiết bị thời gian mở offer. Khi tài xế thật sự
            // xem, chuyển đúng một lần sang giai đoạn quyết định theo cấu hình.
            // Mỗi người vẫn chỉ có một quyền nhận; transaction accept quyết
            // định ai bấm trước trong wave.
            $expiresAt = $viewedAt->copy()->addSeconds(
                OperationalSettings::offerDecisionSeconds($order->city_id),
            );
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
                ->where('orders.status', 'pending'))
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
