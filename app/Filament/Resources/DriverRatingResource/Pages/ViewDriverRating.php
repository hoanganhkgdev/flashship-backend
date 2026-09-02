<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDriverRating extends ViewRecord
{
    protected static string $resource = DriverRatingResource::class;

    public function getTitle(): string
    {
        return 'Đánh giá đơn #'.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.$this->record->driver_rating.'/5 sao';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
