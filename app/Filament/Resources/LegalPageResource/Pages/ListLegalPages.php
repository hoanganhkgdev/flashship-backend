<?php

namespace App\Filament\Resources\LegalPageResource\Pages;

use App\Filament\Resources\LegalPageResource;
use Filament\Resources\Pages\ListRecords;

class ListLegalPages extends ListRecords
{
    protected static string $resource = LegalPageResource::class;

    public function getHeading(): string
    {
        return 'Nội dung pháp lý';
    }

    public function getSubheading(): ?string
    {
        return 'Quản lý điều khoản, chính sách quyền riêng tư và các trang thông tin chung.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
