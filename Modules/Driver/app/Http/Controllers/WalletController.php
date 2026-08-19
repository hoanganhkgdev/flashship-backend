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
        $wallet = DriverWallet::firstOrCreate(['driver_id' => $user->id]);

        return response()->json([
            'success' => true,
            'data'    => ['balance' => $wallet->balance, 'driver_id' => $user->id],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = DriverWallet::firstOrCreate(['driver_id' => $user->id]);
        $txs    = $wallet->transactions()->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $txs->items(),
            'meta'    => ['has_more' => $txs->hasMorePages(), 'total' => $txs->total()],
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:50000']);

        $user   = $request->user();
        $wallet = DriverWallet::firstOrCreate(['driver_id' => $user->id]);

        // Kiểm tra nhanh trước — không phải nguồn xác thực chính, chỉ để trả
        // lỗi sớm/rõ ràng cho trường hợp thường gặp (không phải race). Nguồn
        // xác thực thật là adjust() bên dưới — khoá đúng dòng ví trong
        // transaction rồi mới kiểm tra lại số dư thật.
        if ($wallet->balance < $data['amount']) {
            return response()->json(['success' => false, 'message' => 'Số dư không đủ'], 400);
        }

        try {
            DB::transaction(function () use ($user, $data) {
                $req = WithdrawRequest::create([
                    'driver_id' => $user->id,
                    'amount'    => $data['amount'],
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
        } catch (\Exception $e) {
            // Số dư thật (đọc có khoá trong adjust()) không đủ — vd 2 request
            // rút tiền gần như đồng thời, request thứ 2 chỉ phát hiện ra khi
            // đã khoá được dòng ví, sau khi request thứ 1 đã trừ tiền xong.
            return response()->json(['success' => false, 'message' => 'Số dư không đủ'], 400);
        }

        return response()->json(['success' => true, 'message' => 'Yêu cầu rút tiền đã được gửi']);
    }

    public function withdrawRequests(Request $request): JsonResponse
    {
        $requests = WithdrawRequest::where('driver_id', $request->user()->id)->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $requests->items(), 'meta' => ['has_more' => $requests->hasMorePages()]]);
    }
}
