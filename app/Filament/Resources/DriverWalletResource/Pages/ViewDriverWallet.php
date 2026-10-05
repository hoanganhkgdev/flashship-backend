<?php

namespace App\Filament\Resources\DriverWalletResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\DriverWalletResource;
use App\Filament\Resources\WithdrawRequestResource;
use App\Services\DriverWalletReport;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewDriverWallet extends ViewRecord
{
    protected static string $resource = DriverWalletResource::class;

    protected static string $view = 'filament.resources.driver-wallet-resource.pages.view-driver-wallet';

    protected function getHeaderActions(): array
    {
        return [
            DriverWalletResource::adjustAction(Actions\Action::make('adjust'))->after(fn () => $this->record->refresh()),
            Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')->color('gray')
                ->url(DriverResource::getUrl('view', ['record' => $this->record->driver_id])),
        ];
    }

    public function getTitle(): string
    {
        return 'Ví: '.($this->record->driver?->name ?? 'Tài xế #'.$this->record->driver_id);
    }

    public function getSubheading(): ?string
    {
        $d = $this->record->driver;

        return ($d?->phone ?? '—').' · '.($d?->city?->name ?? 'Chưa có khu vực');
    }

    /** Tóm tắt 30 ngày + công nợ/rút tiền đang mở. */
    public function getOverview(): array
    {
        $w = $this->record;
        $since = now()->subDays(30);
        $txn = DB::table('driver_wallet_transactions')->where('wallet_id', $w->id)->where('created_at', '>=', $since);

        $flow = (clone $txn)->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount END),0) cin, COALESCE(SUM(CASE WHEN type='debit' THEN amount END),0) cout")->first();
        $byCat = (clone $txn)->selectRaw(DriverWalletReport::categorySql().' k, type, SUM(amount) s')->groupBy('k', 'type')->get();
        $labels = DriverWalletReport::categoryLabels();

        $calc = (float) DB::table('driver_wallet_transactions')->where('wallet_id', $w->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE -amount END),0) c")->value('c');

        return [
            'balance' => (float) $w->balance,
            'status' => (int) $w->driver?->status,
            'low' => (int) $w->driver?->status === 1 && $w->balance < DriverWalletResource::lowThreshold(),
            'in' => (int) $flow->cin,
            'out' => (int) $flow->cout,
            'recon_ok' => abs($w->balance - $calc) <= 0.5,
            'categories' => $byCat->groupBy('k')->map(fn ($g, $k) => [
                'label' => $labels[$k] ?? 'Khác',
                'in' => (int) $g->where('type', 'credit')->sum('s'),
                'out' => (int) $g->where('type', 'debit')->sum('s'),
            ])->sortByDesc(fn ($c) => $c['in'] + $c['out'])->values(),
            'pendingWithdraw' => (float) DB::table('withdraw_requests')->where('driver_id', $w->driver_id)->where('status', 'pending')->sum('amount'),
            'debt' => (float) DB::table('driver_debts')->where('driver_id', $w->driver_id)->where('status', '<>', 'paid')
                ->selectRaw('COALESCE(SUM(GREATEST(amount_due - amount_paid, 0)),0) d')->value('d'),
            'withdrawUrl' => WithdrawRequestResource::getUrl('index'),
            'debtUrl' => DriverDebtResource::getUrl('index'),
        ];
    }
}
