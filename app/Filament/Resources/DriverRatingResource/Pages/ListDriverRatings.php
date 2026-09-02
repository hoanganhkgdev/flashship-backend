<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

class ListDriverRatings extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = DriverRatingResource::class;

    public function getHeading(): string
    {
        return 'Đánh giá tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi phản hồi sau chuyến và ưu tiên xử lý các đánh giá từ 1–2 sao.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
