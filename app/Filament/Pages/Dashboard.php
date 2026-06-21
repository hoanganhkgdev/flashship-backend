<?php

namespace App\Filament\Pages;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function getHeading(): string { return ''; }

    public static function canAccess(): bool
    {
        return !auth()->user()?->isCallCenter();
    }
}
