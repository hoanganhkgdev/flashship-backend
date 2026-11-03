<?php

namespace Modules\Driver\Http\Controllers;

use App\Services\DriverDebtService;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Services\DriverWalletService;

class PaymentController extends Controller
{
    /**
     * Tạo payment QR trả công nợ qua PayOS.
     * POST /driver/payment/create
     */
    public function create(Request $request): JsonResponse
    {
        // Chỉ còn trả công nợ qua QR — tài xế không nạp tiền vào ví, ví chỉ
        // tăng từ thu nhập/thưởng và giảm khi rút/trừ nợ.
        $data = $request->validate([
            'type'    => 'required|in:debt_payment',
            'debt_id' => 'required|integer',
        ]);

        $driver = $request->user();

        $createLock = Cache::lock(
            "payment:create:debt:{$driver->id}:{$data['debt_id']}",
            60
        );
        if (!$createLock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'Khoản thanh toán đang được tạo QR, vui lòng chờ vài giây.',
            ], 409);
        }

        try {

            // Đọc lại SAU khi có khóa; request trước có thể vừa thanh toán
            // xong hoặc vừa tạo QR mới trong lúc request này chờ.
            $debt = DriverDebt::where('driver_id', $driver->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->find($data['debt_id']);

            if (!$debt) {
                return response()->json(['success' => false, 'message' => 'Không tìm thấy công nợ'], 404);
            }

            $amount      = (int) round((float) $debt->amount_due - (float) $debt->amount_paid);
            if ($amount <= 0) {
                return response()->json(['success' => false, 'message' => 'Khoản công nợ đã được thanh toán'], 409);
            }
            $description = 'Thanh toan cong no ' . date('dmy', strtotime($debt->week_start ?? $debt->date ?? now()));
            $refId       = $debt->id;

        // Huỷ các QR cũ còn "pending" cho cùng loại/khoản — không thì QR cũ
        // (ảnh chụp màn hình, tab cũ...) vẫn quét trả tiền được nhưng tiền
        // không được cộng vào đâu vì hệ thống chỉ áp dụng cho QR mới nhất.
            $oldPending = DB::table('payment_orders')
            ->where('driver_id', $driver->id)
            ->where('type', $data['type'])
            ->where('status', 'pending')
            ->when($refId !== null, fn ($q) => $q->where('ref_id', $refId))
            ->get(['id', 'order_code']);
            foreach ($oldPending as $old) {
                PayOSService::cancelPayment((int) $old->order_code);
                DB::table('payment_orders')->where('id', $old->id)
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

        // Tạo order code duy nhất (9 chữ số đầu timestamp + 3 chữ số random)
            $orderCode = (int) (substr((string) time(), -7) . str_pad(random_int(0, 999), 3, '0', STR_PAD_LEFT));

            $result = PayOSService::createPayment($orderCode, $amount, $description, $data['type']);

            if (!$result) {
                return response()->json(['success' => false, 'message' => 'Không tạo được link thanh toán'], 500);
            }

            DB::table('payment_orders')->insert([
            'driver_id'       => $driver->id,
            'type'            => $data['type'],
            'ref_id'          => $refId,
            'order_code'      => $orderCode,
            'amount'          => $amount,
            'status'          => 'pending',
            'payment_link_id' => $result['payment_link_id'],
            'checkout_url'    => $result['checkout_url'],
            'qr_code'         => $result['qr_code'],
            'created_at'      => now(),
            'updated_at'      => now(),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'order_code'   => $orderCode,
                    'amount'       => $amount,
                    'qr_code'      => $result['qr_code'],
                    'checkout_url' => $result['checkout_url'],
                ],
            ]);
        } finally {
            $createLock->release();
        }
    }

    /**
     * Kiểm tra trạng thái payment — app poll mỗi 3 giây.
     * GET /driver/payment/{orderCode}/status
     */
    public function status(Request $request, int $orderCode): JsonResponse
    {
        $order = DB::table('payment_orders')
            ->where('order_code', $orderCode)
            ->where('driver_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn thanh toán'], 404);
        }

        // Đã xử lý rồi thì trả về luôn
        if ($order->status !== 'pending') {
            return response()->json(['success' => true, 'data' => ['status' => strtoupper($order->status)]]);
        }

        // Query PayOS
        $info = PayOSService::getPaymentInfo($orderCode);
        $payosStatus = $info['status'] ?? null;

        if ($payosStatus === 'PAID') {
            $this->handlePaid($order, $info['amount_paid'] ?? null);
        } elseif ($payosStatus === 'CANCELLED') {
            DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        return response()->json(['success' => true, 'data' => ['status' => $payosStatus ?? 'PENDING']]);
    }

    /**
     * Webhook từ PayOS — endpoint công khai (không cần auth).
     * POST /payment/webhook/payos
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (!PayOSService::verifyWebhook($payload)) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $code   = $payload['data']['orderCode'] ?? null;
        $status = strtoupper($payload['data']['code'] ?? '');

        if (!$code) {
            return response()->json(['success' => true]);
        }

        $order = DB::table('payment_orders')->where('order_code', $code)->first();

        if (!$order || $order->status === 'paid') {
            return response()->json(['success' => true]);
        }

        // PayOS chỉ gọi webhook khi đã nhận tiền (code '00'); hủy link không có webhook, app poll tự thấy CANCELLED.
        if ($status === '00') {
            $paid = isset($payload['data']['amount']) ? (int) $payload['data']['amount'] : null;
            $this->handlePaid($order, $paid);
            Log::info("[PayOS] Webhook PAID orderCode={$code} driver={$order->driver_id} amount=".($paid ?? '?'));
        }

        return response()->json(['success' => true]);
    }

    /** $paidAmount: số tiền PayOS báo đã nhận; null (không có thông tin) thì lấy số trên QR. */
    private function handlePaid(object $order, ?int $paidAmount = null): void
    {
        // Ghi đúng số tiền thật nhận: chuyển thiếu thì phần còn lại vẫn là nợ, chuyển dư thì dư vào ví.
        $received = $paidAmount !== null && $paidAmount > 0 ? $paidAmount : (int) $order->amount;
        if ($received !== (int) $order->amount) {
            Log::warning('[PayOS] Số tiền nhận khác QR', ['order_code' => $order->order_code, 'qr' => (int) $order->amount, 'received' => $received]);
        }

        DB::transaction(function () use ($order, $received) {
            // PAID có chữ ký hợp lệ phải được ghi nhận kể cả QR vừa bị app
            // thay thế và đánh dấu cancelled trong lúc ngân hàng đã trừ tiền.
            // Compare-and-swap đảm bảo poll/webhook/retry chỉ xử lý một lần.
            // chỉ đúng 1 trong các lệnh gọi đồng thời (app poll vs webhook, hoặc
            // webhook tự retry) có $updated > 0 — bên thua cuộc thấy 0 dòng bị
            // ảnh hưởng và tự return sớm, không cộng tiền/công nợ lần 2.
            $updated = DB::table('payment_orders')
                ->where('id', $order->id)
                ->whereIn('status', ['pending', 'cancelled'])
                ->lockForUpdate()
                ->update(['status' => 'paid', 'updated_at' => now()]);

            if ($updated === 0) {
                // Đã có request khác (poll hoặc webhook retry) xử lý trước rồi — dừng lại, không cộng tiền/công nợ lần 2.
                return;
            }

            // Giữ nhánh này cho các payment_orders type=topup còn "pending" từ
            // trước khi bỏ tính năng nạp ví — webhook PayOS có thể đến trễ.
            if ($order->type === 'topup') {
                DriverWalletService::adjust(
                    $order->driver_id,
                    $received,
                    'credit',
                    'Nạp ví qua PayOS',
                    "payos_{$order->order_code}"
                );
            } elseif ($order->type === 'debt_payment' && $order->ref_id) {
                $debt = DriverDebt::where('id', $order->ref_id)->lockForUpdate()->first();
                if ($debt) {
                    $remaining = DriverDebtService::remaining($debt);
                    $applied = min($remaining, (float) $received);
                    $excess = (float) $received - $applied;

                    if ($applied > 0) {
                        DriverDebtService::applyPayment($debt, $applied, 'payos');
                        $note = 'Đơn QR '.$order->order_code.($received !== (int) $order->amount ? ' (QR '.number_format((int) $order->amount, 0, ',', '.').'₫, nhận '.number_format($received, 0, ',', '.').'₫)' : '');
                        DriverDebtService::log($debt->id, 'paid_payos', $applied, null, $note);
                    }

                    if ($excess > 0) {
                        DriverWalletService::adjust(
                            $order->driver_id,
                            $excess,
                            'credit',
                            'Hoàn tiền thanh toán công nợ dư',
                            "payos_debt_excess_{$order->order_code}"
                        );
                    }
                } else {
                    // Khoản nợ bị admin xóa sau lúc tạo QR nhưng tiền đã thu:
                    // không được nuốt tiền, hoàn toàn bộ vào ví tài xế.
                    DriverWalletService::adjust(
                        $order->driver_id,
                        $received,
                        'credit',
                        'Hoàn tiền QR công nợ không còn tồn tại',
                        "payos_debt_excess_{$order->order_code}"
                    );
                }
            }
        });
    }
}
