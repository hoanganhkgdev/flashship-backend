<?php

namespace App\Filament\Resources\LegalPageResource\Pages;

use App\Filament\Resources\LegalPageResource;
use Filament\Resources\Pages\EditRecord;

class EditLegalPage extends EditRecord
{
    protected static string $resource = LegalPageResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->title;
    }

    public function getSubheading(): ?string
    {
        return 'Nội dung thay đổi sẽ được dùng chung trên các ứng dụng.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
