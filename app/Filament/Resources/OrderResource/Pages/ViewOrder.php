<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Pages\CallCenterPage;
use App\Filament\Resources\OrderResource;
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
            Actions\Action::make('reorder')
                ->label('Đặt lại đơn')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Order $record): bool => in_array($record->status, ['cancelled', 'completed']))
                ->url(fn (Order $record): string => CallCenterPage::getUrl().'?'.http_build_query(array_filter([
                    'reorder' => $record->id,
                    'service' => $record->service_type,
                    'city_id' => $record->city_id,
                    'pickup_address' => $record->pickup_address,
                    'pickup_phone' => $record->pickup_phone,
                    'delivery_address' => $record->delivery_address,
                    'delivery_phone' => $record->delivery_phone,
                ]))),
            Actions\EditAction::make()
                ->label('Chỉnh sửa đơn')
                ->icon('heroicon-o-pencil-square'),
        ];
    }
}
