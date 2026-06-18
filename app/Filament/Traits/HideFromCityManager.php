<?php

namespace App\Filament\Traits;

trait HideFromCityManager
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->user_type !== 'city_manager';
    }
}
