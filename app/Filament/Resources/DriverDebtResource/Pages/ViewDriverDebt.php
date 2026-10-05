<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\DriverWalletResource;
use App\Services\DriverDebtService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverWallet;

class ViewDriverDebt extends ViewRecord
{
    protected static string $resource = DriverDebtResource::class;

    protected static string $view = 'filament.resources.driver-debt-resource.pages.view-driver-debt';

    public function getTitle(): string
    {
        return 'Công nợ #'.$this->record->id;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.($this->record->driver?->phone ?? '—');
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record->refresh();
        $wallet = DriverWallet::where('driver_id', $this->record->driver_id)->first();

        return [
            DriverDebtResource::walletAction(Actions\Action::make('pay_wallet'))->after($refresh),
            DriverDebtResource::manualAction(Actions\Action::make('pay_manual'))->after($refresh),
            DriverDebtResource::remindAction(Actions\Action::make('remind'))->after($refresh),
            Actions\ActionGroup::make([
                DriverDebtResource::waiveAction(Actions\Action::make('waive'))->after($refresh),
                DriverDebtResource::adjustAction(Actions\Action::make('adjust'))->after($refresh),
                DriverDebtResource::overdueAction(Actions\Action::make('mark_overdue'))->after($refresh),
                Actions\Action::make('wallet')->label('Ví tài xế')->icon('heroicon-o-wallet')
                    ->visible((bool) $wallet)->url($wallet ? DriverWalletResource::getUrl('view', ['record' => $wallet]) : null),
                Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')
                    ->url(DriverResource::getUrl('view', ['record' => $this->record->driver_id])),
            ])->label('Khác')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    /** @return Collection<int, array{label:string,amount:float,who:string,note:?string,when:\Carbon\Carbon}> */
    public function getTimeline(): Collection
    {
        $logs = $this->record->logs()->get();
        $names = User::whereIn('id', $logs->pluck('performed_by')->filter()->unique())->pluck('name', 'id');

        return $logs->map(fn ($l) => [
            'label' => DriverDebtService::ACTIONS[$l->action] ?? $l->action,
            'action' => $l->action,
            'amount' => $l->amount,
            'who' => $l->performed_by ? ($names[$l->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
            'note' => $l->note,
            'when' => $l->created_at,
        ]);
    }
}
