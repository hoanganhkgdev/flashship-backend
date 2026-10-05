<?php

namespace App\Filament\Resources\WithdrawRequestResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\DriverWalletResource;
use App\Filament\Resources\WithdrawRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Modules\Driver\Models\DriverWallet;
use Modules\Driver\Models\WithdrawRequest;

class ViewWithdrawRequest extends ViewRecord
{
    protected static string $resource = WithdrawRequestResource::class;

    protected static string $view = 'filament.resources.withdraw-request-resource.pages.view-withdraw-request';

    public function getTitle(): string
    {
        return 'Yêu cầu rút #'.$this->record->id;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.number_format($this->record->amount, 0, ',', '.').'₫';
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record->refresh();
        $wallet = DriverWallet::where('driver_id', $this->record->driver_id)->first();

        return [
            WithdrawRequestResource::approveAction(Actions\Action::make('approve'))->after($refresh),
            WithdrawRequestResource::rejectAction(Actions\Action::make('reject'))->after($refresh),
            Actions\Action::make('wallet')->label('Ví tài xế')->icon('heroicon-o-wallet')->color('gray')
                ->visible((bool) $wallet)->url($wallet ? DriverWalletResource::getUrl('view', ['record' => $wallet]) : null),
            Actions\Action::make('driver')->label('Hồ sơ tài xế')->icon('heroicon-o-user')->color('gray')
                ->url(DriverResource::getUrl('view', ['record' => $this->record->driver_id])),
        ];
    }

    /** Các yêu cầu rút trước đó của cùng tài xế. */
    public function getHistory(): Collection
    {
        return WithdrawRequest::where('driver_id', $this->record->driver_id)->whereKeyNot($this->record->id)->latest()->limit(8)->get();
    }
}
