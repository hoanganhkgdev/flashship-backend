<?php

namespace App\Filament\Resources\AdminUserResource\Pages;

use App\Filament\Resources\AdminUserResource;
use App\Services\AdminAccountService;
use App\Services\CatalogService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    private const TRACKED = ['name' => 'Họ tên', 'email' => 'Email', 'phone' => 'Số điện thoại', 'user_type' => 'Vai trò', 'city_id' => 'Khu vực'];

    private array $before = [];

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Thay đổi vai trò có hiệu lực ngay với phạm vi truy cập. Mọi thay đổi được ghi nhật ký.';
    }

    protected function getHeaderActions(): array
    {
        return [
            AdminUserResource::lockAction(\Filament\Actions\Action::make('lock'))->after(fn () => $this->record->refresh()),
            AdminUserResource::passwordAction(\Filament\Actions\Action::make('password')),
            AdminUserResource::deleteAction(\Filament\Actions\Action::make('remove'))->after(fn () => $this->redirect(AdminUserResource::getUrl('index'))),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->before = $this->record->only(array_keys(self::TRACKED));

        // Hạ quyền: không tự hạ quyền mình, không hạ quản trị viên đầy đủ cuối cùng.
        if (($data['user_type'] ?? null) !== $this->record->user_type && $this->record->user_type === 'admin'
            && ($why = AdminAccountService::blocker($this->record, auth()->user(), 'demote'))) {
            Notification::make()->danger()->title('Không đổi được vai trò')->body($why)->send();
            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $r = $this->record->refresh();
        CatalogService::logChanges('admin_user', $r->id, $this->before, $r->only(array_keys(self::TRACKED)), self::TRACKED, auth()->id());
        if (filled($this->data['password'] ?? null)) {
            AdminAccountService::log($r, 'password_reset', auth()->id(), 'Đổi mật khẩu trong form sửa');
        }
    }
}
