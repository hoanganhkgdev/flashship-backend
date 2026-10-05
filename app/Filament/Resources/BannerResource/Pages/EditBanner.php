<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditBanner extends EditRecord
{
    protected static string $resource = BannerResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa banner';
    }

    public function getSubheading(): ?string
    {
        return $this->record->title;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['scope'] = $data['city_id'] ? 'city' : 'all';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['city_id'] = ($data['scope'] ?? 'city') === 'all' ? null : ($this->record->city_id ?? Filament::getTenant()?->id);
        unset($data['scope']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá banner'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
