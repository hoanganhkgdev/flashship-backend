<?php
namespace Modules\Driver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Models\WithdrawRequest;

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

        $wallet = DriverWallet::firstOrCreate(['driver_id' => $request->user()->id]);

        if ($wallet->balance < $data['amount']) {
            return response()->json(['success' => false, 'message' => 'Số dư không đủ'], 400);
        }

        WithdrawRequest::create([
            'driver_id' => $request->user()->id,
            'amount'    => $data['amount'],
            'status'    => 'pending',
        ]);

        return response()->json(['success' => true, 'message' => 'Yêu cầu rút tiền đã được gửi']);
    }

    public function withdrawRequests(Request $request): JsonResponse
    {
        $requests = WithdrawRequest::where('driver_id', $request->user()->id)->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $requests->items(), 'meta' => ['has_more' => $requests->hasMorePages()]]);
    }
}
