<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Services\CatalogService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    private const TRACKED = ['name' => 'Tên', 'slug' => 'Slug', 'weekly_fee' => 'Phí tuần', 'lat' => 'Vĩ độ', 'lng' => 'Kinh độ', 'is_active' => 'Hoạt động', 'is_test' => 'Khu vực thử'];

    private array $before = [];

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật trạng thái, phí tuần và tọa độ khu vực. Mọi thay đổi được ghi nhật ký.';
    }

    protected function getHeaderActions(): array
    {
        return [CityResource::deleteAction(DeleteAction::make())];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->before = $this->record->only(array_keys(self::TRACKED));
        $data['slug'] = CatalogService::normalizeSlug((string) ($data['slug'] ?? ''), (string) $data['name']);

        return $data;
    }

    protected function afterSave(): void
    {
        $after = $this->record->refresh()->only(array_keys(self::TRACKED));
        // lat/lng lưu dạng decimal: so sánh theo số để "10.0125000" và 10.0125 không bị coi là đổi.
        foreach (['lat', 'lng'] as $k) {
            $this->before[$k] = $this->before[$k] === null ? null : (float) $this->before[$k];
            $after[$k] = $after[$k] === null ? null : (float) $after[$k];
        }
        CatalogService::logChanges('city', $this->record->id, $this->before, $after, self::TRACKED, auth()->id());
    }
}
