<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;

class ViewDriverRating extends ViewRecord
{
    protected static string $resource = DriverRatingResource::class;

    protected static string $view = 'filament.resources.driver-rating-resource.pages.view-driver-rating';

    public function getTitle(): string
    {
        return 'Đánh giá đơn #'.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Đơn chưa gán tài xế').' · '.$this->record->driver_rating.'/5 sao';
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record->refresh();

        return [
            DriverRatingResource::handleAction(Actions\Action::make('handle'))->after($refresh),
            DriverRatingResource::hideAction(Actions\Action::make('hide'))->after($refresh),
            DriverRatingResource::unhideAction(Actions\Action::make('unhide'))->after($refresh),
            Actions\Action::make('order')->label('Xem đơn')->icon('heroicon-o-clipboard-document-list')->color('gray')
                ->url(OrderResource::getUrl('view', ['record' => $this->record])),
        ];
    }

    /** Các đánh giá khác của cùng tài xế (không tính đánh giá đang xem). */
    public function getOtherRatings(): Collection
    {
        if (! $this->record->delivery_man_id) {
            return collect();
        }

        return Order::query()->where('delivery_man_id', $this->record->delivery_man_id)
            ->whereNotNull('driver_rating')->where('rating_hidden', false)->whereKeyNot($this->record->id)
            ->latest('rated_at')->limit(6)->get(['id', 'code', 'driver_rating', 'driver_rating_note', 'rated_at']);
    }

    public function getHandler(): ?User
    {
        return $this->record->rating_handled_by ? User::find($this->record->rating_handled_by) : null;
    }

    public function driverUrl(): ?string
    {
        return $this->record->delivery_man_id ? DriverResource::getUrl('view', ['record' => $this->record->delivery_man_id]) : null;
    }
}
