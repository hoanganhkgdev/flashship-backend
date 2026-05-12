<?php
namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Models\DriverWalletTransaction;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Driver\Services\DriverWalletService;

class WalletAdminController extends Controller
{
    // ── Withdraw requests ─────────────────────────────────────────────────────

    public function withdrawRequests(Request $request): JsonResponse
    {
        $query = WithdrawRequest::with([
            'driver:id,name,phone',
            'processor:id,name',
        ])->latest();

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('driver_id')) $query->where('driver_id', $request->driver_id);

        $items = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'has_more'     => $items->hasMorePages(),
                'total'        => $items->total(),
            ],
        ]);
    }

    public function approveWithdraw(Request $request, int $id): JsonResponse
    {
        $withdraw = WithdrawRequest::findOrFail($id);

        if ($withdraw->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Yêu cầu này đã được xử lý.'], 409);
        }

        DB::transaction(function () use ($withdraw, $request) {
            DriverWalletService::adjust(
                $withdraw->driver_id,
                $withdraw->amount,
                'debit',
                'Rút tiền #' . $withdraw->id,
                'withdraw_' . $withdraw->id
            );

            $withdraw->update([
                'status'       => 'approved',
                'admin_note'   => $request->input('admin_note'),
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Đã duyệt yêu cầu rút tiền.',
            'data'    => $withdraw->fresh(['driver:id,name,phone', 'processor:id,name']),
        ]);
    }

    public function rejectWithdraw(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'admin_note' => 'required|string|max:500',
        ]);

        $withdraw = WithdrawRequest::findOrFail($id);

        if ($withdraw->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Yêu cầu này đã được xử lý.'], 409);
        }

        $withdraw->update([
            'status'       => 'rejected',
            'admin_note'   => $data['admin_note'],
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đã từ chối yêu cầu rút tiền.',
            'data'    => $withdraw->fresh(['driver:id,name,phone', 'processor:id,name']),
        ]);
    }

    // ── Driver wallet ─────────────────────────────────────────────────────────

    public function driverWallet(int $driverId): JsonResponse
    {
        $driver = User::where('user_type', 'driver')->with('wallet')->findOrFail($driverId);

        $wallet  = $driver->wallet;
        $balance = $wallet?->balance ?? 0;

        $stats = DB::table('driver_wallet_transactions')
            ->join('driver_wallets', 'driver_wallets.id', '=', 'driver_wallet_transactions.wallet_id')
            ->where('driver_wallets.driver_id', $driverId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END), 0) as total_credit,
                COALESCE(SUM(CASE WHEN type='debit'  THEN amount ELSE 0 END), 0) as total_debit,
                COUNT(*) as tx_count
            ")
            ->first();

        $pendingWithdraw = WithdrawRequest::where('driver_id', $driverId)
            ->where('status', 'pending')
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'driver'          => ['id' => $driver->id, 'name' => $driver->name, 'phone' => $driver->phone],
                'balance'         => (float) $balance,
                'total_credit'    => (float) ($stats->total_credit ?? 0),
                'total_debit'     => (float) ($stats->total_debit ?? 0),
                'tx_count'        => (int)   ($stats->tx_count ?? 0),
                'pending_withdraw'=> (float) $pendingWithdraw,
            ],
        ]);
    }

    public function driverTransactions(Request $request, int $driverId): JsonResponse
    {
        $wallet = DriverWallet::where('driver_id', $driverId)->first();

        if (!$wallet) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['current_page' => 1, 'has_more' => false, 'total' => 0],
            ]);
        }

        $query = $wallet->transactions()->latest();

        if ($request->filled('type')) $query->where('type', $request->type);

        $items = $query->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'has_more'     => $items->hasMorePages(),
                'total'        => $items->total(),
            ],
        ]);
    }

    public function adjustWallet(Request $request, int $driverId): JsonResponse
    {
        $data = $request->validate([
            'type'        => 'required|in:credit,debit',
            'amount'      => 'required|numeric|min:1000',
            'description' => 'required|string|max:255',
        ]);

        $driver = User::where('user_type', 'driver')->findOrFail($driverId);

        $ref = 'admin_adj_' . $driverId . '_' . now()->timestamp;

        try {
            $tx = DriverWalletService::adjust(
                $driverId,
                (float) $data['amount'],
                $data['type'],
                $data['description'] . ' (admin: ' . $request->user()->name . ')',
                $ref
            );
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $newBalance = DriverWallet::where('driver_id', $driverId)->value('balance');

        return response()->json([
            'success'     => true,
            'message'     => 'Đã ' . ($data['type'] === 'credit' ? 'cộng' : 'trừ') . ' ví thành công.',
            'data'        => [
                'transaction' => $tx,
                'new_balance' => (float) $newBalance,
            ],
        ]);
    }

    // ── Overview (all drivers) ────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        $query = DriverWallet::with('driver:id,name,phone,city_id,is_online')
            ->withCount(['transactions as tx_count'])
            ->orderByDesc('balance');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('driver', fn($q) =>
                $q->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")
            );
        }

        $items = $query->paginate(20);

        $totals = DB::table('driver_wallets')
            ->selectRaw('SUM(balance) as total_balance, COUNT(*) as wallet_count')
            ->first();

        $pendingCount = WithdrawRequest::where('status', 'pending')->count();
        $pendingAmount = WithdrawRequest::where('status', 'pending')->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page'   => $items->currentPage(),
                'has_more'       => $items->hasMorePages(),
                'total'          => $items->total(),
                'total_balance'  => (float) ($totals->total_balance ?? 0),
                'pending_count'  => (int)   $pendingCount,
                'pending_amount' => (float) $pendingAmount,
            ],
        ]);
    }
}
