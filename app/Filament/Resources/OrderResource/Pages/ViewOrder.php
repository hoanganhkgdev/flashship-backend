<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Support\AdminAccess;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Modules\Order\Models\Order;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Chi tiết đơn #'.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return 'Tạo lúc '.$this->record->created_at?->format('H:i · d/m/Y').' · '.($this->record->city?->name ?? 'Chưa xác định khu vực');
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::assignAction(Actions\Action::make('assignDriver'))->after(fn () => $this->record->refresh()),
            Actions\Action::make('reorder')
                ->label('Đặt lại đơn')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Order $record): bool => AdminAccess::allows(auth()->user(), AdminAccess::ORDERS_MANAGE) && in_array($record->status, ['cancelled', 'completed']))
                ->url(fn (Order $record): string => OrderResource::reorderUrl($record)),
            OrderResource::completeAction(Actions\Action::make('complete'))->after(fn () => $this->record->refresh()),
            OrderResource::cancelAction(Actions\Action::make('cancel'))->after(fn () => $this->record->refresh()),
            Actions\EditAction::make()
                ->label('Chỉnh sửa đơn')
                ->icon('heroicon-o-pencil-square'),
        ];
    }
}
