<?php

namespace App\Filament\Resources\LegalPageResource\Pages;

use App\Filament\Resources\LegalPageResource;
use App\Services\LegalPageService;
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
        return 'Nội dung được dùng chung trên các app và trang web. Mỗi lần lưu, bản cũ được giữ trong lịch sử để khôi phục.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Lưu bản đang có trước khi ghi đè (chỉ khi tiêu đề hoặc nội dung thật sự đổi). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['content'] ?? null) !== $this->record->content || ($data['title'] ?? null) !== $this->record->title) {
            LegalPageService::snapshot($this->record, auth()->id());
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
