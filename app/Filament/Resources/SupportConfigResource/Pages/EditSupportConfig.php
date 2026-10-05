<?php

namespace App\Filament\Resources\SupportConfigResource\Pages;

use App\Filament\Resources\SupportConfigResource;
use App\Services\CatalogService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportConfig extends EditRecord
{
    protected static string $resource = SupportConfigResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->title;
    }

    private const TRACKED = ['title' => 'Tiêu đề', 'subtitle' => 'Phụ đề', 'type' => 'Loại', 'value' => 'Giá trị', 'audience' => 'Đối tượng', 'city_id' => 'Khu vực', 'is_active' => 'Hiển thị'];

    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá kênh hỗ trợ')
                ->after(fn () => CatalogService::log('support_config', $this->record->id, 'deleted', auth()->id(), $this->record->title.' ('.$this->record->value.')')),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->before = $this->record->only(array_keys(self::TRACKED));

        return $data;
    }

    protected function afterSave(): void
    {
        CatalogService::logChanges('support_config', $this->record->id, $this->before, $this->record->refresh()->only(array_keys(self::TRACKED)), self::TRACKED, auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
