<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?string $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 1;

    public function getHeading(): string
    {
        return '';
    }

    // Truyền số nguyên thì Filament chỉ cho lưới 12 cột từ màn hình lg trở lên
    // và 1 cột ở điện thoại, trong khi các widget đều khai báo columnSpan
    // 'default' => 12. Hai bên lệch nhau làm thẻ co còn ~40% chiều rộng. Đặt rõ
    // 12 cột cho mọi cỡ màn hình để columnSpan của widget khớp.
    public function getColumns(): int|string|array
    {
        return ['default' => 12];
    }

    public function mount(): void
    {
        if (auth()->user()?->isCallCenter()) {
            $this->redirect(CallCenterPage::getUrl());
        }
    }
}
