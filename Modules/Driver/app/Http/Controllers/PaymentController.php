<?php

namespace Modules\Driver\Http\Controllers;

use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Models\DriverDebt;
use Modules\Driver\Services\DriverWalletService;

class PaymentController extends Controller
{
    /**
     * Tạo payment QR — dùng cho nạp ví hoặc thanh toán công nợ.
     * POST /driver/payment/create
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'    => 'required|in:topup,debt_payment',
            'amount'  => 'required_if:type,topup|nullable|integer|min:10000|max:50000000',
            'debt_id' => 'required_if:type,debt_payment|nullable|integer',
        ]);

        $driver = $request->user();

        if ($data['type'] === 'debt_payment') {
            $debt = DriverDebt::where('driver_id', $driver->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->find($data['debt_id']);

            if (!$debt) {
                return response()->json(['success' => false, 'message' => 'Không tìm thấy công nợ'], 404);
            }

            $amount      = (int) ($debt->amount_due - $debt->amount_paid);
            $description = 'Phi tuan ' . date('dmy', strtotime($debt->week_start));
            $refId       = $debt->id;
        } else {
            $amount      = (int) $data['amount'];
            $description = 'Nap vi FlashShip';
            $refId       = null;
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
        $payosStatus = PayOSService::getPaymentStatus($orderCode);

        if ($payosStatus === 'PAID') {
            $this->handlePaid($order);
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

        if (!$order || $order->status !== 'pending') {
            return response()->json(['success' => true]);
        }

        if ($status === '00') {
            $this->handlePaid($order);
            Log::info("[PayOS] Webhook PAID orderCode={$code} driver={$order->driver_id}");
        } elseif ($status === 'CANCELLED') {
            DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    private function handlePaid(object $order): void
    {
        DB::transaction(function () use ($order) {
            DB::table('payment_orders')->where('id', $order->id)->update([
                'status'     => 'paid',
                'updated_at' => now(),
            ]);

            if ($order->type === 'topup') {
                DriverWalletService::adjust(
                    $order->driver_id,
                    $order->amount,
                    'credit',
                    'Nạp ví qua PayOS',
                    "payos_{$order->order_code}"
                );
            } elseif ($order->type === 'debt_payment' && $order->ref_id) {
                $debt = DriverDebt::find($order->ref_id);
                if ($debt && in_array($debt->status, ['pending', 'overdue'])) {
                    $newPaid = $debt->amount_paid + $order->amount;
                    $status  = $newPaid >= $debt->amount_due ? 'paid' : $debt->status;
                    $debt->update(['amount_paid' => $newPaid, 'status' => $status]);
                }
            }
        });
    }
}
