<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Driver\Services\DriverWalletService;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = DriverWalletService::ensureWallet($user->id);
        $overdue = DriverWalletService::overdueDebt($user);

        return response()->json([
            'success' => true,
            'data'    => [
                'balance' => $wallet->balance,
                'driver_id' => $user->id,
                // Nợ quá hạn được giữ lại trong ví: chỉ phần vượt quá số này mới rút được.
                'overdue_debt' => $overdue,
                'withdrawable' => max(0, $wallet->balance - $overdue),
                // Giới hạn mỗi lần rút, để app kiểm tra trước khi gửi (khớp validate ở withdraw()).
                'min_withdraw' => \Modules\Core\Services\OperationalSettings::minWithdrawAmount($user->city_id),
                'max_withdraw' => DriverWalletService::MAX_WITHDRAW,
                // Tài khoản nhận tiền rút — app ví không phải gọi thêm /driver/profile chỉ để lấy phần này.
                'bank' => ($bank = \Modules\Driver\Models\Bank::where('user_id', $user->id)->first()) ? [
                    'bank_code' => $bank->bank_code,
                    'bank_name' => $bank->bank_name,
                    'account_number' => $bank->account_number,
                    'account_name' => $bank->account_name,
                ] : null,
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = DriverWalletService::ensureWallet($user->id);
        // Một đơn hoàn thành cộng vài khoản cùng giây: thêm id để thứ tự cố định, phân trang không lặp/sót dòng.
        $txs    = $wallet->transactions()->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $txs->items(),
            'meta'    => ['has_more' => $txs->hasMorePages(), 'total' => $txs->total()],
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $user   = $request->user();
        $min    = \Modules\Core\Services\OperationalSettings::minWithdrawAmount($user->city_id);
        $max    = DriverWalletService::MAX_WITHDRAW;
        $data   = $request->validate(['amount' => 'required|integer|min:'.$min.'|max:'.$max], [
            'amount.integer' => 'Số tiền rút phải là số nguyên (đồng).',
            'amount.min' => 'Số tiền rút tối thiểu là '.number_format($min, 0, ',', '.').'đ.',
            'amount.max' => 'Mỗi lần chỉ rút tối đa '.number_format($max, 0, ',', '.').'đ.',
        ]);

        if ((int) $user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đang tạm ngưng, không thể tạo yêu cầu rút tiền.',
            ], 403);
        }

        $wallet = DriverWalletService::ensureWallet($user->id);

        // Kiểm tra nhanh trước — không phải nguồn xác thực chính, chỉ để trả
        // lỗi sớm/rõ ràng cho trường hợp thường gặp (không phải race). Nguồn
        // xác thực thật là adjust() bên dưới — khoá đúng dòng ví trong
        // transaction rồi mới kiểm tra lại số dư thật.
        if ($wallet->balance < $data['amount']) {
            return response()->json(['success' => false, 'message' => 'Số dư không đủ'], 400);
        }

        try {
            DB::transaction(function () use ($user, $data) {
                // Khóa tài xế trước để cùng thứ tự với updateBank(); snapshot
                // không thể bị đổi giữa lúc kiểm tra và tạo yêu cầu.
                $locked = \Modules\Core\Models\User::whereKey($user->id)->lockForUpdate()->firstOrFail();

                // Nợ quá hạn còn phải thu thì giữ lại trong ví, chỉ rút phần vượt quá. Kiểm tra trong khoá
                // tài xế để hai lần rút đồng thời không cùng lọt qua.
                $overdue = DriverWalletService::overdueDebt($locked);
                if ($overdue > 0) {
                    $max = max(0, (float) DriverWallet::where('driver_id', $user->id)->value('balance') - $overdue);
                    if ($data['amount'] > $max) {
                        throw new \DomainException('debt_hold|'.(int) $overdue.'|'.(int) $max);
                    }
                }

                $bank = \Modules\Driver\Models\Bank::where('user_id', $user->id)
                    ->lockForUpdate()->first();
                if (!$bank || !$bank->bank_code || !$bank->account_number || !$bank->account_name) {
                    throw new \DomainException('bank_missing');
                }

                $req = WithdrawRequest::create([
                    'driver_id' => $user->id,
                    'amount'    => $data['amount'],
                    'bank_code' => $bank->bank_code,
                    'bank_name' => $bank->bank_name,
                    'account_number' => $bank->account_number,
                    'account_name' => $bank->account_name,
                    'status'    => 'pending',
                ]);

                DriverWalletService::adjust(
                    $user->id,
                    $data['amount'],
                    'debit',
                    'Yêu cầu rút tiền #' . $req->id,
                    'withdraw_hold_' . $req->id
                );
            });
        } catch (\DomainException $e) {
            if (str_starts_with($e->getMessage(), 'debt_hold|')) {
                [, $overdue, $max] = explode('|', $e->getMessage());

                return response()->json([
                    'success' => false,
                    'code' => 'debt_hold',
                    'message' => 'Bạn còn nợ quá hạn '.number_format((int) $overdue, 0, ',', '.').'đ nên chưa rút được hết. '
                        .((int) $max > 0 ? 'Hiện chỉ rút được tối đa '.number_format((int) $max, 0, ',', '.').'đ.' : 'Hãy thanh toán nợ trước khi rút tiền.'),
                ], 422);
            }

            return response()->json(['success' => false, 'message' => 'Vui lòng cập nhật đầy đủ tài khoản ngân hàng trước khi rút tiền'], 422);
        } catch (\Exception $e) {
            // Số dư thật (đọc có khoá trong adjust()) không đủ — vd 2 request
            // rút tiền gần như đồng thời, request thứ 2 chỉ phát hiện ra khi
            // đã khoá được dòng ví, sau khi request thứ 1 đã trừ tiền xong.
            if (str_starts_with($e->getMessage(), 'Số dư không đủ')) {
                return response()->json(['success' => false, 'code' => 'insufficient_balance', 'message' => 'Số dư không đủ'], 400);
            }
            // Lỗi khác (DB, hệ thống): không báo nhầm "số dư không đủ"; transaction đã rollback, chưa trừ tiền.
            report($e);

            return response()->json(['success' => false, 'message' => 'Hệ thống đang bận, chưa tạo được yêu cầu rút. Vui lòng thử lại.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Yêu cầu rút tiền đã được gửi']);
    }

    public function withdrawRequests(Request $request): JsonResponse
    {
        $requests = WithdrawRequest::where('driver_id', $request->user()->id)->latest()->latest('id')->paginate(20);

        // Chỉ những gì tài xế cần biết; không trả trường nội bộ (mã lệnh PayOS, người duyệt, lỗi chuyển khoản...).
        return response()->json([
            'success' => true,
            'data' => collect($requests->items())->map(fn (WithdrawRequest $r) => [
                'id' => $r->id,
                'amount' => (int) $r->amount,
                'status' => $r->status,
                // Đang chuyển qua PayOS: vẫn là pending nhưng tài xế nên biết tiền đang trên đường.
                'in_progress' => $r->status === 'pending' && \App\Services\WithdrawService::payosInFlight($r),
                'bank_name' => $r->bank_name,
                'account_number' => \App\Services\DriverBankService::mask($r->account_number),
                // admin_note = nhãn lý do + ghi chú của admin (reject_reason chỉ là mã).
                'reject_reason' => $r->status === 'rejected'
                    ? ($r->admin_note ?: (\App\Services\WithdrawService::REJECT_REASONS[$r->reject_reason] ?? null))
                    : null,
                'created_at' => $r->created_at?->toIso8601String(),
                'processed_at' => $r->processed_at?->toIso8601String(),
            ])->values(),
            'meta' => ['has_more' => $requests->hasMorePages()],
        ]);
    }
}
