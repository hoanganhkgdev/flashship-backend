<?php

namespace App\Filament\Traits;

use App\Support\AdminAccess;

trait HideFromCityManager
{
    public static function canViewAny(): bool
    {
        return AdminAccess::allows(auth()->user(), AdminAccess::SYSTEM_SETTINGS);
    }
}
