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

    public function getColumns(): int|string|array
    {
        return 12;
    }

    public function mount(): void
    {
        if (auth()->user()?->isCallCenter()) {
            $this->redirect(CallCenterPage::getUrl());
        }
    }
}
