<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getHeading(): string { return ''; }

    public function mount(): void
    {
        if (auth()->user()?->isCallCenter()) {
            $this->redirect(CallCenterPage::getUrl());
        }
    }
}
