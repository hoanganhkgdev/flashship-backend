<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Services\CatalogService;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    public function getTitle(): string
    {
        return 'Thêm khu vực';
    }

    public function getSubheading(): ?string
    {
        return 'Thiết lập khu vực phục vụ mới. Khu vực mới cần có giá, ca làm việc và tọa độ trước khi mở cho khách.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = CatalogService::normalizeSlug((string) ($data['slug'] ?? ''), (string) $data['name']);

        return $data;
    }

    protected function afterCreate(): void
    {
        CatalogService::log('city', $this->record->id, 'created', auth()->id(), $this->record->name);
    }
}
